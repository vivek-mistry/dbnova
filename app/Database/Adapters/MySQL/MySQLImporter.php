<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ImporterInterface;
use App\Database\DTOs\Import\ImportRequest;
use App\Database\DTOs\Import\ImportResult;
use App\Database\DTOs\Import\PreflightReport;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\SqlStatementTooLargeException;
use App\Database\Exceptions\UnsupportedDelimiterException;
use App\Database\Import\ImportRefusedException;
use App\Database\Import\SourceException;
use App\Database\Import\SourceStream;
use App\Database\Processes\ProcessRunner;
use App\Database\Sql\SqlStatement;
use App\Database\Sql\SqlStreamScanner;
use App\Database\Support\ReadOnlyGuard;
use Illuminate\Contracts\Process\InvokedProcess;

class MySQLImporter implements ImporterInterface
{
    /** Warnings kept for the report; the rest are only counted. */
    private const int KEPT_WARNINGS = 5;

    /** The longest error message stored. */
    private const int MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private ProcessRunner $processRunner,
        private MySQLConnection $connection,
        private ReadOnlyGuard $readOnlyGuard,
    ) {}

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
        } catch (SourceException|SqlStatementTooLargeException|UnsupportedDelimiterException $exception) {
            $problems[] = $exception->getMessage();
        }

        return $scan->report($problems, $sqlBytes);
    }

    public function import(ImportRequest $request, SourceStream $source, callable $onProgress): ImportResult
    {
        $this->readOnlyGuard->assertWritable();
        $this->assertServerReady($request);

        $pump = new MySQLImportPump(
            $source,
            $request->sourceDatabase,
            max(1, config()->integer('dbnova.limits.pump_chunk_bytes')),
            config()->integer('dbnova.limits.max_statement_bytes'),
            $request->speedMode,
            $request->trustCompoundPrograms,
        );

        $warningCount = 0;
        $warnings = [];

        try {
            $result = $this->processRunner->stream(
                'mysql',
                $request->credentials,
                $this->arguments($request),
                $pump->chunks(),
                function (InvokedProcess $process) use ($pump, $onProgress, &$warningCount, &$warnings): void {
                    $output = $process->latestOutput();

                    if ($output !== '' && str_contains($output, 'Warning (Code')) {
                        $warningCount += substr_count($output, 'Warning (Code');

                        if (count($warnings) < self::KEPT_WARNINGS && preg_match_all('/^Warning \(Code \d+\): .*$/m', $output, $matches) > 0) {
                            $warnings = array_slice([...$warnings, ...$matches[0]], 0, self::KEPT_WARNINGS);
                        }
                    }

                    $onProgress($pump->sourcePosition(), $pump->bytesSent, $pump->statementsSent);
                },
            );
        } catch (SourceException|SqlStatementTooLargeException|UnsupportedDelimiterException|ImportRefusedException $exception) {
            return new ImportResult(false, $pump->bytesSent, $pump->statementsSent, $pump->skippedStatements, $exception->getMessage());
        }

        if ($result->successful()) {
            return new ImportResult(true, $pump->bytesSent, $pump->statementsSent, $pump->skippedStatements, warningCount: $warningCount, warnings: $warnings);
        }

        [$message, $code, $line] = $this->parseError($result->errorOutput(), $result->exitCode());

        return new ImportResult(false, $pump->bytesSent, $pump->statementsSent, $pump->skippedStatements, $message, $code, $line, $warningCount, $warnings);
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

    /**
     * @return list<string>
     */
    private function arguments(ImportRequest $request): array
    {
        $arguments = [
            '--database='.$request->database,
            '--default-character-set=utf8mb4',
            '--batch',
            '--show-warnings',
            // Piped input may not run client commands (\! shell, source, ...).
            '--binary-mode',
            // A dump may not read files on this host (LOAD DATA LOCAL).
            '--local-infile=0',
            // Never continue in a new session after a lost connection.
            '--skip-reconnect',
            // The server's own max_allowed_packet still applies.
            '--max-allowed-packet=1073741824',
        ];

        if ($request->speedMode) {
            $arguments[] = '--init-command=SET SESSION FOREIGN_KEY_CHECKS = 0, SESSION UNIQUE_CHECKS = 0, SESSION autocommit = 0';
        }

        return $arguments;
    }

    /**
     * Check the credentials and the target before starting the client, and
     * refuse a server whose strings the scanner would read differently.
     *
     * @throws DatabaseOperationException
     */
    private function assertServerReady(ImportRequest $request): void
    {
        $this->connection->useCredentials($request->credentials);
        $sqlMode = (string) $this->connection->scalar('SELECT @@GLOBAL.sql_mode');

        if (stripos($sqlMode, 'NO_BACKSLASH_ESCAPES') !== false) {
            throw new DatabaseOperationException('The server\'s sql_mode includes NO_BACKSLASH_ESCAPES, which changes how dump strings are read. Remove it from the global sql_mode to import.');
        }

        $exists = $this->connection->scalar('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$request->database]);

        if ((int) $exists === 0) {
            throw new DatabaseOperationException('The target database `'.$request->database.'` does not exist (any more).');
        }
    }

    /**
     * The message, MySQL error code and line from the client's stderr,
     * e.g. "ERROR 1064 (42000) at line 9: You have an error ...".
     *
     * @return array{0: string, 1: int|null, 2: int|null}
     */
    private function parseError(string $errorOutput, ?int $exitCode): array
    {
        if (preg_match('/^ERROR(?: (\d+))?(?: \(\w+\))?(?: at line (\d+))?: (.*)$/m', $errorOutput, $matches) === 1) {
            return [
                mb_strimwidth(trim($matches[3]), 0, self::MAX_ERROR_LENGTH, '…'),
                $matches[1] !== '' ? (int) $matches[1] : null,
                $matches[2] !== '' ? (int) $matches[2] : null,
            ];
        }

        $message = trim($errorOutput) !== ''
            ? trim($errorOutput)
            : 'The mysql client stopped with exit code '.($exitCode ?? 'unknown').' and no error message.';

        return [mb_strimwidth($message, 0, self::MAX_ERROR_LENGTH, '…'), null, null];
    }
}
