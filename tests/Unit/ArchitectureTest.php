<?php

arch('the managed-server layer never uses Laravel database connections')
    ->expect('App\Database')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Query\Builder',
        'Illuminate\Database\Connection',
    ]);

arch('controllers depend on adapter contracts, not on a specific engine')
    ->expect('App\Http')
    ->not->toUse('App\Database\Adapters');

arch('services depend on adapter contracts, not on a specific engine')
    ->expect('App\Services')
    ->not->toUse('App\Database\Adapters')
    ->ignoring('App\Database\Adapters\MySQL\MySQLConnector');
