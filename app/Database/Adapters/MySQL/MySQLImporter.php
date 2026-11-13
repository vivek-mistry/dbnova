<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ImporterInterface;
use App\Database\DTOs\Import\PreflightReport;
use App\Database\Exceptions\SqlStatementTooLargeException;
use App\Database\Import\SourceException;
use App\Database\Import\SourceStream;
use App\Database\Sql\SqlStatement;
use App\Database\Sql\SqlStreamScanner;

class MySQLImporter implements ImporterInterface
{
    public function preflight(SourceStream $source, ?callable $onProgress = null): PreflightReport
    {
        $scanner = new SqlStreamScanner(config()->integer('dbnova.limits.max_statement_bytes'));
        $chunkBytes = max(1, config()->integer('dbnova.limits.pump_chunk_bytes'));
        $scan = new MySQLPreflightScan;
        $problems = [];
        $sqlBytes = 0;

        try {
            while (($data = $source->read($chunkBytes)) !== '') {
                $sqlBytes += strlen($data);

                foreach ($scanner->feed($data) as $statement) {
                    $scan->inspect($statement);
                }

                if ($onProgress !== null) {
                    $onProgress($source->position(), $scan->statementCount);
                }
            }

            foreach ($scanner->finish() as $statement) {
                $scan->inspect($statement);
            }

            if ($scanner->isInsideLiteral()) {
                $problems[] = 'The file ends inside a string or comment, so it looks truncated. Upload the complete dump.';
            }
        } catch (SourceException|SqlStatementTooLargeException $exception) {
            $problems[] = $exception->getMessage();
        }

        return $scan->report($problems, $sqlBytes);
    }

    /**
     * Whether an import of a dump made for $sourceDatabase skips this
     * statement: its USE / CREATE / DROP / ALTER DATABASE would act on a
     * database other than the chosen target.
     */
    public static function skipsStatement(SqlStatement $statement, ?string $sourceDatabase): bool
    {
        if ($sourceDatabase === null) {
            return false;
        }

        $reference = MySQLDumpStatement::databaseStatement($statement);

        return $reference !== null && $reference['name'] === $sourceDatabase;
    }
}
