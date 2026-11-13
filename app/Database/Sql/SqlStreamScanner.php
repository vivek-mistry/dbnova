<?php

namespace App\Database\Sql;

use App\Database\Exceptions\SqlStatementTooLargeException;

/**
 * Splits MySQL text into statements the way the mysql client does: a
 * delimiter (";" by default, changeable with DELIMITER) ends a statement
 * only outside string literals, quoted identifiers and comments.
 *
 * It is incremental: feed() accepts arbitrary chunks (statements and
 * tokens may span chunk boundaries) and returns the statements completed
 * so far, so a large dump can be split without loading it into memory.
 * Memory use is bounded by the largest single statement.
 *
 * The hot loop only finds boundaries and copies raw slices of the input;
 * keyword and code analysis happen on demand in SqlStatement. This keeps
 * multi-gigabyte dumps fast to scan.
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

    /** Unprocessed input. */
    private string $buffer = '';

    /** The statement being built, as written. */
    private string $sql = '';

    /** The line number at the start of $buffer (1-based). */
    private int $line = 1;

    /** The line the current statement started on, or 0 before its first code character. */
    private int $statementLine = 0;

    /** Offset of the current statement's first code character in $this->sql. */
    private int $codeOffset = 0;

    /** Whether the current statement has a backslash outside strings (a mysql client command). */
    private bool $backslashInCode = false;

    /**
     * @param  int  $maxStatementBytes  Fail when one statement grows past this size (0 = no limit).
     */
    public function __construct(private int $maxStatementBytes = 0) {}

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
     *
     * @throws SqlStatementTooLargeException
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
     *
     * @throws SqlStatementTooLargeException
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
     * Whether the input so far ends inside a string, quoted identifier or
     * block comment, which means a truncated file once all input is fed.
     */
    public function isInsideLiteral(): bool
    {
        return in_array($this->state, [self::SINGLE_QUOTE, self::DOUBLE_QUOTE, self::BACKTICK, self::BLOCK_COMMENT], true);
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
        // Start of the text not yet copied into $this->sql.
        $segment = 0;
        // Newlines before this offset are already counted in $this->line.
        $counted = 0;
        $stops = "'\"`#-/\\".$this->delimiter[0];

        while ($position < $length) {
            $state = $this->state;

            if ($state === self::SINGLE_QUOTE || $state === self::DOUBLE_QUOTE || $state === self::BACKTICK) {
                $quote = $state === self::SINGLE_QUOTE ? "'" : ($state === self::DOUBLE_QUOTE ? '"' : '`');
                $quoteStops = $quote === '`' ? '`' : $quote.'\\';

                while (true) {
                    $position += strcspn($buffer, $quoteStops, $position);

                    if ($position >= $length) {
                        break;
                    }

                    if ($buffer[$position] === '\\') {
                        if ($position + 1 >= $length && ! $final) {
                            break 2; // The escaped character is in the next chunk.
                        }

                        $position += 2;

                        continue;
                    }

                    if ($position + 1 >= $length && ! $final) {
                        break 2; // It might be a doubled quote.
                    }

                    if (($buffer[$position + 1] ?? '') === $quote) {
                        $position += 2;

                        continue;
                    }

                    $position++;
                    $this->state = self::NORMAL;

                    break;
                }

                $position = min($position, $length);

                continue;
            }

            if ($state === self::LINE_COMMENT) {
                $lineEnd = strpos($buffer, "\n", $position);

                if ($lineEnd === false) {
                    $position = $length;
                } else {
                    $position = $lineEnd + 1;
                    $this->state = self::NORMAL;
                }

                continue;
            }

            if ($state === self::BLOCK_COMMENT) {
                $close = strpos($buffer, '*/', $position);

                if ($close !== false) {
                    $position = $close + 2;
                    $this->state = self::NORMAL;

                    continue;
                }

                // Keep a trailing "*" in case "*/" is split across chunks.
                $end = $final ? $length : max($position, $length - 1);

                if ($end === $position) {
                    break;
                }

                $position = $end;

                continue;
            }

            if ($this->statementLine === 0) {
                // Before a statement's first code: skip whitespace on its own so a
                // DELIMITER directive at the start of the next line is noticed.
                $whitespace = strspn($buffer, " \t\r\n", $position);

                if ($whitespace > 0) {
                    $position += $whitespace;

                    continue;
                }

                if ($buffer[$position] === 'D' || $buffer[$position] === 'd') {
                    $directiveEnd = $this->delimiterDirectiveEnd($buffer, $position, $length, $final);

                    if ($directiveEnd === 0) {
                        break; // Need more input to tell.
                    }

                    if ($directiveEnd !== null) {
                        $this->sql = '';
                        $position = $segment = $directiveEnd;
                        $stops = "'\"`#-/\\".$this->delimiter[0];

                        continue;
                    }
                }
            }

            $character = $buffer[$position];

            if ($character === $this->delimiter[0]) {
                $delimiterLength = strlen($this->delimiter);

                if ($length - $position < $delimiterLength && ! $final && str_starts_with($this->delimiter, substr($buffer, $position))) {
                    break; // A delimiter may continue in the next chunk.
                }

                if (substr_compare($buffer, $this->delimiter, $position, $delimiterLength) === 0) {
                    $this->sql .= substr($buffer, $segment, $position - $segment);
                    $statement = $this->emit();

                    if ($statement !== null) {
                        $statements[] = $statement;
                    }

                    $position = $segment = $position + $delimiterLength;

                    continue;
                }
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $this->markStatementStart($buffer, $position, $segment, $counted);
                $this->state = $character === "'" ? self::SINGLE_QUOTE : ($character === '"' ? self::DOUBLE_QUOTE : self::BACKTICK);
                $position++;

                continue;
            }

            if ($character === '#') {
                $this->state = self::LINE_COMMENT;
                $position++;

                continue;
            }

            if ($character === '-' || $character === '/') {
                $next = $buffer[$position + 1] ?? null;
                $third = $buffer[$position + 2] ?? null;

                // Both comment openers need up to two more characters to recognise.
                if (! $final && ($next === null || ($next === ($character === '-' ? '-' : '*') && $third === null))) {
                    break;
                }

                // "--" starts a comment only when followed by whitespace or the end. This is
                // the mysql client's rule (the server also accepts control characters), and
                // imports are split by the client, so the scanner must agree with it.
                if ($character === '-' && $next === '-' && ($third === null || ctype_space($third))) {
                    $this->state = self::LINE_COMMENT;
                    $position += 2;

                    continue;
                }

                if ($character === '/' && $next === '*') {
                    if ($third === '!') {
                        // MySQL executes conditional comments, so they are code.
                        $this->markStatementStart($buffer, $position, $segment, $counted);
                    }

                    $this->state = self::BLOCK_COMMENT;
                    $position += 2;

                    continue;
                }
            }

            if ($character === '\\') {
                // Outside strings a backslash is a mysql client command (\C, \G, ...).
                $this->backslashInCode = true;
            }

            // A run of ordinary characters.
            $this->markStatementStart($buffer, $position, $segment, $counted);
            $position += 1 + strcspn($buffer, $stops, $position + 1);
        }

        $this->sql .= substr($buffer, $segment, $position - $segment);
        $this->line += substr_count($buffer, "\n", $counted, $position - $counted);
        $this->buffer = substr($buffer, $position);

        // Unconsumed input counts too: a DELIMITER line without a newline would otherwise grow forever.
        if ($this->maxStatementBytes > 0 && strlen($this->sql) + strlen($this->buffer) > $this->maxStatementBytes) {
            throw new SqlStatementTooLargeException($this->statementLine > 0 ? $this->statementLine : $this->line, $this->maxStatementBytes);
        }

        return $statements;
    }

    /**
     * Record the line of the current statement's first code character.
     */
    private function markStatementStart(string $buffer, int $position, int $segment, int &$counted): void
    {
        if ($this->statementLine !== 0) {
            return;
        }

        $this->line += substr_count($buffer, "\n", $counted, $position - $counted);
        $counted = $position;
        $this->statementLine = $this->line;
        $this->codeOffset = strlen($this->sql) + $position - $segment;
    }

    /**
     * The offset just after a DELIMITER directive line starting here, 0 when
     * more input is needed to tell, or null when this is not a directive.
     */
    private function delimiterDirectiveEnd(string $buffer, int $position, int $length, bool $final): ?int
    {
        $start = substr($buffer, $position, 10);

        if (strlen($start) < 10 && ! $final && strncasecmp($start, 'DELIMITER ', strlen($start)) === 0) {
            return 0;
        }

        if (preg_match('/^DELIMITER[ \t]/i', $start) !== 1) {
            return null;
        }

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

        return $end;
    }

    private function emit(): ?SqlStatement
    {
        $statement = null;

        if ($this->statementLine !== 0) {
            // Leading comments are part of the statement text; the text starts this many lines earlier.
            $leadingWhitespace = strspn($this->sql, " \t\r\n");
            $commentLines = substr_count($this->sql, "\n", $leadingWhitespace, max(0, $this->codeOffset - $leadingWhitespace));

            $statement = new SqlStatement(trim($this->sql), $this->statementLine, $this->delimiter, $this->statementLine - $commentLines, $this->backslashInCode);
        }

        $this->sql = '';
        $this->backslashInCode = false;
        $this->statementLine = 0;

        return $statement;
    }
}
