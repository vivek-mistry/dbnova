<?php

namespace App\Http\Requests\Databases;

use App\Actions\Fortify\AuthenticateMySQLUser;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dropping a database requires typing its name and re-entering the MySQL password.
 */
class DropDatabaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([$this->route('database')])],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type the database name exactly to confirm.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $user instanceof User || ! app(AuthenticateMySQLUser::class)->verify($user->username, $this->string('password')->toString())) {
                    $validator->errors()->add('password', 'The MySQL password is incorrect.');
                }
            },
        ];
    }
}
