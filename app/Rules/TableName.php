<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A name MySQL accepts for a table or column: at most 64 characters, no
 * path separators, dots or NUL, and no trailing space.
 */
class TableName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
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
    }
}
