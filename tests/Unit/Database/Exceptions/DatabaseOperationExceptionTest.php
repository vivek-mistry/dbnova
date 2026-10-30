<?php

use App\Database\Exceptions\DatabaseOperationException;

function pdoException(int $mysqlCode, string $serverMessage): PDOException
{
    $exception = new PDOException("SQLSTATE[HY000]: General error: {$mysqlCode} {$serverMessage}");
    $exception->errorInfo = ['HY000', $mysqlCode, $serverMessage];

    return $exception;
}

test('known MySQL errors get a friendly message followed by the server message', function () {
    $exception = DatabaseOperationException::fromPdoException(pdoException(1007, "Can't create database 'shop'; database exists"));

    expect($exception->getMessage())->toBe("A database with that name already exists. (MySQL: Can't create database 'shop'; database exists)");
    expect($exception->getCode())->toBe(1007);
});

test('permission errors explain that the MySQL account lacks privileges', function () {
    $exception = DatabaseOperationException::fromPdoException(pdoException(1044, "Access denied for user 'reader'@'localhost' to database 'shop'"));

    expect($exception->getMessage())->toStartWith('Your MySQL account does not have permission to do that.');
});

test('unknown MySQL errors show the server message', function () {
    $exception = DatabaseOperationException::fromPdoException(pdoException(1064, 'You have an error in your SQL syntax'));

    expect($exception->getMessage())->toBe('MySQL error: You have an error in your SQL syntax');
});
