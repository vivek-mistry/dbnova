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

        if (mb_strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidIdentifierException('Names cannot be longer than '.self::MAX_LENGTH.' characters.');
        }
    }
}
