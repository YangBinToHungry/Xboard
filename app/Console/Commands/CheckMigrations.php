<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckMigrations extends Command
{
    protected $signature = 'xboard:check-migrations';
    protected $description = 'Refuse application startup when database migrations are pending';

    public function handle(): int
    {
        $migrator = app('migrator');
        if (!$migrator->repositoryExists()) {
            $this->error('Migration repository is missing. Run xboard:install or xboard:update first.');
            return self::FAILURE;
        }
        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $pending = array_diff(array_keys($migrator->getMigrationFiles($paths)), $migrator->getRepository()->getRan());
        if ($pending) {
            $this->error('Pending migrations: ' . implode(', ', $pending));
            $this->error('Back up the database and run php artisan xboard:update before starting services.');
            return self::FAILURE;
        }
        $this->info('Database migration state is current.');
        return self::SUCCESS;
    }
}
