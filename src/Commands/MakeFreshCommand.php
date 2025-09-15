<?php

namespace Siddharthgor\UpdateFileGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class MakeFreshCommand extends Command
{
    protected $signature = 'app:make-fresh {--output=install.sql : Output file path}';
    protected $description = 'Generate a fresh install SQL file with cleaned database and add install.blade.php';

    public function handle()
    {
        $output = $this->option('output');
        $tables = config('update-file-generator.truncate_tables', []);

        // Copy and rename setup.blade.php to install.blade.php
        $sourceViewFile = base_path('resources/views/install.blade.php');
        $targetViewFile = base_path('resources/views/install.blade.php');

        if (File::exists($sourceViewFile)) {
            File::copy($sourceViewFile, $targetViewFile);
            $this->info("Copied and renamed resources/views/setup.blade.php to resources/views/install.blade.php");
            Log::channel(config('update-file-generator.logging.channel'))->info("Copied and renamed resources/views/setup.blade.php to resources/views/install.blade.php");
        } else {
            $this->warn("Source file resources/views/setup.blade.php does not exist, skipping.");
            Log::channel(config('update-file-generator.logging.channel'))->warning("Source file resources/views/setup.blade.php does not exist, skipping.");
        }

        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            foreach ($tables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->info("Truncated table: {$table}");
                    Log::channel(config('update-file-generator.logging.channel'))->info("Truncated table: {$table}");
                } else {
                    $this->warn("Table {$table} does not exist, skipping truncation.");
                    Log::channel(config('update-file-generator.logging.channel'))->warning("Table {$table} does not exist, skipping truncation.");
                }
            }
        } catch (\Exception $e) {
            $this->error("Failed to truncate tables: {$e->getMessage()}");
            Log::channel(config('update-file-generator.logging.channel'))->error("Failed to truncate tables: {$e->getMessage()}");
            // Re-enable foreign key checks before exiting
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            return 1;
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->exportDatabase($output);
        $this->resetEnv();
        $this->info("Fresh install SQL generated at {$output}");
        Log::channel(config('update-file-generator.logging.channel'))->info("Fresh install SQL generated at {$output}");
        return 0;
    }

    protected function exportDatabase($output)
    {
        $db = config('database.connections.mysql');
        $command = [
            'mysqldump',
            '-u' . $db['username'],
            '-p' . $db['password'],
            '-h',
            $db['host'],
            $db['database']
        ];
        $process = new Process($command);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error('Database export failed: ' . $process->getErrorOutput());
            Log::channel(config('update-file-generator.logging.channel'))->error('Database export failed: ' . $process->getErrorOutput());
            return;
        }
        File::put($output, $process->getOutput());
    }

    protected function resetEnv()
    {
        $envPath = base_path('.env');
        $envContent = File::get($envPath);
        $genericEnv = preg_replace('/^APP_KEY=.*/m', 'APP_KEY=', $envContent);
        $genericEnv = preg_replace('/^DB_.*/m', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=laravel\nDB_USERNAME=root\nDB_PASSWORD=", $genericEnv);
        File::put($envPath, $genericEnv);
    }
}
