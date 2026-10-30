<?php

namespace App\Http\Requests\Tables;

use App\Database\DTOs\Page;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Locates one cell by column and the row's key values (an encoded cursor).
 */
class TableValueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'column' => ['required', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('key') && Page::decodeCursor($this->string('key')->toString()) === null) {
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
