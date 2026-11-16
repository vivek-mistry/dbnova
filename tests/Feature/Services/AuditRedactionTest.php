<?php

use App\Database\Exceptions\DatabaseOperationException;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;

test('audit errors keep their shape but never quoted SQL or values', function (string $error, string $stored) {
    expect(AuditLogger::redact($error))->toBe($stored);
})->with([
    'syntax error quoting a password' => [
        "MySQL error: You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'IDENTIFED BY 'S3cretPw'' at line 1",
        "MySQL error: You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '…' at line 1",
    ],
    'duplicate row value' => [
        "MySQL error: Duplicate entry 'alice@example.com' for key 'users.email'",
        "MySQL error: Duplicate entry '…' for key '…'",
    ],
    'escaped quote inside a value' => ["Duplicate entry 'O''Brien' for key 'k'", "Duplicate entry '…' for key '…'"],
    'double-quoted value' => ['Incorrect integer value: "x1" for column', 'Incorrect integer value: "…" for column'],
    'no quotes' => ['MySQL error: Lock wait timeout exceeded; try restarting transaction', 'MySQL error: Lock wait timeout exceeded; try restarting transaction'],
    'backticked identifiers are kept' => ['Unknown column `email` in field list', 'Unknown column `email` in field list'],
]);

test('stored audit entries are redacted', function () {
    actingAsMySqlUser();

    app(AuditLogger::class)->record('record.insert', 'shop', 'users', [], new DatabaseOperationException("MySQL error: Duplicate entry 'alice@example.com' for key 'users.email'"));

    expect(AuditLog::query()->sole()->error)->toBe("MySQL error: Duplicate entry '…' for key '…'");
});
