<?php

namespace App\Http\Requests\Tables;

use App\Database\DTOs\Page;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The encoded keys of the rows to delete.
 */
class DeleteRecordsRequest extends FormRequest
{
    public const int MAX_ROWS = 1000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'keys.*' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('keys', []) as $index => $key) {
                    if (is_string($key) && Page::decodeCursor($key) === null) {
                        $validator->errors()->add("keys.{$index}", 'The row key is invalid.');
                    }
                }
            },
        ];
    }

    /**
     * @return list<list<int|float|string|null>>
     */
    public function keys(): array
    {
        return array_values(array_map(
            fn (string $key): array => Page::decodeCursor($key) ?? [],
            (array) $this->validated('keys'),
        ));
    }
}
