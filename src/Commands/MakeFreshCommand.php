<?php

namespace Siddharthgor\UpdateFileGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class MakeFreshCommand extends Command
{
    protected $signature = 'app:make-fresh {--output=install.sql : Output file path}';
    protected $description = 'Generate a fresh install SQL file with cleaned database';

    public function handle()
    {
        $output = $this->option('output');
        $tables = config('update-file-generator.truncate_tables', []);
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        $this->exportDatabase($output);
        $this->resetEnv();
        $this->info("Fresh install SQL generated at {$output}");
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
