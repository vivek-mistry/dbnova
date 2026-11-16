<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Models\User;

/**
 * Hostile database, table and column names sent to every endpoint that takes
 * a name, against a real MySQL server (nothing mocked). No request may fail
 * with a 500, and a canary database must come through untouched: a quoting
 * bug would show up as a dropped canary or an unexpected database.
 * Runs when DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    actingAsMySqlUser(User::factory()->create(['username' => $this->credentials['username']]), $this->credentials['password']);
    $this->connection = app(MySQLConnection::class)->useCredentials($this->credentials);

    $this->canary = 'dbnova_test_canary_'.bin2hex(random_bytes(3));
    $this->connection->statement("CREATE DATABASE `{$this->canary}`");
    $this->connection->statement("CREATE TABLE `{$this->canary}`.`t` (id INT PRIMARY KEY, name VARCHAR(20))");
    $this->connection->statement("INSERT INTO `{$this->canary}`.`t` VALUES (1, 'a'), (2, 'b'), (3, 'c')");

    $this->databases = fn (): array => array_column($this->connection->select('SHOW DATABASES'), 'Database');
    $this->before = ($this->databases)();
})->group('mysql');

afterEach(function () {
    if (isset($this->connection)) {
        foreach (($this->databases)() as $name) {
            if (! in_array($name, $this->before, true) || $name === $this->canary) {
                $this->connection->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`');
            }
        }
    }
});

/**
 * @return array<string, string>
 */
function hostileNames(string $canary): array
{
    return [
        'backtick break-out' => "t`; DROP DATABASE `{$canary}`; -- ",
        'doubled backticks' => 'a``b',
        'single quote' => "t' OR '1'='1",
        'double quote' => 't" OR "1"="1',
        'NUL byte' => "t\0x",
        'emoji' => 'tbl_😀',
        'right-to-left override' => "t\u{202E}lbat",
        '65 characters' => str_repeat('x', 65),
        'trailing space' => 't ',
        'only spaces' => '   ',
        'dot path' => "{$canary}.t",
        'slash and dots' => '../../etc/passwd',
        'percent and underscore' => 'd%_',
        'backslash' => 't\\',
        'comment opener' => 't/*',
        'newline' => "t\nDROP DATABASE x",
    ];
}

test('hostile names never cause a server error or reach SQL unquoted', function () {
    $canary = $this->canary;
    $statuses = [];
    $json = ['Accept' => 'application/json'];

    $check = function (string $label, $response) use (&$statuses): void {
        $statuses[$label] = $response->status();
    };

    foreach (hostileNames($canary) as $label => $name) {
        // Names in the URL.
        $check("show db {$label}", $this->get(route('databases.show', $name), $json));
        $check("tree {$label}", $this->get(route('database-tree.tables', $name), $json));
        $check("export tables {$label}", $this->get(route('exports.tables', $name), $json));
        $check("browse table {$label}", $this->get(route('tables.show', ['database' => $canary, 'table' => $name]), $json));
        $check("structure {$label}", $this->get(route('tables.structure', ['database' => $canary, 'table' => $name]), $json));
        $check("rows db {$label}", $this->get(route('tables.rows', ['database' => $name, 'table' => 't']), $json));
        $check("count {$label}", $this->get(route('tables.count', ['database' => $canary, 'table' => $name]), $json));

        // Names as column references in the table browser.
        $check("sort {$label}", $this->get(route('tables.rows', ['database' => $canary, 'table' => 't', 'sort' => $name]), $json));
        $check("filter {$label}", $this->get(route('tables.rows', ['database' => $canary, 'table' => 't', 'filters' => [['column' => $name, 'operator' => '=', 'value' => 'a']]]), $json));
        $check("value {$label}", $this->get(route('tables.value', ['database' => $canary, 'table' => 't', 'column' => $name, 'key' => 'x']), $json));

        // Names in request bodies (form-encoded, so invalid UTF-8 and NUL get through intact).
        $check("create db {$label}", $this->post(route('databases.store'), ['name' => 'dbnova_test_'.$name], $json));
        $check("maintenance {$label}", $this->post(route('maintenance.run', $canary), ['operation' => 'check', 'tables' => [$name]], $json));
        $check("export {$label}", $this->post(route('exports.store'), ['database' => $canary, 'tables' => [$name], 'format' => 'sql'], $json));
        $check("schema preview {$label}", $this->post(route('schema.preview', $canary), [
            'table' => $name,
            'change' => ['type' => 'add_column', 'column' => ['name' => $name, 'type' => 'int']],
        ], $json));
        $check("insert {$label}", $this->post(route('tables.records.store', ['database' => $canary, 'table' => 't']), ['values' => [$name => '1']], $json));
    }

    // Invalid UTF-8 can only travel in the URL or a form body.
    $check('show db invalid utf-8', $this->get('/databases/'.rawurlencode("\xff\xfe"), $json));
    $check('create db invalid utf-8', $this->post(route('databases.store'), ['name' => "dbnova_test_\xff"], $json));

    $errors = array_filter($statuses, fn (int $status): bool => $status >= 500);
    expect($errors)->toBe([]);

    // The canary is intact, and nothing outside the test prefix appeared.
    expect($this->connection->scalar("SELECT COUNT(*) FROM `{$canary}`.`t`"))->toBe(3)
        ->and(array_values(array_filter(
            array_diff(($this->databases)(), $this->before),
            fn (string $name): bool => ! str_starts_with($name, 'dbnova_test_'),
        )))->toBe([]);
});
