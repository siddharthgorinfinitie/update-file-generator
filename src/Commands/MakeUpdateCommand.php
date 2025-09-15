<?php

namespace Siddharthgor\UpdateFileGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class MakeUpdateCommand extends Command
{
    protected $signature = 'app:make-update {--from-date= : From date (YYYY-MM-DD)} {--to-date= : To date (YYYY-MM-DD)} {--output=update.zip : Output zip file}';
    protected $description = 'Generate update package based on date range';

    public function handle()
    {
        $from = $this->option('from-date');
        $to = $this->option('to-date');
        $output = $this->option('output') ?? "update_{$from}_to_{$to}.zip";

        if (!$from || !$to) {
            $this->error('From and to dates are required.');
            return;
        }

        $changedFiles = $this->getChangedFiles($from, $to);
        $exclusions = config('update-file-generator.exclusions', []);
        $changedFiles = array_filter($changedFiles, fn($file) => !Str::contains($file, $exclusions));

        $migrations = array_filter($changedFiles, fn($file) => Str::startsWith($file, 'database/migrations/'));
        $normalFiles = array_diff($changedFiles, $migrations);
        $newFolders = $this->getNewFolders($changedFiles);

        $this->buildJsonFiles($normalFiles, $migrations, $newFolders);
        $this->createZip($output);

        $this->info("Update package generated at {$output}");
    }

    protected function getChangedFiles($from, $to)
    {
        $process = new Process(['git', 'log', "--since={$from}", "--until={$to}", '--name-only', '--pretty=format:']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error('Git log failed: ' . $process->getErrorOutput());
            return [];
        }
        $output = trim($process->getOutput());
        return array_unique(array_filter(explode("\n", $output)));
    }

    protected function getNewFolders($files)
    {
        $folders = [];
        foreach ($files as $file) {
            $dir = dirname($file);
            if ($dir !== '.' && !in_array($dir, $folders)) {
                $folders[] = $dir;
            }
        }
        return $folders;
    }

    protected function buildJsonFiles($normalFiles, $migrations, $newFolders)
    {
        $tempDir = storage_path('app/update-temp');
        File::makeDirectory($tempDir, 0755, true, true);

        File::put($tempDir . '/files.json', json_encode(array_merge($normalFiles, $migrations), JSON_PRETTY_PRINT));
        File::put($tempDir . '/archives.json', json_encode([], JSON_PRETTY_PRINT));
        File::put($tempDir . '/folders.json', json_encode($newFolders, JSON_PRETTY_PRINT));

        $version = date('Y.m.d');
        $package = [
            'version' => $version,
            'files' => './files.json',
            'archives' => './archives.json',
            'folders' => './folders.json',
            'manual_queries' => true,
            'query_path' => 'query.sql'
        ];
        File::put($tempDir . '/package.json', json_encode($package, JSON_PRETTY_PRINT));

        File::makeDirectory($tempDir . '/update-files', 0755, true);
        foreach (array_merge($normalFiles, $migrations) as $file) {
            if (File::exists(base_path($file))) {
                File::copy(base_path($file), $tempDir . '/update-files/' . $file);
            }
        }

        File::put($tempDir . '/query.sql', '-- Add manual queries here');
    }

    protected function createZip($output)
    {
        $tempDir = storage_path('app/update-temp');
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Failed to create zip file.');
            return;
        }
        $this->addDirToZip($tempDir, $zip, basename($tempDir));
        $zip->close();
        File::deleteDirectory($tempDir);
    }

    protected function addDirToZip($dir, $zip, $relativePath)
    {
        $handler = opendir($dir);
        while (($file = readdir($handler)) !== false) {
            if ($file != '.' && $file != '..') {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $zip->addEmptyDir($relativePath . '/' . $file);
                    $this->addDirToZip($path, $zip, $relativePath . '/' . $file);
                } else {
                    $zip->addFile($path, $relativePath . '/' . $file);
                }
            }
        }
        closedir($handler);
    }
}
