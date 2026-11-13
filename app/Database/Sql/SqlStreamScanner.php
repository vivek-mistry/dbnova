<?php

namespace App\Database\Sql;

/**
 * Splits MySQL text into statements the way the mysql client does: a
 * delimiter (";" by default, changeable with DELIMITER) ends a statement
 * only outside string literals, quoted identifiers and comments.
 *
 * It is incremental: feed() accepts arbitrary chunks (statements and
 * tokens may span chunk boundaries) and returns the statements completed
 * so far, so a large dump can be split without loading it into memory.
 * Memory use is bounded by the largest single statement.
 */
class SqlStreamScanner
{
    private const int NORMAL = 0;

    private const int SINGLE_QUOTE = 1;

    private const int DOUBLE_QUOTE = 2;

    private const int BACKTICK = 3;

    private const int LINE_COMMENT = 4;

    private const int BLOCK_COMMENT = 5;

    private string $delimiter = ';';

    private int $state = self::NORMAL;

    /** Whether the current block comment is a /*! conditional comment (executed by MySQL). */
    private bool $conditionalComment = false;

    /** Unprocessed input. */
    private string $buffer = '';

    /** The statement being built, as written. */
    private string $sql = '';

    /** The statement being built, with strings, identifiers and comments blanked. */
    private string $code = '';

    /** The current line number (1-based). */
    private int $line = 1;

    /** The line the current statement started on, or 0 before its first code character. */
    private int $statementLine = 0;

    /**
     * Split a complete text into statements.
     *
     * @return list<SqlStatement>
     */
    public static function split(string $sql): array
    {
        $scanner = new self;

        return [...$scanner->feed($sql), ...$scanner->finish()];
    }

    /**
     * Add input and return the statements it completed.
     *
     * @return list<SqlStatement>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;

        return $this->process(final: false);
    }

    /**
     * Signal the end of input and return the final statement, if any.
     *
     * @return list<SqlStatement>
     */
    public function finish(): array
    {
        $statements = $this->process(final: true);
        $last = $this->emit();

        return $last === null ? $statements : [...$statements, $last];
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * @return list<SqlStatement>
     */
    private function process(bool $final): array
    {
        $statements = [];
        $buffer = $this->buffer;
        $length = strlen($buffer);
        $position = 0;

        while ($position < $length) {
            $consumed = match ($this->state) {
                self::NORMAL => $this->scanNormal($buffer, $position, $length, $final, $statements),
                self::SINGLE_QUOTE => $this->scanQuoted($buffer, $position, $length, $final, "'"),
                self::DOUBLE_QUOTE => $this->scanQuoted($buffer, $position, $length, $final, '"'),
                self::BACKTICK => $this->scanQuoted($buffer, $position, $length, $final, '`'),
                self::LINE_COMMENT => $this->scanLineComment($buffer, $position, $length),
                default => $this->scanBlockComment($buffer, $position, $length, $final),
            };

            if ($consumed === 0) {
                break; // Need more input to decide.
            }

            $position += $consumed;
        }

        $this->buffer = substr($buffer, $position);

        return $statements;
    }

    /**
     * @param  list<SqlStatement>  $statements
     */
    private function scanNormal(string $buffer, int $position, int $length, bool $final, array &$statements): int
    {
        if ($this->statementLine === 0) {
            // Before a statement's first code: copy whitespace on its own so a
            // DELIMITER directive at the start of the next line is noticed.
            $whitespace = strspn($buffer, " \t\r\n", $position);

            if ($whitespace > 0) {
                $text = substr($buffer, $position, $whitespace);
                $this->appendSql($text, $text);

                return $whitespace;
            }

            $directive = $this->delimiterDirectiveLength($buffer, $position, $length, $final);

            if ($directive !== null) {
                return $directive;
            }
        }

        $delimiterLength = strlen($this->delimiter);
        $available = $length - $position;

        if ($available < $delimiterLength && ! $final && str_starts_with($this->delimiter, substr($buffer, $position))) {
            return 0; // A delimiter may continue in the next chunk.
        }

        if ($available >= $delimiterLength && substr_compare($buffer, $this->delimiter, $position, $delimiterLength) === 0) {
            $statement = $this->emit();

            if ($statement !== null) {
                $statements[] = $statement;
            }

            return $delimiterLength;
        }

        $character = $buffer[$position];
        $next = $buffer[$position + 1] ?? null;
        $third = $buffer[$position + 2] ?? null;

        if ($character === "'") {
            return $this->open(self::SINGLE_QUOTE, $character);
        }

        if ($character === '"') {
            return $this->open(self::DOUBLE_QUOTE, $character);
        }

        if ($character === '`') {
            return $this->open(self::BACKTICK, $character);
        }

        if ($character === '#') {
            return $this->open(self::LINE_COMMENT, $character);
        }

        if ($character === '-' || $character === '/') {
            // Both comment openers need up to two more characters to recognise.
            if (! $final && ($next === null || ($next === ($character === '-' ? '-' : '*') && $third === null))) {
                return 0;
            }

            // "--" starts a comment only when followed by whitespace or the end.
            if ($character === '-' && $next === '-' && ($third === null || ctype_space($third) || ctype_cntrl($third))) {
                $this->appendSql('--', '  ');
                $this->state = self::LINE_COMMENT;

                return 2;
            }

            if ($character === '/' && $next === '*') {
                $this->conditionalComment = $third === '!';
                $this->state = self::BLOCK_COMMENT;
                $this->appendSql('/*', '  ');

                return 2;
            }
        }

        // Copy a run of ordinary characters at once.
        $run = max(strcspn($buffer, "'\"`#-/".$this->delimiter[0], $position), 1);
        $text = substr($buffer, $position, $run);
        $this->appendSql($text, $text);

        return $run;
    }

    /**
     * The length of a DELIMITER directive line starting here, 0 when more
     * input is needed to tell, or null when this is not a directive.
     */
    private function delimiterDirectiveLength(string $buffer, int $position, int $length, bool $final): ?int
    {
        $start = substr($buffer, $position, 10);

        if (strlen($start) < 10 && ! $final && strncasecmp($start, 'DELIMITER ', strlen($start)) === 0) {
            return 0;
        }

        if (preg_match('/^DELIMITER[ \t]/i', $start) !== 1) {
            return null;
        }

        return $this->scanDelimiterDirective($buffer, $position, $length, $final);
    }

    private function scanDelimiterDirective(string $buffer, int $position, int $length, bool $final): int
    {
        $lineEnd = strpos($buffer, "\n", $position);

        if ($lineEnd === false && ! $final) {
            return 0;
        }

        $end = $lineEnd === false ? $length : $lineEnd + 1;
        $directive = trim(substr($buffer, $position + 10, $end - $position - 10));
        $newDelimiter = preg_split('/\s+/', $directive)[0] ?? '';

        if ($newDelimiter !== '') {
            $this->delimiter = $newDelimiter;
        }

        $this->sql = '';
        $this->code = '';
        $this->line += substr_count($buffer, "\n", $position, $end - $position);

        return $end - $position;
    }

    private function scanQuoted(string $buffer, int $position, int $length, bool $final, string $quote): int
    {
        $character = $buffer[$position];

        if ($character === '\\' && $quote !== '`') {
            if ($position + 1 >= $length) {
                if (! $final) {
                    return 0;
                }

                $this->appendSql($character, ' ');

                return 1;
            }

            $escaped = substr($buffer, $position, 2);
            $this->appendSql($escaped, str_repeat(' ', strlen($escaped)));

            return 2;
        }

        if ($character === $quote) {
            if ($position + 1 >= $length && ! $final) {
                return 0; // It might be a doubled quote.
            }

            if (($buffer[$position + 1] ?? '') === $quote) {
                $this->appendSql($quote.$quote, '  ');

                return 2;
            }

            $this->appendSql($quote, $quote);
            $this->state = self::NORMAL;

            return 1;
        }

        $run = max(strcspn($buffer, $quote.'\\', $position), 1);
        $text = substr($buffer, $position, $run);
        $this->appendSql($text, preg_replace('/[^\n]/', ' ', $text) ?? '');

        return $run;
    }

    private function scanLineComment(string $buffer, int $position, int $length): int
    {
        $lineEnd = strpos($buffer, "\n", $position);
        $end = $lineEnd === false ? $length : $lineEnd + 1;
        $text = substr($buffer, $position, $end - $position);

        $this->appendSql($text, preg_replace('/[^\n]/', ' ', $text) ?? '');

        if ($lineEnd !== false) {
            $this->state = self::NORMAL;
        }

        return $end - $position;
    }

    private function scanBlockComment(string $buffer, int $position, int $length, bool $final): int
    {
        $close = strpos($buffer, '*/', $position);

        if ($close === false) {
            // Keep a trailing "*" in case "*/" is split across chunks.
            $end = $final ? $length : max($position, $length - 1);

            if ($end === $position) {
                return 0;
            }

            $text = substr($buffer, $position, $end - $position);
            $this->appendSql($text, $this->conditionalComment ? $text : (preg_replace('/[^\n]/', ' ', $text) ?? ''));

            return $end - $position;
        }

        $text = substr($buffer, $position, $close + 2 - $position);
        $this->appendSql($text, $this->conditionalComment ? $text : (preg_replace('/[^\n]/', ' ', $text) ?? ''));
        $this->state = self::NORMAL;
        $this->conditionalComment = false;

        return $close + 2 - $position;
    }

    private function open(int $state, string $character): int
    {
        $this->state = $state;
        $this->appendSql($character, $state === self::LINE_COMMENT ? ' ' : $character);

        return 1;
    }

    private function appendSql(string $sql, string $code): void
    {
        if ($this->statementLine === 0 && trim($code) !== '') {
            $this->statementLine = $this->line + substr_count($sql, "\n", 0, strspn($sql, " \t\r\n"));
        }

        $this->sql .= $sql;
        $this->code .= $code;
        $this->line += substr_count($sql, "\n");
    }

    private function emit(): ?SqlStatement
    {
        $statement = trim($this->code) === ''
            ? null
            : new SqlStatement(trim($this->sql), trim($this->code), $this->statementLine);

        $this->sql = '';
        $this->code = '';
        $this->statementLine = 0;

        return $statement;
    }
}
