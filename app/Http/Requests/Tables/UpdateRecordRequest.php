<?php

namespace App\Http\Requests\Tables;

use App\Database\DTOs\Page;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * The row's encoded key plus the column values to change.
 */
class UpdateRecordRequest extends StoreRecordRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:4096'],
            'values' => ['required', 'array', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if (is_string($this->input('key')) && Page::decodeCursor($this->string('key')->toString()) === null) {
                    $validator->errors()->add('key', 'The row key is invalid.');
                }
            },
        ];
    }

    /**
     * @return list<int|float|string|null>
     */
    public function keyValues(): array
    {
        return Page::decodeCursor($this->string('key')->toString()) ?? [];
    }
}
