<?php

namespace Siddharthgor\UpdateFileGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class MakeUpdateCommand extends Command
{
    protected $signature = 'app:make-update {--from-date= : From date (YYYY-MM-DD)} {--to-date= : To date (YYYY-MM-DD)} {--output=update.zip : Output zip file} {--version= : Custom version (e.g., 2.0.2)} {--previous-version= : Previous version (e.g., 2.0.1)}';
    protected $description = 'Generate update package based on date range';

    public function handle()
    {
        $from = $this->option('from-date');
        $to = $this->option('to-date');
        $output = $this->option('output') ?? "update_{$from}_to_{$to}.zip";
        $version = $this->option('version') ?? config('update-file-generator.versioning.version', date('Y.m.d'));
        $previousVersion = $this->option('previous-version') ?? config('update-file-generator.versioning.previous_version', 'unknown');

        if (!$from || !$to) {
            $this->error('From and to dates are required.');
            return 1;
        }

        $changedFiles = $this->getChangedFiles($from, $to);
        if (empty($changedFiles)) {
            $this->warn('No files changed in the specified date range.');
            return 0;
        }

        $exclusions = config('update-file-generator.exclusions', []);
        $changedFiles = array_filter($changedFiles, fn($file) => !Str::contains($file, $exclusions));

        $this->buildUpdateFiles($changedFiles, $version, $previousVersion);
        $this->createZip($output);

        $this->info("Update package generated at {$output}");
        return 0;
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

    protected function buildUpdateFiles($changedFiles, $version, $previousVersion)
    {
        $tempDir = storage_path('app/update-temp');
        if (File::exists($tempDir)) {
            File::deleteDirectory($tempDir);
        }
        File::makeDirectory($tempDir, 0755, true, true);

        $archiveableDirs = config('update-file-generator.archiveable_dirs', []);
        $configSingleFiles = config('update-file-generator.single_files', []);
        $migrations = [];
        $singleFiles = [];
        $newFolders = [];
        $archives = [];

        // Categorize files
        foreach ($changedFiles as $file) {
            if (Str::startsWith($file, 'database/migrations/')) {
                $migrations[] = $file;
            } elseif (in_array($file, $configSingleFiles)) {
                $singleFiles[$file] = $file;
            } else {
                $archived = false;
                foreach ($archiveableDirs as $dir => $zipName) {
                    if (Str::startsWith($file, $dir . '/')) {
                        $archived = true;
                        break;
                    }
                }
                if (!$archived) {
                    $singleFiles[$file] = $file;
                }
            }
        }

        // Create archives for directories
        foreach ($archiveableDirs as $dir => $zipName) {
            $filesInDir = array_filter($changedFiles, fn($file) => Str::startsWith($file, $dir . '/'));
            if (!empty($filesInDir)) {
                $zipPath = $tempDir . '/update-files/' . $dir . '/' . $zipName;
                File::makeDirectory(dirname($zipPath), 0755, true, true);
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    foreach ($filesInDir as $file) {
                        $sourcePath = base_path($file);
                        if (File::exists($sourcePath)) {
                            $relativePath = Str::after($file, $dir . '/');
                            $zip->addFile($sourcePath, $relativePath);
                        } else {
                            $this->warn("Skipping file: {$file} does not exist.");
                        }
                    }
                    $zip->close();
                    $archives["update-files/{$dir}/{$zipName}"] = $dir;
                } else {
                    $this->warn("Failed to create archive: {$zipName}");
                }
            }
        }

        // Copy single files and migrations
        File::makeDirectory($tempDir . '/update-files', 0755, true, true);
        foreach (array_merge($singleFiles, $migrations) as $file) {
            $sourcePath = base_path($file);
            if (File::exists($sourcePath)) {
                $targetPath = $tempDir . '/update-files/' . $file;
                File::makeDirectory(dirname($targetPath), 0755, true, true);
                File::copy($sourcePath, $targetPath);
            } else {
                unset($singleFiles[$file]);
                $this->warn("Skipping file: {$file} does not exist.");
            }
        }

        // Generate new folders
        foreach ($changedFiles as $file) {
            $dir = dirname($file);
            if ($dir !== '.' && !in_array($dir, $newFolders)) {
                $newFolders[] = $dir;
            }
        }

        // Write JSON files
        File::put($tempDir . '/files.json', json_encode($singleFiles, JSON_PRETTY_PRINT));
        File::put($tempDir . '/archives.json', json_encode($archives, JSON_PRETTY_PRINT));
        File::put($tempDir . '/folders.json', json_encode($newFolders, JSON_PRETTY_PRINT));

        // Generate package.json
        $package = [
            'version' => $version,
            'files' => './files.json',
            'archives' => './archives.json',
            'folders' => './folders.json',
            'manual_queries' => true,
            'query_path' => 'query.sql'
        ];
        File::put($tempDir . '/package.json', json_encode($package, JSON_PRETTY_PRINT));

        // Generate updater.json
        $updater = [
            'version' => $version,
            'previous' => $previousVersion,
            'manual_queries' => true,
            'query_path' => 'query.sql'
        ];
        File::put($tempDir . '/updater.json', json_encode($updater, JSON_PRETTY_PRINT));

        // Generate query.sql (placeholder)
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
        $this->addDirToZip($tempDir, $zip, 'update-files');
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
