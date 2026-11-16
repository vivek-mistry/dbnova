<?php

namespace App\Http\Requests\Sql;

use App\Database\DTOs\Query\QueryRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RunQueryRequest extends FormRequest
{
    /**
     * Largest SQL accepted from the editor (bigger scripts belong in an import).
     */
    public const int MAX_SQL_BYTES = 1_048_576;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'max:'.self::MAX_SQL_BYTES],
            'database' => ['nullable', 'string', 'max:64'],
            'runId' => ['required', 'uuid'],
            'confirmDestructive' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sql.max' => 'The SQL is larger than 1 MB. Use Import for big scripts.',
        ];
    }

    public function toQueryRequest(): QueryRequest
    {
        return new QueryRequest(
            sql: $this->string('sql')->toString(),
            database: $this->filled('database') ? $this->string('database')->toString() : null,
            confirmedDestructive: $this->boolean('confirmDestructive'),
            rowCap: config()->integer('dbnova.limits.query_row_cap'),
        );
    }
}
