<?php

namespace App\Http\Requests\Imports;

use App\Actions\Fortify\AuthenticateMySQLUser;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dropping and recreating an import's target database requires typing its
 * name and re-entering the MySQL password, like dropping a database.
 */
class ResetImportTargetRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $import = $this->route('import');

        return [
            'confirmation' => ['required', 'string', Rule::in([$import instanceof ImportJob ? $import->database_name : null])],
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
