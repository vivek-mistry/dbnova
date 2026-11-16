<?php

namespace App\Rules;

use App\Database\Adapters\MySQL\MySQLSchemaManager;
use App\Database\Support\IdentifierQuoter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A name MySQL accepts for a new database: at most 64 characters, no path
 * separators or dots (databases are directories on disk), no trailing
 * space, and not one of the server's own system databases.
 */
class DatabaseName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }

        if (mb_strlen($value) > 64) {
            $fail('The :attribute cannot be longer than 64 characters.');
        }

        if (preg_match('#[/\\\\.\x00]#', $value) === 1) {
            $fail('The :attribute cannot contain "/", "\\", "." or NUL characters.');
        }

        if (str_ends_with($value, ' ')) {
            $fail('The :attribute cannot end with a space.');
        }

        if (! mb_check_encoding($value, 'UTF-8') || IdentifierQuoter::outsideBmp($value)) {
            $fail('The :attribute cannot contain emoji or other characters MySQL does not allow in names.');
        }

        if (in_array(strtolower($value), MySQLSchemaManager::SYSTEM_DATABASES, true)) {
            $fail('The :attribute cannot be the name of a MySQL system database.');
        }
    }
}
