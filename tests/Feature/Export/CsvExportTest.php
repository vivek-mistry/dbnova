<?php

use App\Database\Adapters\MySQL\MySQLExporter;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ExporterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\Export\CsvExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\ExportDatabaseJob;
use App\Models\AuditLog;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->user = actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');
    $this->directory = sys_get_temp_dir().'/dbnova-csv-'.bin2hex(random_bytes(4));
    config(['dbnova.paths.exports' => $this->directory, 'dbnova.paths.tmp' => $this->directory.'/tmp', 'dbnova.limits.min_free_disk_bytes' => 0]);
    File::ensureDirectoryExists($this->directory);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

describe('starting', function () {
    beforeEach(function () {
        $this->fakes = fakeDatabaseAdapter();
        $this->fakes['schema']->shouldReceive('database')->andReturnUsing(
            fn (string $name) => $name === 'shop' ? new DatabaseInfo('shop', 'utf8mb4', 'utf8mb4_bin', 2, 100, false) : null,
        );
        $this->fakes['schema']->shouldReceive('tables')->with('shop')->andReturn([
            new TableInfo('orders', 'BASE TABLE', 'InnoDB', 3, 16384, 'utf8mb4_bin'),
            new TableInfo('customers', 'BASE TABLE', 'InnoDB', 3, 16384, 'utf8mb4_bin'),
        ]);
        Queue::fake();
    });

    test('a table CSV export needs exactly one table', function (array $tables) {
        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => $tables, 'format' => 'csv'])
            ->assertUnprocessable()->assertJsonValidationErrors('tables');

        Queue::assertNothingPushed();
    })->with([
        'none' => [[]],
        'two' => [['orders', 'customers']],
    ]);

    test('a table CSV export is queued without SQL dump options', function () {
        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => ['orders'], 'format' => 'csv.gz'])
            ->assertCreated()->assertJson(['source' => 'table', 'format' => 'csv.gz', 'tables' => ['orders']]);

        expect(ExportJob::query()->sole())
            ->options->toBe(['spreadsheetSafe' => false])
            ->file_name->toMatch('/^shop-orders-\d{8}-\d{6}\.csv\.gz$/');
    });

    test('spreadsheet-safe mode is an opt-in option on table and query exports', function () {
        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => ['orders'], 'format' => 'csv', 'spreadsheetSafe' => true])->assertCreated();
        $this->postJson(route('exports.query'), ['sql' => 'SELECT 1', 'format' => 'csv', 'spreadsheetSafe' => true])->assertCreated();

        expect(ExportJob::query()->pluck('options')->all())->toBe([['spreadsheetSafe' => true], ['spreadsheetSafe' => true]]);
    });

    test('finished files a user keeps are capped; deleting one makes room', function () {
        config(['dbnova.limits.max_kept_exports' => 2]);
        $kept = ExportJob::factory()->for($this->user)->count(2)->create(['status' => ExportStatus::Completed, 'expires_at' => now()->addHour()]);
        ExportJob::factory()->for($this->user)->create(['status' => ExportStatus::Completed, 'expires_at' => now()->subMinute()]);
        ExportJob::factory()->create(['status' => ExportStatus::Completed, 'expires_at' => now()->addHour()]);

        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => ['orders'], 'format' => 'csv'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'You are keeping 2 finished exports (the limit is 2). Delete ones you have downloaded, then start this export again.');
        $this->postJson(route('exports.query'), ['sql' => 'SELECT 1', 'format' => 'csv'])->assertStatus(429);

        $this->deleteJson(route('exports.destroy', $kept[0]->id))->assertSuccessful();
        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => ['orders'], 'format' => 'csv'])->assertCreated();
    });

    test('a kept-files limit of 0 means no limit', function () {
        config(['dbnova.limits.max_kept_exports' => 0]);
        ExportJob::factory()->for($this->user)->count(3)->create(['status' => ExportStatus::Completed, 'expires_at' => now()->addHour()]);

        $this->postJson(route('exports.store'), ['database' => 'shop', 'tables' => ['orders'], 'format' => 'csv'])->assertCreated();
    });

    test('a SQL editor result is queued as a CSV export of its single read-only statement', function () {
        $this->postJson(route('exports.query'), ['sql' => "  SELECT * FROM orders WHERE note = 'a;b'  ", 'database' => 'shop', 'format' => 'csv'])
            ->assertCreated()->assertJson(['source' => 'query', 'database' => 'shop', 'query' => "SELECT * FROM orders WHERE note = 'a;b'"]);

        $export = ExportJob::query()->sole();
        expect($export->query)->toBe("SELECT * FROM orders WHERE note = 'a;b'")
            ->and($export->credentials)->toBe(['username' => 'root', 'password' => 'Secret@123'])
            ->and($export->file_name)->toMatch('/^shop-query-\d{8}-\d{6}\.csv$/');
        Queue::assertPushedOn('exports', ExportDatabaseJob::class);

        $audit = AuditLog::query()->sole();
        expect($audit->action)->toBe('export.start')
            ->and(json_encode($audit->details))->not->toContain('a;b');
    });

    test('a query export works without a default database', function () {
        $this->postJson(route('exports.query'), ['sql' => 'SHOW DATABASES', 'format' => 'csv'])
            ->assertCreated()->assertJson(['database' => null]);
    });

    test('statements that write, several statements and unknown databases are refused', function (array $payload, string $field) {
        $this->postJson(route('exports.query'), ['format' => 'csv', ...$payload])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        Queue::assertNothingPushed();
    })->with([
        'DELETE' => [['sql' => 'DELETE FROM orders'], 'sql'],
        'UPDATE' => [['sql' => 'UPDATE orders SET total = 0'], 'sql'],
        'two statements' => [['sql' => 'SELECT 1; SELECT 2'], 'sql'],
        'USE' => [['sql' => 'USE shop'], 'sql'],
        'empty' => [['sql' => ' -- nothing '], 'sql'],
        'SQL dump format' => [['sql' => 'SELECT 1', 'format' => 'sql'], 'format'],
        'unknown database' => [['sql' => 'SELECT 1', 'database' => 'nope'], 'database'],
        'DELIMITER smuggling' => [['sql' => "DELIMITER \$\$\nSELECT 1; COMMIT; DELETE FROM orders\$\$"], 'sql'],
        'conditional comment smuggling' => [['sql' => 'SELECT 1 /*!; COMMIT; DELETE FROM orders # */'], 'sql'],
        'FOR SHARE' => [['sql' => 'SELECT * FROM orders FOR SHARE'], 'sql'],
        'FOR UPDATE' => [['sql' => 'SELECT * FROM orders FOR UPDATE'], 'sql'],
        'INTO OUTFILE' => [['sql' => "SELECT * FROM orders INTO OUTFILE '/tmp/x'"], 'sql'],
        'INTO a variable' => [['sql' => 'SELECT 1 INTO @x'], 'sql'],
    ]);

    test('quoted text that merely mentions these words is fine', function () {
        $this->postJson(route('exports.query'), ['sql' => "SELECT 'a; b' AS x, `into` FROM orders WHERE note = 'FOR UPDATE'", 'format' => 'csv'])
            ->assertCreated();
    });

    test('read-only mode still allows query exports', function () {
        config(['dbnova.read_only' => true]);

        $this->postJson(route('exports.query'), ['sql' => 'SELECT 1', 'format' => 'csv'])->assertCreated();
    });

    test('the export page can open a given export, but only the user\'s own', function () {
        $mine = ExportJob::factory()->for($this->user)->create();
        $theirs = ExportJob::factory()->create();

        $this->get(route('exports.index', ['export' => $mine->id, 'database' => 'shop', 'table' => 'orders']))
            ->assertInertia(fn ($page) => $page->where('openExport.id', $mine->id)->where('table', 'orders'));
        $this->get(route('exports.index', ['export' => $theirs->id]))
            ->assertInertia(fn ($page) => $page->where('openExport', null));
    });
});

test('cancelling a running query export also stops the query in MySQL', function () {
    $fakes = fakeDatabaseAdapter();
    $export = ExportJob::factory()->for($this->user)->create(['status' => ExportStatus::Exporting, 'format' => ExportFormat::Csv, 'query' => 'SELECT 1', 'mysql_connection_id' => 4242]);
    $fakes['queries']->shouldReceive('cancel')->once()->with(4242)->andReturnTrue();

    $this->postJson(route('exports.cancel', $export))->assertOk()->assertJson(['cancelRequested' => true]);
});

test('the query text is dropped once the export file is gone', function () {
    $export = ExportJob::factory()->for($this->user)->create([
        'status' => ExportStatus::Completed, 'format' => ExportFormat::Csv, 'query' => "SELECT * FROM users WHERE email = 'a@b.c'", 'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('dbnova:prune-temp')->assertSuccessful();

    expect($export->refresh())->status->toBe(ExportStatus::Expired)->query->toBeNull();
});

test('a completed CSV export downloads as text/csv', function () {
    $export = ExportJob::factory()->for($this->user)->create([
        'status' => ExportStatus::Completed, 'format' => ExportFormat::Csv, 'tables' => ['orders'], 'file_name' => 'shop-orders.csv', 'expires_at' => now()->addDay(),
    ]);
    file_put_contents($export->path(), "\"id\"\r\n1\r\n");

    $this->get(URL::temporarySignedRoute('exports.download', now()->addMinute(), ['export' => $export->id]))
        ->assertOk()->assertDownload('shop-orders.csv')->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

describe('the job', function () {
    beforeEach(function () {
        $this->exporter = Mockery::mock(ExporterInterface::class);
        $this->mock(DatabaseAdapterInterface::class)->shouldReceive('exporter')->andReturn($this->exporter);
        $this->runJob = fn (ExportJob $export) => (new ExportDatabaseJob($export->id))->handle(app(DatabaseAdapterInterface::class));
        $this->queued = fn (array $attributes) => ExportJob::factory()->for($this->user)->create([
            'credentials' => ['username' => 'root', 'password' => 'Secret@123'],
            ...$attributes,
        ]);
    });

    test('a table CSV export passes the table, and records the rows written', function () {
        $export = ($this->queued)(['format' => ExportFormat::CsvGz, 'tables' => ['orders']]);

        $this->exporter->shouldReceive('exportCsv')->once()->withArgs(function (CsvExportRequest $request) use ($export) {
            return $request->table === 'orders' && $request->query === null && $request->database === 'shop'
                && $request->format === ExportFormat::CsvGz && $request->path === $export->path();
        })->andReturn(new ExportResult(true, 100, 40, rowsWritten: 3));

        ($this->runJob)($export);

        expect($export->refresh())->status->toBe(ExportStatus::Completed)->rows_written->toBe(3)->mysql_connection_id->toBeNull();
    });

    test('the spreadsheet-safe option reaches the exporter', function () {
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'tables' => ['orders'], 'options' => ['spreadsheetSafe' => true]]);

        $this->exporter->shouldReceive('exportCsv')->once()
            ->withArgs(fn (CsvExportRequest $request) => $request->spreadsheetSafe)
            ->andReturn(new ExportResult(true, 100, 40, rowsWritten: 3));

        ($this->runJob)($export);
    });

    test('a query export passes the statement and takes a heartbeat lease while MySQL prepares the first row', function () {
        config(['dbnova.export_query_lease_seconds' => 600]);
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'query' => 'SELECT 1', 'database_name' => '']);

        $this->exporter->shouldReceive('exportCsv')->once()->andReturnUsing(function (CsvExportRequest $request, callable $onProgress, callable $onStart) use ($export) {
            expect($request->query)->toBe('SELECT 1')->and($request->table)->toBeNull()->and($request->database)->toBeNull();

            $onStart(77);
            $row = $export->fresh();
            expect($row->mysql_connection_id)->toBe(77)
                ->and($row->heartbeat_at->greaterThan(now()->addSeconds(590)))->toBeTrue();

            return new ExportResult(true, 10, 10, rowsWritten: 1);
        });

        ($this->runJob)($export);

        expect($export->refresh()->status)->toBe(ExportStatus::Completed);
    });

    test('a query stopped by Cancel ends as cancelled, not failed', function () {
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'query' => 'SELECT 1']);
        $this->exporter->shouldReceive('exportCsv')->andReturnUsing(function () use ($export) {
            ExportJob::query()->whereKey($export->id)->update(['cancel_requested' => true]);

            throw new DatabaseOperationException('Query execution was interrupted');
        });

        ($this->runJob)($export);

        expect($export->refresh())->status->toBe(ExportStatus::Cancelled)->last_error->toBeNull();
    });

    test('the heartbeat lease is capped at 4 hours whatever the config says', function () {
        config(['dbnova.export_query_lease_seconds' => 999999]);
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'query' => 'SELECT 1']);

        $this->exporter->shouldReceive('exportCsv')->andReturnUsing(function (CsvExportRequest $request, callable $onProgress, callable $onStart) use ($export) {
            $onStart(5);
            expect($export->fresh()->heartbeat_at->lessThanOrEqualTo(now()->addHours(4)->addSecond()))->toBeTrue();

            // The query returned: the lease ends at once, even within the 2 s progress interval.
            $onProgress(0, 0, 0);
            expect($export->fresh()->heartbeat_at->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();

            return new ExportResult(true, 1, 1, rowsWritten: 0);
        });

        ($this->runJob)($export);
    });

    test('a failed query export keeps SQL fragments out of the audit log', function () {
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'query' => "SELECT secret_token FROM users WHERE email = 'a@b.c'"]);
        $this->exporter->shouldReceive('exportCsv')->andThrow(new DatabaseOperationException("Unknown column 'secret_token' in 'field list'"));

        ($this->runJob)($export);

        expect($export->refresh()->last_error)->toContain('secret_token');
        expect(AuditLog::query()->where('action', 'export.failed')->sole()->error)->toBe('The query export failed.');
    });

    test('a query error without a cancel fails the export with MySQL\'s message', function () {
        $export = ($this->queued)(['format' => ExportFormat::Csv, 'query' => 'SELECT nope']);
        $this->exporter->shouldReceive('exportCsv')->andThrow(new DatabaseOperationException("Unknown column 'nope' in 'field list'"));

        ($this->runJob)($export);

        expect($export->refresh())->status->toBe(ExportStatus::Failed)->last_error->toContain('Unknown column');
    });
});

test('spreadsheet-safe CSV neutralises formulas but keeps numbers and exact mode untouched', function () {
    $row = ['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', "\tTAB", '-12.50', '+44', 'plain', '', null, -5];

    expect(MySQLExporter::csvLine($row, spreadsheetSafe: true))
        ->toBe("\"'=HYPERLINK(\"\"http://x\"\")\",\"'+1+1\",\"'-2+3\",\"'@SUM(A1)\",\"'\tTAB\",\"-12.50\",\"+44\",\"plain\",\"\",,-5\r\n")
        ->and(MySQLExporter::csvLine($row))
        ->toBe("\"=HYPERLINK(\"\"http://x\"\")\",\"+1+1\",\"-2+3\",\"@SUM(A1)\",\"\tTAB\",\"-12.50\",\"+44\",\"plain\",\"\",,-5\r\n");
});
