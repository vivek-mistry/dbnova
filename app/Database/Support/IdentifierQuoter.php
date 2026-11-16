<?php

namespace App\Database\Support;

use App\Database\Exceptions\InvalidIdentifierException;

/**
 * The only place where database, table and column names are turned into SQL.
 */
class IdentifierQuoter
{
    public const int MAX_LENGTH = 64;

    /**
     * Quote an identifier with backticks, doubling any backtick inside it.
     *
     * @throws InvalidIdentifierException
     */
    public function quote(string $identifier): string
    {
        $this->assertValid($identifier);

        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * @throws InvalidIdentifierException
     */
    public function assertValid(string $identifier): void
    {
        if ($identifier === '') {
            throw new InvalidIdentifierException('Names cannot be empty.');
        }

        if (str_contains($identifier, "\0")) {
            throw new InvalidIdentifierException('Names cannot contain NUL characters.');
        }

        if (! mb_check_encoding($identifier, 'UTF-8')) {
            throw new InvalidIdentifierException('Names must be valid UTF-8.');
        }

        if (mb_strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidIdentifierException('Names cannot be longer than '.self::MAX_LENGTH.' characters.');
        }

        if (self::outsideBmp($identifier)) {
            throw new InvalidIdentifierException('Names cannot contain emoji or other characters outside the Basic Multilingual Plane.');
        }

        if (str_ends_with($identifier, ' ')) {
            throw new InvalidIdentifierException('Names cannot end with a space.');
        }
    }

    /**
     * Whether MySQL could have an object with this name. Lookups with any other name
     * are "not found" without asking MySQL, which would fail on them instead: emoji
     * cannot be converted to identifiers (utf8mb3), and a trailing space would match
     * the name without it (information_schema compares with PAD SPACE).
     */
    public static function isValid(string $identifier): bool
    {
        try {
            (new self)->assertValid($identifier);
        } catch (InvalidIdentifierException) {
            return false;
        }

        return true;
    }

    /**
     * MySQL identifiers are stored as utf8mb3: no 4-byte characters such as emoji.
     */
    public static function outsideBmp(string $value): bool
    {
        return preg_match('/[^\x{0000}-\x{FFFF}]/u', $value) === 1;
    }
}
