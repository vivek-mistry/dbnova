<?php

namespace App\Database\Sql;

/**
 * One statement found by the scanner.
 *
 * $code is the statement with string literals, quoted identifiers and
 * ordinary comments blanked out, so keywords can be found without being
 * fooled by text such as 'WHERE' inside a string. Conditional comments
 * (/*!40101 ... *\/) are kept in $code because MySQL executes them.
 */
final readonly class SqlStatement
{
    /** @var list<string> */
    private const array READ_ONLY_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'USE', 'WITH', 'HELP'];

    public function __construct(
        public string $sql,
        public string $code,
        public int $line,
    ) {}

    /**
     * The first keyword, e.g. "SELECT", or "" when there is none.
     */
    public function keyword(): string
    {
        return preg_match('/[A-Za-z_]+/', $this->code, $matches) === 1 ? strtoupper($matches[0]) : '';
    }

    public function isReadOnly(): bool
    {
        return self::isReadOnlyKeyword($this->keyword());
    }

    /**
     * Whether statements starting with this keyword only read data.
     */
    public static function isReadOnlyKeyword(string $keyword): bool
    {
        return in_array(strtoupper($keyword), self::READ_ONLY_KEYWORDS, true);
    }

    /**
     * DROP and TRUNCATE, and UPDATE or DELETE without a WHERE clause.
     */
    public function isDestructive(): bool
    {
        return match ($this->keyword()) {
            'DROP', 'TRUNCATE' => true,
            'UPDATE', 'DELETE' => preg_match('/\bWHERE\b/i', $this->code) !== 1,
            default => false,
        };
    }

    /**
     * Whether a SELECT already limits its own rows.
     */
    public function hasLimit(): bool
    {
        return preg_match('/\bLIMIT\b/i', $this->code) === 1;
    }
}
