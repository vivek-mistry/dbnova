<?php

namespace App\Database\Adapters\MySQL;

use App\Database\DTOs\Import\PreflightReport;
use App\Database\Sql\SqlStatement;

/**
 * Accumulates what preflight learns from each statement of a dump.
 */
final class MySQLPreflightScan
{
    private const int MAX_LISTED_TABLES = 1000;

    /** Statements longer than this are data (INSERTs); option checks only look at shorter ones. */
    private const int SMALL_STATEMENT_BYTES = 8192;

    public int $statementCount = 0;

    /** @var array<string, int> Database name => first line that names it in USE / CREATE / DROP / ALTER DATABASE. */
    private array $databaseStatements = [];

    private int $databaseStatementCount = 0;

    /** The first statement the import policy refuses, with its line. */
    private ?string $firstViolation = null;

    private int $violationCount = 0;

    /** @var list<string> */
    private array $tables = [];

    private int $tableCount = 0;

    private int $storedObjectCount = 0;

    private bool $hasDefiners = false;

    private bool $disablesForeignKeyChecks = false;

    private bool $setsGtidPurged = false;

    private int $largestStatementBytes = 0;

    public function inspect(SqlStatement $statement): void
    {
        $this->statementCount++;
        $size = strlen($statement->sql);
        $this->largestStatementBytes = max($this->largestStatementBytes, $size);

        $violation = MySQLDumpPolicy::violation($statement);

        if ($violation !== null) {
            $this->violationCount++;
            $this->firstViolation ??= sprintf('Line %d: %s', $statement->line, $violation);
        }

        $reference = MySQLDumpStatement::databaseStatement($statement);

        if ($reference !== null) {
            $this->databaseStatements[$reference['name']] ??= $statement->line;
            $this->databaseStatementCount++;

            return;
        }

        $table = MySQLDumpStatement::createdTable($statement);

        if ($table !== null) {
            $this->tableCount++;

            if (count($this->tables) < self::MAX_LISTED_TABLES) {
                $this->tables[] = $table;
            }

            return;
        }

        if (MySQLDumpStatement::createsStoredObject($statement)) {
            $this->storedObjectCount++;
            $this->hasDefiners = $this->hasDefiners || stripos($statement->sql, 'DEFINER') !== false;

            return;
        }

        if ($size > self::SMALL_STATEMENT_BYTES) {
            return;
        }

        $keyword = $statement->keyword();

        if ($keyword === 'SET') {
            $this->disablesForeignKeyChecks = $this->disablesForeignKeyChecks || preg_match('/FOREIGN_KEY_CHECKS\s*=\s*0/i', $statement->sql) === 1;
            $this->setsGtidPurged = $this->setsGtidPurged || stripos($statement->sql, 'GTID_PURGED') !== false;
        }
    }

    /**
     * @param  list<string>  $problems  Problems found while reading the file.
     */
    public function report(array $problems, int $sqlBytes): PreflightReport
    {
        $names = array_map('strval', array_keys($this->databaseStatements));
        sort($names);

        if (count($names) > 1) {
            $problems[] = sprintf(
                'The dump uses %d databases (%s). DBNova imports into one database at a time: export each database separately.',
                count($names),
                implode(', ', array_map(fn (string $name): string => '`'.$name.'`', array_slice($names, 0, 10))).(count($names) > 10 ? ', …' : ''),
            );
        }

        if ($this->firstViolation !== null) {
            $problems[] = $this->firstViolation.($this->violationCount > 1 ? sprintf(' (%d statements in the file have problems like this.)', $this->violationCount) : '');
        }

        if ($this->statementCount === 0 && $problems === []) {
            $problems[] = 'No SQL statements were found in the file.';
        }

        $warnings = [];

        if ($this->hasDefiners) {
            $warnings[] = 'The dump creates views, triggers or routines with DEFINER clauses. MySQL refuses these unless the definer is your account or you have the SET_USER_ID (or SUPER) privilege.';
        }

        if ($this->setsGtidPurged) {
            $warnings[] = 'The dump sets GTID_PURGED, which needs administrator privileges and usually fails. Re-export with --set-gtid-purged=OFF.';
        }

        $singleName = count($names) === 1 ? $names[0] : null;

        return new PreflightReport(
            problems: $problems,
            warnings: $warnings,
            databases: $names,
            sourceDatabase: $singleName,
            statementCount: $this->statementCount,
            tables: $this->tables,
            tableCount: $this->tableCount,
            skippedStatements: $singleName === null ? 0 : $this->databaseStatementCount,
            storedObjectCount: $this->storedObjectCount,
            disablesForeignKeyChecks: $this->disablesForeignKeyChecks,
            largestStatementBytes: $this->largestStatementBytes,
            sqlBytes: $sqlBytes,
        );
    }
}
