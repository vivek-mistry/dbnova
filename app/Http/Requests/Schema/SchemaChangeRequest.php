<?php

namespace App\Http\Requests\Schema;

use App\Actions\Fortify\AuthenticateMySQLUser;
use App\Database\DTOs\Schema\AddColumn;
use App\Database\DTOs\Schema\AddForeignKey;
use App\Database\DTOs\Schema\AddIndex;
use App\Database\DTOs\Schema\ColumnDefault;
use App\Database\DTOs\Schema\ColumnDefaultKind;
use App\Database\DTOs\Schema\ColumnDefinition;
use App\Database\DTOs\Schema\CopyTable;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DropColumn;
use App\Database\DTOs\Schema\DropForeignKey;
use App\Database\DTOs\Schema\DropIndex;
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\ForeignKeyAction;
use App\Database\DTOs\Schema\ForeignKeyDefinition;
use App\Database\DTOs\Schema\IndexDefinition;
use App\Database\DTOs\Schema\IndexType;
use App\Database\DTOs\Schema\ModifyColumn;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\Schema\SchemaChange;
use App\Database\DTOs\Schema\TruncateTable;
use App\Models\User;
use App\Rules\TableName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * A schema change sent as JSON: {"table": "...", "change": {"type": "...", ...}}.
 * The DDL builder does the detailed checks (types, lengths, defaults); this
 * request checks the shape and, for destructive changes, the typed table name
 * and MySQL password.
 */
class SchemaChangeRequest extends FormRequest
{
    /** @var list<string> */
    public const array TYPES = [
        'add_column', 'modify_column', 'drop_column', 'add_index', 'drop_index', 'add_foreign_key', 'drop_foreign_key',
        'rename_table', 'copy_table', 'truncate_table', 'drop_table', 'create_table',
    ];

    /** @var list<string> */
    private const array DESTRUCTIVE_TYPES = ['drop_column', 'truncate_table', 'drop_table'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('change.type');
        $column = ['required', 'array'];
        $columnRules = fn (string $prefix): array => [
            "{$prefix}.name" => ['required', new TableName],
            "{$prefix}.type" => ['required', 'string', 'max:32'],
            "{$prefix}.length" => ['nullable', 'string', 'max:16'],
            "{$prefix}.values" => ['nullable', 'array', 'max:1000'],
            "{$prefix}.values.*" => ['string', 'max:255'],
            "{$prefix}.unsigned" => ['boolean'],
            "{$prefix}.nullable" => ['boolean'],
            "{$prefix}.default.kind" => ['nullable', Rule::enum(ColumnDefaultKind::class)],
            "{$prefix}.default.value" => ['nullable', 'string', 'max:1000'],
            "{$prefix}.autoIncrement" => ['boolean'],
            "{$prefix}.onUpdateCurrentTimestamp" => ['boolean'],
            "{$prefix}.collation" => ['nullable', 'string', 'max:64'],
            "{$prefix}.comment" => ['nullable', 'string', 'max:1024'],
        ];

        return [
            'table' => ['required', new TableName],
            'change' => ['required', 'array'],
            'change.type' => ['required', Rule::in(self::TYPES)],
            'allowLocking' => ['boolean'],
            ...match ($type) {
                'add_column' => ['change.column' => $column, ...$columnRules('change.column'), 'change.after' => ['nullable', 'string', 'max:64'], 'change.first' => ['boolean']],
                'modify_column' => ['change.from' => ['required', 'string', 'max:64'], 'change.column' => $column, ...$columnRules('change.column'), 'change.after' => ['nullable', 'string', 'max:64'], 'change.first' => ['boolean']],
                'drop_column' => ['change.column' => ['required', 'string', 'max:64']],
                'add_index' => [
                    'change.indexType' => ['required', Rule::enum(IndexType::class)],
                    'change.name' => ['nullable', new TableName],
                    'change.columns' => ['required', 'array', 'min:1', 'max:16'],
                    'change.columns.*' => ['string', 'max:64'],
                ],
                'drop_index', 'drop_foreign_key' => ['change.name' => ['required', 'string', 'max:64']],
                'add_foreign_key' => [
                    'change.name' => ['nullable', new TableName],
                    'change.columns' => ['required', 'array', 'min:1', 'max:16'],
                    'change.columns.*' => ['string', 'max:64'],
                    'change.referencedTable' => ['required', new TableName],
                    'change.referencedColumns' => ['required', 'array', 'min:1', 'max:16'],
                    'change.referencedColumns.*' => ['string', 'max:64'],
                    'change.onDelete' => ['required', Rule::enum(ForeignKeyAction::class)],
                    'change.onUpdate' => ['required', Rule::enum(ForeignKeyAction::class)],
                ],
                'rename_table' => ['change.newName' => ['required', new TableName]],
                'copy_table' => ['change.newName' => ['required', new TableName], 'change.withData' => ['boolean']],
                'create_table' => [
                    'change.columns' => ['required', 'array', 'min:1', 'max:200'],
                    ...$columnRules('change.columns.*'),
                    'change.primaryKey' => ['nullable', 'array', 'max:16'],
                    'change.primaryKey.*' => ['string', 'max:64'],
                    'change.engine' => ['nullable', 'string', 'max:32'],
                    'change.collation' => ['nullable', 'string', 'max:64'],
                    'change.comment' => ['nullable', 'string', 'max:2048'],
                ],
                default => [],
            },
        ];
    }

    /**
     * Destructive changes need the table name typed and the MySQL password re-entered
     * when they are applied (not when previewed).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->requiresConfirmation()) {
                    return;
                }

                if ($this->input('confirmation') !== $this->input('table')) {
                    $validator->errors()->add('confirmation', 'Type the table name exactly to confirm.');

                    return;
                }

                $user = $this->user();
                $password = $this->input('password');

                if (! $user instanceof User || ! is_string($password) || ! app(AuthenticateMySQLUser::class)->verify($user->username, $password)) {
                    $validator->errors()->add('password', 'The MySQL password is incorrect.');
                }
            },
        ];
    }

    public function isDestructive(): bool
    {
        return in_array($this->input('change.type'), self::DESTRUCTIVE_TYPES, true);
    }

    public function tableName(): string
    {
        return $this->string('table')->toString();
    }

    public function allowsLocking(): bool
    {
        return $this->boolean('allowLocking');
    }

    public function toChange(): SchemaChange
    {
        /** @var array<string, mixed> $change */
        $change = $this->validated('change');

        return match ($change['type']) {
            'add_column' => new AddColumn($this->column($change['column']), $this->optionalString($change['after'] ?? null), (bool) ($change['first'] ?? false)),
            'modify_column' => new ModifyColumn((string) $change['from'], $this->column($change['column']), $this->optionalString($change['after'] ?? null), (bool) ($change['first'] ?? false)),
            'drop_column' => new DropColumn((string) $change['column']),
            'add_index' => new AddIndex(new IndexDefinition(IndexType::from($change['indexType']), $this->stringList($change['columns']), $this->optionalString($change['name'] ?? null))),
            'drop_index' => new DropIndex((string) $change['name']),
            'add_foreign_key' => new AddForeignKey(new ForeignKeyDefinition(
                $this->stringList($change['columns']),
                (string) $change['referencedTable'],
                $this->stringList($change['referencedColumns']),
                ForeignKeyAction::from($change['onDelete']),
                ForeignKeyAction::from($change['onUpdate']),
                $this->optionalString($change['name'] ?? null),
            )),
            'drop_foreign_key' => new DropForeignKey((string) $change['name']),
            'rename_table' => new RenameTable((string) $change['newName']),
            'copy_table' => new CopyTable((string) $change['newName'], (bool) ($change['withData'] ?? false)),
            'truncate_table' => new TruncateTable,
            'drop_table' => new DropTable,
            'create_table' => new CreateTable(
                array_values(array_map($this->column(...), $change['columns'])),
                $this->stringList($change['primaryKey'] ?? []),
                $this->optionalString($change['engine'] ?? null),
                $this->optionalString($change['collation'] ?? null),
                (string) ($change['comment'] ?? ''),
            ),
            default => throw new InvalidArgumentException("Unsupported schema change type \"{$change['type']}\"."),
        };
    }

    protected function requiresConfirmation(): bool
    {
        return $this->isDestructive() && $this->routeIs('schema.apply');
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function column(array $column): ColumnDefinition
    {
        /** @var array{kind?: string|null, value?: string|null} $default */
        $default = $column['default'] ?? [];

        return new ColumnDefinition(
            name: (string) $column['name'],
            type: (string) $column['type'],
            length: $this->optionalString($column['length'] ?? null),
            values: $this->stringList($column['values'] ?? []),
            unsigned: (bool) ($column['unsigned'] ?? false),
            nullable: (bool) ($column['nullable'] ?? true),
            default: new ColumnDefault(
                ColumnDefaultKind::tryFrom((string) ($default['kind'] ?? '')) ?? ColumnDefaultKind::None,
                $default['value'] ?? null,
            ),
            autoIncrement: (bool) ($column['autoIncrement'] ?? false),
            onUpdateCurrentTimestamp: (bool) ($column['onUpdateCurrentTimestamp'] ?? false),
            collation: $this->optionalString($column['collation'] ?? null),
            comment: (string) ($column['comment'] ?? ''),
        );
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        return array_values(array_map(strval(...), is_array($values) ? $values : []));
    }
}
