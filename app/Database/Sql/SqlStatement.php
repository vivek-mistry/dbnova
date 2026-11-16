<?php

namespace App\Database\Sql;

/**
 * One statement found by the scanner, as written (without its delimiter).
 *
 * Analysis is done on demand: leadingTokens() reads only the start of the
 * statement (cheap for a multi-megabyte INSERT), and code() blanks string
 * literals, quoted identifiers and ordinary comments so keywords such as
 * WHERE are not found inside 'a string'. Conditional comments
 * (/*!40101 ... *\/) count as code because MySQL executes them.
 */
final readonly class SqlStatement
{
    /** @var list<string> */
    private const array READ_ONLY_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'USE', 'WITH', 'HELP'];

    /** The line the statement text starts on (before $line when leading comments are included). */
    public int $firstLine;

    /**
     * @param  int  $line  The line of the statement's first code (what MySQL reports in errors).
     */
    public function __construct(
        public string $sql,
        public int $line,
        public string $delimiter = ';',
        ?int $firstLine = null,
        public bool $hasClientCommand = false,
        public bool $endsWithLineBreak = false,
        public bool $endsWithSpace = false,
    ) {
        $this->firstLine = $firstLine ?? $line;
    }

    /**
     * The first keyword, e.g. "SELECT", or "" when there is none.
     */
    public function keyword(): string
    {
        foreach ($this->leadingTokens(4) as $token) {
            if ($token['type'] === 'word' && preg_match('/^[A-Za-z_]+/', $token['value'], $matches) === 1) {
                return strtoupper($matches[0]);
            }
        }

        return '';
    }

    /**
     * The first tokens of the statement, skipping whitespace and comments.
     * Quoted identifiers are unquoted; conditional comment markers are
     * dropped so their contents read as ordinary code.
     *
     * @return list<array{type: 'word'|'identifier'|'string'|'symbol', value: string}>
     */
    public function leadingTokens(int $limit): array
    {
        $sql = $this->sql;
        $length = strlen($sql);
        $position = 0;
        $tokens = [];

        while (count($tokens) < $limit && $position < $length) {
            $position += strspn($sql, " \t\r\n", $position);

            if ($position >= $length) {
                break;
            }

            $character = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            if ($character === '#' || ($character === '-' && $next === '-' && ($position + 2 >= $length || ctype_space($sql[$position + 2])))) {
                $lineEnd = strpos($sql, "\n", $position);
                $position = $lineEnd === false ? $length : $lineEnd + 1;

                continue;
            }

            if ($character === '/' && $next === '*') {
                if (($sql[$position + 2] ?? '') === '!') {
                    $position += 3;
                    $position += strspn($sql, '0123456789', $position);

                    continue;
                }

                $close = strpos($sql, '*/', $position + 2);
                $position = $close === false ? $length : $close + 2;

                continue;
            }

            if ($character === '*' && $next === '/') {
                $position += 2; // End of a conditional comment.

                continue;
            }

            if ($character === '`' || $character === '"' || $character === "'") {
                $end = self::quotedEnd($sql, $position, $character);
                $inner = substr($sql, $position + 1, max(0, $end - $position - 2));
                $tokens[] = $character === '`'
                    ? ['type' => 'identifier', 'value' => str_replace('``', '`', $inner)]
                    : ['type' => 'string', 'value' => $inner];
                $position = $end;

                continue;
            }

            $word = strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', $position);

            if ($word > 0) {
                $tokens[] = ['type' => 'word', 'value' => substr($sql, $position, $word)];
                $position += $word;

                continue;
            }

            $tokens[] = ['type' => 'symbol', 'value' => $character];
            $position++;
        }

        return $tokens;
    }

    /**
     * The statement with string literals, quoted identifiers and ordinary
     * comments removed (quotes are kept as empty pairs).
     */
    public function code(): string
    {
        $sql = $this->sql;
        $length = strlen($sql);
        $position = 0;
        $code = '';
        $inConditional = false;

        while ($position < $length) {
            $run = strcspn($sql, "'\"`#-/*", $position);
            $code .= substr($sql, $position, $run);
            $position += $run;

            if ($position >= $length) {
                break;
            }

            $character = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            if ($character === "'" || $character === '"' || $character === '`') {
                $code .= $character.$character;
                $position = self::quotedEnd($sql, $position, $character);
            } elseif ($character === '#' || ($character === '-' && $next === '-' && ($position + 2 >= $length || ctype_space($sql[$position + 2])))) {
                $lineEnd = strpos($sql, "\n", $position);
                $code .= ' ';
                $position = $lineEnd === false ? $length : $lineEnd;
            } elseif ($character === '/' && $next === '*' && ($sql[$position + 2] ?? '') === '!') {
                $code .= ' ';
                $position += 3;
                $position += strspn($sql, '0123456789', $position);
                $inConditional = true;
            } elseif ($character === '/' && $next === '*') {
                $close = strpos($sql, '*/', $position + 2);
                $code .= ' ';
                $position = $close === false ? $length : $close + 2;
            } elseif ($character === '*' && $next === '/' && $inConditional) {
                $code .= ' ';
                $position += 2;
                $inConditional = false;
            } else {
                $code .= $character;
                $position++;
            }
        }

        return $code;
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
     * A read-only statement that returns rows, so its result can be exported (USE and HELP do not qualify).
     */
    public function isExportableQuery(): bool
    {
        return $this->exportProblem() === null;
    }

    /**
     * Why this statement cannot be exported as a query result, or null.
     *
     * The export re-runs the statement in a worker for a long time, so on
     * top of being read-only it must be exactly what the scanner sees: no
     * custom DELIMITER and no ";" in its code (incl. /*! *\/ comments),
     * which could hide a second statement; no locking clauses, which
     * would block the application's writes while rows stream; and no
     * INTO, which writes a file on the server or sets variables.
     */
    public function exportProblem(): ?string
    {
        if (! $this->isReadOnly() || in_array($this->keyword(), ['USE', 'HELP'], true)) {
            return 'Only read-only queries (SELECT, WITH, SHOW, DESCRIBE, EXPLAIN) can be exported to CSV.';
        }

        if ($this->delimiter !== ';' || str_contains($this->code(), ';')) {
            return 'The query must be a single statement without DELIMITER or ";" inside it.';
        }

        if (preg_match('/\b(FOR\s+(UPDATE|SHARE)|LOCK\s+IN\s+SHARE\s+MODE|INTO)\b/i', $this->code()) === 1) {
            return 'Queries with FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE or INTO cannot be exported: they lock rows or write elsewhere.';
        }

        return null;
    }

    /**
     * DROP and TRUNCATE, and UPDATE or DELETE without a WHERE clause.
     */
    public function isDestructive(): bool
    {
        return match ($this->keyword()) {
            'DROP', 'TRUNCATE' => true,
            'UPDATE', 'DELETE' => preg_match('/\bWHERE\b/i', $this->code()) !== 1,
            default => false,
        };
    }

    /**
     * Whether a SELECT already limits its own rows.
     */
    public function hasLimit(): bool
    {
        return preg_match('/\bLIMIT\b/i', $this->code()) === 1;
    }

    /**
     * Whether $needle appears inside a conditional comment (outside its
     * strings). The mysql client splits statements inside /*! ... *\/,
     * while this scanner keeps the comment whole.
     */
    public function conditionalCommentContains(string $needle): bool
    {
        $sql = $this->sql;
        $length = strlen($sql);
        $position = 0;

        while (($open = strpos($sql, '/*!', $position)) !== false) {
            $position = $open + 3;

            while ($position < $length) {
                $position += strcspn($sql, "'\"`*".$needle[0], $position);

                if ($position >= $length) {
                    return false;
                }

                $character = $sql[$position];

                if ($character === "'" || $character === '"' || $character === '`') {
                    $position = self::quotedEnd($sql, $position, $character);
                } elseif ($character === '*' && ($sql[$position + 1] ?? '') === '/') {
                    $position += 2;

                    break;
                } elseif (substr_compare($sql, $needle, $position, strlen($needle)) === 0) {
                    return true;
                } else {
                    $position++;
                }
            }
        }

        return false;
    }

    /**
     * The offset just after the quoted text that opens at $start.
     */
    private static function quotedEnd(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $position = $start + 1;
        $stops = $quote === '`' ? '`' : $quote.'\\';

        while (true) {
            $position += strcspn($sql, $stops, $position);

            if ($position >= $length) {
                return $length;
            }

            if ($sql[$position] === '\\') {
                $position += 2;

                continue;
            }

            if (($sql[$position + 1] ?? '') === $quote) {
                $position += 2;

                continue;
            }

            return $position + 1;
        }
    }
}
