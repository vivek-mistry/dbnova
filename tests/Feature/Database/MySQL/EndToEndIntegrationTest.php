<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\DTOs\Page;
use App\Models\AuditLog;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The whole stack against a real MySQL server, with nothing mocked: login,
 * database explorer, table browser and record editing. Runs when
 * DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    $this->database = 'dbnova_test_e2e_'.bin2hex(random_bytes(3));
    $this->renamed = $this->database.'_renamed';
})->group('mysql');

afterEach(function () {
    if (isset($this->credentials)) {
        $connection = app(MySQLConnection::class)->useCredentials($this->credentials);
        $connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
        $connection->statement("DROP DATABASE IF EXISTS `{$this->renamed}`");
    }
});

test('a MySQL user can log in, manage a database and edit its rows', function () {
    $this->post(route('login.store'), $this->credentials)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $this->post(route('databases.store'), ['name' => $this->database])
        ->assertRedirect(route('databases.show', $this->database));

    app(MySQLConnection::class)->statement("CREATE TABLE `{$this->database}`.`orders` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        notes TEXT NULL
    )");
    app(MySQLConnection::class)->statement("INSERT INTO `{$this->database}`.`orders` (status, notes) VALUES ('new', 'first'), ('paid', NULL)");

    $tableArgs = ['database' => $this->database, 'table' => 'orders'];

    $this->get(route('tables.show', $tableArgs))->assertInertia(fn (Assert $page) => $page
        ->component('tables/Browse')
        ->where('keyColumns', ['id'])
        ->where('firstPage.rows', [[1, 'new', 'first'], [2, 'paid', null]])
    );

    $this->getJson(route('tables.rows', $tableArgs).'?'.http_build_query([
        'filters' => [['column' => 'status', 'operator' => '=', 'value' => 'paid']],
    ]))->assertJsonPath('rows', [[2, 'paid', null]]);

    $this->patchJson(route('tables.records.update', $tableArgs), [
        'key' => Page::encodeCursor([2]),
        'values' => ['notes' => '', 'status' => ' shipped '],
    ])->assertOk()->assertJsonPath('row', [2, ' shipped ', '']);

    $this->postJson(route('tables.records.store', $tableArgs), ['values' => ['notes' => null]])->assertCreated();

    $this->deleteJson(route('tables.records.destroy', $tableArgs), ['keys' => [Page::encodeCursor([1])]])
        ->assertExactJson(['deleted' => 1]);

    $this->getJson(route('tables.rows', $tableArgs))->assertJsonPath('rows', [[2, ' shipped ', ''], [3, 'new', null]]);

    $this->patch(route('databases.update', $this->database), ['name' => $this->renamed])
        ->assertRedirect(route('databases.show', $this->renamed));

    $this->delete(route('databases.destroy', $this->renamed), [
        'confirmation' => $this->renamed,
        'password' => $this->credentials['password'],
    ])->assertRedirect(route('databases.index'));

    $this->get(route('databases.show', $this->renamed))->assertNotFound();

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([
        'database.create',
        'record.update',
        'record.insert',
        'record.delete',
        'database.rename',
        'database.drop',
    ]);
    expect(AuditLog::query()->where('succeeded', false)->count())->toBe(0);
});
