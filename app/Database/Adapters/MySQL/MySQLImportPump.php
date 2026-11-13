<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Import\ImportRefusedException;
use App\Database\Import\SourceStream;
use App\Database\Sql\SqlStatement;
use App\Database\Sql\SqlStreamScanner;
use Generator;

/**
 * Turns a dump into the text piped to the mysql client, one chunk at a time.
 *
 * Statements are sent exactly as written. Only whole statements are
 * dropped (the source database's USE / CREATE DATABASE), and blank lines
 * are added so every statement starts on the same line as in the original
 * file: the client's "ERROR ... at line N" then points into the user's file.
 */
final class MySQLImportPump
{
    public int $bytesSent = 0;

    public int $statementsSent = 0;

    public int $skippedStatements = 0;

    /** The line the next output character lands on (as the mysql client counts). */
    private int $outputLine = 1;

    /** The delimiter the mysql client is currently using. */
    private string $delimiter = ';';

    /**
     * @param  int<1, max>  $chunkBytes
     */
    public function __construct(
        private SourceStream $source,
        private ?string $sourceDatabase,
        private int $chunkBytes,
        private int $maxStatementBytes,
        private bool $speedMode = false,
        private bool $trustCompoundPrograms = false,
    ) {}

    /**
     * The text for the mysql client's stdin. A chunk is pulled only after
     * the previous one was taken, so reading never gets ahead of MySQL.
     *
     * @return Generator<int, string>
     */
    public function chunks(): Generator
    {
        $scanner = new SqlStreamScanner($this->maxStatementBytes);

        while (($data = $this->source->read($this->chunkBytes)) !== '') {
            $output = $this->render($scanner->feed($data));

            if ($output !== '') {
                yield $output;
                $this->bytesSent += strlen($output);
            }
        }

        $output = $this->render($scanner->finish());

        if ($this->speedMode) {
            // autocommit was turned off for the session (--init-command); keep what was sent.
            $output .= ($this->delimiter === ';' ? '' : "DELIMITER ;\n").'COMMIT;'."\n";
        }

        if ($output !== '') {
            yield $output;
            $this->bytesSent += strlen($output);
        }
    }

    /**
     * Where the source has been read up to (for progress).
     */
    public function sourcePosition(): int
    {
        return $this->source->position();
    }

    /**
     * @param  list<SqlStatement>  $statements
     *
     * @throws ImportRefusedException
     */
    public function render(array $statements): string
    {
        $output = '';

        foreach ($statements as $statement) {
            if (MySQLImporter::skipsStatement($statement, $this->sourceDatabase)) {
                $this->skippedStatements++;

                continue;
            }

            $violation = MySQLDumpPolicy::violation($statement, $this->trustCompoundPrograms);

            if ($violation !== null) {
                throw new ImportRefusedException(sprintf('Line %d: %s', $statement->line, $violation));
            }

            if ($statement->delimiter !== $this->delimiter) {
                $output .= $this->padTo($statement->firstLine - 1).'DELIMITER '.$statement->delimiter."\n";
                $this->outputLine++;
                $this->delimiter = $statement->delimiter;
            }

            $output .= $this->padTo($statement->firstLine).$statement->sql;
            $this->outputLine += substr_count($statement->sql, "\n");

            if ($statement->endsWithLineBreak) {
                // The last line may be a "-- comment"; a delimiter on it would be commented out.
                $output .= "\n";
                $this->outputLine++;
            } elseif ($statement->endsWithSpace) {
                // Keep a gap: "1 $" followed by "$$" must not become "1 $$$".
                $output .= ' ';
            }

            $output .= $this->delimiter."\n";
            $this->outputLine++;
            $this->statementsSent++;
        }

        return $output;
    }

    private function padTo(int $line): string
    {
        if ($this->outputLine >= $line) {
            return '';
        }

        $padding = str_repeat("\n", $line - $this->outputLine);
        $this->outputLine = $line;

        return $padding;
    }
}
