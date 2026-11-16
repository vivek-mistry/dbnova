<?php

namespace App\Database\DTOs;

final readonly class ColumnInfo
{
    /**
     * Column data types whose values are raw bytes and cannot be sent as text.
     *
     * @var list<string>
     */
    private const array BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /**
     * Column data types that can hold long text.
     *
     * @var list<string>
     */
    private const array LONG_TEXT_TYPES = ['text', 'mediumtext', 'longtext', 'json'];

    /**
     * Text longer than this many characters is previewed rather than sent in full.
     */
    public const int PREVIEW_LENGTH = 256;

    public function __construct(
        public string $name,
        public string $dataType,
        public string $columnType,
        public bool $nullable,
        public string $key,
        public ?string $default,
        public string $extra,
        public ?int $maxLength,
        public string $comment,
        public ?string $collation = null,
    ) {}

    public function isBinary(): bool
    {
        return in_array($this->dataType, self::BINARY_TYPES, true);
    }

    /**
     * Whether values may be longer than the preview length and must be truncated.
     */
    public function needsPreview(): bool
    {
        return in_array($this->dataType, self::LONG_TEXT_TYPES, true)
            || ($this->maxLength !== null && $this->maxLength > self::PREVIEW_LENGTH && ! $this->isBinary());
    }

    /**
     * @return array{name: string, dataType: string, columnType: string, nullable: bool, key: string, default: string|null, extra: string, comment: string, collation: string|null, isBinary: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'dataType' => $this->dataType,
            'columnType' => $this->columnType,
            'nullable' => $this->nullable,
            'key' => $this->key,
            'default' => $this->default,
            'extra' => $this->extra,
            'comment' => $this->comment,
            'collation' => $this->collation,
            'isBinary' => $this->isBinary(),
        ];
    }
}
