<?php

namespace App\Console\Commands;

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Services\System\SystemCheck;
use App\Services\System\SystemCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dbnova:install')]
#[Description('Verify that this machine meets DBNova\'s system requirements')]
class InstallCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SystemCheckService $systemCheckService, MySQLConnector $connector): int
    {
        $checks = $systemCheckService->run();

        $this->table(
            ['Check', 'Status', 'Detail'],
            array_map(fn (SystemCheck $check): array => [$check->name, $check->status->value, $check->detail], $checks),
        );

        if ($systemCheckService->hasFailures($checks)) {
            $this->components->warn('Some system checks failed. Fix them before using DBNova; see the System page for hints.');

            return self::FAILURE;
        }

        $this->components->info("DBNova is ready. Sign in with a MySQL account on {$connector->serverLabel()}.");

        return self::SUCCESS;
    }
}
