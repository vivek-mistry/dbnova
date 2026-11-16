<?php

namespace App\Http\Requests\Databases;

use App\Rules\DatabaseName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatabaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new DatabaseName],
            'collation' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
        ];
    }
}
