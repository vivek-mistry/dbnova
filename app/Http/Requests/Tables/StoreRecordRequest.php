<?php

namespace App\Http\Requests\Tables;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Column values for a new row: {"values": {"column": "text" | null}}.
 * Columns left out use their defaults.
 */
class StoreRecordRequest extends FormRequest
{
    public const int MAX_VALUE_LENGTH = 1_048_576;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'values' => ['present', 'array', 'max:500'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('values', []) as $column => $value) {
                    if (! is_string($column) || $column === '' || mb_strlen($column) > 64) {
                        $validator->errors()->add('values', 'Column names must be 1 to 64 characters.');

                        return;
                    }

                    if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
                        $validator->errors()->add('values', "The value for \"{$column}\" must be text or null.");

                        return;
                    }

                    if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                        $validator->errors()->add('values', "The value for \"{$column}\" is larger than 1 MB.");

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function columnValues(): array
    {
        $values = [];

        foreach ((array) $this->input('values', []) as $column => $value) {
            $values[(string) $column] = $value === null ? null : (string) $value;
        }

        return $values;
    }
}
