<?php

namespace Siddharthgor\UpdateFileGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class MakeUpdateCommand extends Command
{
    protected $signature = 'app:make-update {--from-date= : From date (YYYY-MM-DD)} {--to-date= : To date (YYYY-MM-DD)} {--output=update.zip : Output zip file} {--new-version= : Custom version (e.g., 2.0.2)} {--prev-version= : Previous version (e.g., 2.0.1)} {--include-uncommitted : Include uncommitted changes} {--debug-output : Enable debug output} {--dry-run : Simulate the update process} {--rollback : Generate a rollback package}';
    protected $description = 'Generate update or rollback package based on date range';

    /**
     * Check if a string matches any of the given patterns or directories.
     *
     * @param string $string
     * @param array $patterns
     * @param bool $caseSensitive
     * @return bool
     */
    protected function matchesAny($string, array $patterns, $caseSensitive = false)
    {
        // Normalize path to use forward slashes
        $string = str_replace('\\', '/', $string);

        foreach ($patterns as $pattern) {
            // Normalize pattern
            $pattern = str_replace('\\', '/', $pattern);

            // Handle directory exclusions (e.g., 'vendor/' matches 'vendor/laravel/...')
            if (Str::endsWith($pattern, '/')) {
                if (Str::startsWith($string, $pattern)) {
                    return true;
                }
            }
            // Handle wildcard patterns (e.g., '*.log')
            elseif (Str::is($pattern, $string, $caseSensitive)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $from = $this->option('from-date');
        $to = $this->option('to-date');
        $output = $this->option('output') ?? "update_{$from}_to_{$to}.zip";
        $newVersion = $this->option('new-version');
        $prevVersion = $this->option('prev-version');
        $debugOutput = $this->option('debug-output');
        $dryRun = $this->option('dry-run');
        $rollback = $this->option('rollback');

        // Validate date range
        if (!$from || !$to) {
            $this->error('From and to dates are required.');
            Log::channel(config('update-file-generator.logging.channel'))->error('From and to dates are required.');
            return 1;
        }

        $today = now()->format('Y-m-d');
        if ($to > $today) {
            $this->warn("To date ({$to}) is in the future. Ensure your Git repository has commits in this range.");
            Log::channel(config('update-file-generator.logging.channel'))->warning("To date ({$to}) is in the future.");
        }

        // Extract versions from filename if not provided
        if (!$newVersion && preg_match('/_v([\d.]+)_to_([\d.]+)\.zip$/', $output, $matches)) {
            $prevVersion = $prevVersion ?? $matches[1];
            $newVersion = $newVersion ?? $matches[2];
        }
        $newVersion = $newVersion ?? config('update-file-generator.versioning.new_version', date('Y.m.d'));
        $prevVersion = $prevVersion ?? config('update-file-generator.versioning.prev_version', 'unknown');

        // Validate versions
        if (!preg_match('/^\d+\.\d+\.\d+$/', $newVersion) || ($prevVersion !== 'unknown' && !preg_match('/^\d+\.\d+\.\d+$/', $prevVersion))) {
            $this->error('Invalid version format. Use semantic versioning (e.g., 2.0.2).');
            Log::channel(config('update-file-generator.logging.channel'))->error('Invalid version format.');
            return 1;
        }

        // Initialize logging
        Log::channel(config('update-file-generator.logging.channel'))->info("Starting update package generation: from {$from} to {$to}, version {$newVersion}");

        // Get changed files from Git for migrations and additional files
        $changedFiles = $this->getChangedFiles($from, $to);
        if ($this->option('include-uncommitted')) {
            $changedFiles = array_merge($changedFiles, $this->getUncommittedFiles());
        }

        // Get all files from archiveable_dirs and single_files
        $allFiles = $this->getAllConfigFiles();
        $changedFiles = array_unique(array_merge($changedFiles, $allFiles));

        if (empty($changedFiles)) {
            $this->warn('No files to include. Check `archiveable_dirs`, `single_files`, and Git history.');
            Log::channel(config('update-file-generator.logging.channel'))->warning('No files to include.');
            return 0;
        }

        $exclusions = config('update-file-generator.exclusions', []);
        $filteredFiles = [];
        foreach ($changedFiles as $file) {
            if ($this->matchesAny($file, $exclusions, true)) {
                $this->logVerbose("Excluding file: {$file}", $debugOutput);
            } else {
                $filteredFiles[] = $file;
                $this->logVerbose("Including file: {$file}", $debugOutput);
            }
        }
        $changedFiles = $filteredFiles;

        if (empty($changedFiles)) {
            $this->warn('All files were excluded. Check `exclusions` in config/update-file-generator.php.');
            Log::channel(config('update-file-generator.logging.channel'))->warning('All files were excluded.');
            return 0;
        }

        if ($dryRun) {
            $this->info('Dry run: Simulating update package generation.');
            $this->logVerbose('Included files: ' . implode(', ', $changedFiles), $debugOutput);
            Log::channel(config('update-file-generator.logging.channel'))->info('Dry run completed.');
            return 0;
        }

        $this->buildUpdateFiles($changedFiles, $newVersion, $prevVersion, $rollback);
        $this->createZip($output);

        $this->info("Update package generated at {$output}");
        Log::channel(config('update-file-generator.logging.channel'))->info("Update package generated: {$output}");
        return 0;
    }

    /**
     * Get changed files from Git log.
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    protected function getChangedFiles($from, $to)
    {
        $process = new Process(['git', 'log', "--since={$from}", "--until={$to}", '--name-only', '--pretty=format:', '--no-merges']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error('Git log failed: ' . $process->getErrorOutput());
            Log::channel(config('update-file-generator.logging.channel'))->error('Git log failed: ' . $process->getErrorOutput());
            return [];
        }
        $output = trim($process->getOutput());
        $files = array_unique(array_filter(explode("\n", $output)));
        // Normalize paths
        return array_map(fn($file) => str_replace('\\', '/', $file), $files);
    }

    /**
     * Get uncommitted files from Git status.
     *
     * @return array
     */
    protected function getUncommittedFiles()
    {
        $process = new Process(['git', 'status', '--porcelain']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->warn('Git status failed: ' . $process->getErrorOutput());
            Log::channel(config('update-file-generator.logging.channel'))->warning('Git status failed: ' . $process->getErrorOutput());
            return [];
        }
        $files = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (preg_match('/^.\s+(.+)$/', $line, $matches)) {
                $files[] = str_replace('\\', '/', $matches[1]);
            }
        }
        return array_unique($files);
    }

    /**
     * Get all files from archiveable_dirs and single_files.
     *
     * @return array
     */
    protected function getAllConfigFiles()
    {
        $files = [];
        $archiveableDirs = config('update-file-generator.archiveable_dirs', []);
        $singleFiles = config('update-file-generator.single_files', []);
        $fileExtensions = config('update-file-generator.file_extensions', []);
        $exclusions = config('update-file-generator.exclusions', []);

        // Collect all files from archiveable_dirs
        foreach ($archiveableDirs as $dir => $zipName) {
            $dirPath = base_path($dir);
            if (File::isDirectory($dirPath)) {
                $dirFiles = File::allFiles($dirPath);
                foreach ($dirFiles as $file) {
                    $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                    $fullPath = $dir . '/' . $relativePath;
                    if (!empty($fileExtensions) && !Str::endsWith($fullPath, $fileExtensions)) {
                        $this->logVerbose("Skipping file: {$fullPath} (invalid extension)", $this->option('debug-output'));
                        continue;
                    }
                    if ($this->matchesAny($fullPath, $exclusions, true)) {
                        $this->logVerbose("Excluding file: {$fullPath}", $this->option('debug-output'));
                        continue;
                    }
                    $files[] = $fullPath;
                }
            }
        }

        // Add single_files
        foreach ($singleFiles as $file) {
            if (File::exists(base_path($file)) && !$this->matchesAny($file, $exclusions, true)) {
                $files[] = $file;
            } else {
                $this->logVerbose("Skipping single file: {$file} (does not exist or excluded)", $this->option('debug-output'));
            }
        }

        return array_unique($files);
    }

    /**
     * Build update files and JSON metadata.
     *
     * @param array $changedFiles
     * @param string $newVersion
     * @param string $prevVersion
     * @param bool $rollback
     */
    protected function buildUpdateFiles($changedFiles, $newVersion, $prevVersion, $rollback)
    {
        $tempDir = storage_path('app/update-temp');
        if (File::exists($tempDir)) {
            File::deleteDirectory($tempDir);
        }
        File::makeDirectory($tempDir, 0755, true, true);

        $archiveableDirs = config('update-file-generator.archiveable_dirs', []);
        $configSingleFiles = config('update-file-generator.single_files', []);
        $fileExtensions = config('update-file-generator.file_extensions', []);
        $exclusions = config('update-file-generator.exclusions', []);
        $migrations = [];
        $singleFiles = [];
        $newFolders = [];
        $archives = [];

        // Categorize files
        foreach ($changedFiles as $file) {
            if (Str::startsWith($file, 'database/migrations/')) {
                $migrations[] = $file;
            } elseif (in_array($file, $configSingleFiles)) {
                $singleFiles["update-files/{$file}"] = $file;
            } else {
                $archived = false;
                foreach ($archiveableDirs as $dir => $zipName) {
                    if (Str::startsWith($file, $dir . '/')) {
                        $archived = true;
                        break;
                    }
                }
                if (!$archived && !empty($fileExtensions) && Str::endsWith($file, $fileExtensions) && !$this->matchesAny($file, $exclusions, true)) {
                    $singleFiles["update-files/{$file}"] = $file;
                }
            }
        }

        // Create archives under update-files/
        File::makeDirectory($tempDir . '/update-files', 0755, true, true);
        foreach ($archiveableDirs as $dir => $zipName) {
            $dirPath = base_path($dir);
            if (File::isDirectory($dirPath)) {
                $zipPath = $tempDir . '/update-files/' . $dir . '/' . $zipName;
                File::makeDirectory(dirname($zipPath), 0755, true, true);
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $filesInDir = File::allFiles($dirPath);
                    foreach ($filesInDir as $file) {
                        $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                        $fullPath = $dir . '/' . $relativePath;
                        if (!empty($fileExtensions) && !Str::endsWith($fullPath, $fileExtensions)) {
                            $this->logVerbose("Skipping file: {$fullPath} (invalid extension)", $this->option('debug-output'));
                            continue;
                        }
                        if ($this->matchesAny($fullPath, $exclusions, true)) {
                            $this->logVerbose("Excluding file: {$fullPath}", $this->option('debug-output'));
                            continue;
                        }
                        $sourcePath = base_path($fullPath);
                        if (File::exists($sourcePath)) {
                            $zip->addFile($sourcePath, $relativePath);
                            $this->logVerbose("Added to archive {$zipName}: {$fullPath}", $this->option('debug-output'));
                        }
                    }
                    $zip->close();
                    $archives["update-files/{$dir}/{$zipName}"] = $dir;
                } else {
                    $this->warn("Failed to create archive: {$zipName}");
                    Log::channel(config('update-file-generator.logging.channel'))->warning("Failed to create archive: {$zipName}");
                }
            }
        }

        // Copy single files and migrations under update-files/
        foreach (array_merge($singleFiles, $migrations) as $zipPath => $file) {
            $sourcePath = base_path($file);
            if (File::exists($sourcePath)) {
                $targetPath = $tempDir . '/' . $zipPath;
                File::makeDirectory(dirname($targetPath), 0755, true, true);
                File::copy($sourcePath, $targetPath);
                $this->logVerbose("Copied file: {$file} to {$zipPath}", $this->option('debug-output'));
            } else {
                unset($singleFiles[$zipPath]);
                $this->warn("Skipping file: {$file} does not exist.");
                Log::channel(config('update-file-generator.logging.channel'))->warning("Skipping file: {$file} does not exist.");
            }
        }

        // Generate new folders
        foreach ($changedFiles as $file) {
            $dir = dirname($file);
            if ($dir !== '.' && !in_array("update-files/{$dir}", $newFolders)) {
                $newFolders[] = "update-files/{$dir}";
            }
        }

        // Generate query.sql and rollback.sql from migrations (placeholder)
        $sql = '';
        $rollbackSql = '';
        foreach ($migrations as $file) {
            $sql .= "-- Migration: {$file}\n";
            $rollbackSql .= "-- Rollback for: {$file}\n";
            // TODO: Parse migration for SQL (requires complex logic)
            $sql .= "-- Add migration SQL here\n";
            $rollbackSql .= "-- Add rollback SQL here\n";
        }

        // Write JSON and SQL files at tempDir root
        File::put($tempDir . '/files.json', json_encode($singleFiles, JSON_PRETTY_PRINT));
        File::put($tempDir . '/archives.json', json_encode($archives, JSON_PRETTY_PRINT));
        File::put($tempDir . '/folders.json', json_encode($newFolders, JSON_PRETTY_PRINT));
        File::put($tempDir . '/query.sql', $sql ?: '-- Add manual queries here');
        File::put($tempDir . '/rollback.sql', $rollbackSql ?: '-- Add rollback queries here');

        // Generate package.json
        $package = [
            'version' => $newVersion,
            'files' => 'files.json',
            'archives' => 'archives.json',
            'folders' => 'folders.json',
            'manual_queries' => true,
            'query_path' => 'query.sql'
        ];
        File::put($tempDir . '/package.json', json_encode($package, JSON_PRETTY_PRINT));

        // Generate updater.json
        $updater = [
            'version' => $newVersion,
            'previous' => $prevVersion,
            'manual_queries' => true,
            'query_path' => 'query.sql'
        ];
        File::put($tempDir . '/updater.json', json_encode($updater, JSON_PRETTY_PRINT));
    }

    /**
     * Create the final zip file with metadata at root and other files under update-files/.
     *
     * @param string $output
     */
    protected function createZip($output)
    {
        $tempDir = storage_path('app/update-temp');
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Failed to create zip file.');
            Log::channel(config('update-file-generator.logging.channel'))->error('Failed to create zip file: ' . $output);
            return;
        }

        // Add metadata files at zip root
        $metadataFiles = ['package.json', 'updater.json', 'files.json', 'archives.json', 'folders.json', 'query.sql', 'rollback.sql'];
        foreach ($metadataFiles as $file) {
            $filePath = $tempDir . '/' . $file;
            if (File::exists($filePath)) {
                $zip->addFile($filePath, $file);
                $this->logVerbose("Added metadata file to zip root: {$file}", $this->option('debug-output'));
            }
        }

        // Add update-files/ directory and its contents
        $updateFilesDir = $tempDir . '/update-files';
        if (File::exists($updateFilesDir)) {
            $this->addDirToZip($updateFilesDir, $zip, 'update-files');
        }

        $zip->close();
        File::deleteDirectory($tempDir);
        $this->logVerbose("Created zip: {$output}", $this->option('debug-output'));
    }

    /**
     * Add directory contents to zip.
     *
     * @param string $dir
     * @param ZipArchive $zip
     * @param string $relativePath
     */
    protected function addDirToZip($dir, $zip, $relativePath)
    {
        $handler = opendir($dir);
        while (($file = readdir($handler)) !== false) {
            if ($file != '.' && $file != '..') {
                $path = $dir . '/' . $file;
                $zipPath = $relativePath ? $relativePath . '/' . $file : $file;
                if (is_dir($path)) {
                    $zip->addEmptyDir($zipPath);
                    $this->addDirToZip($path, $zip, $zipPath);
                } else {
                    $zip->addFile($path, $zipPath);
                }
            }
        }
        closedir($handler);
    }

    /**
     * Log debug messages if enabled.
     *
     * @param string $message
     * @param bool $debugOutput
     */
    protected function logVerbose($message, $debugOutput)
    {
        if ($debugOutput) {
            $this->info($message);
            Log::channel(config('update-file-generator.logging.channel'))->info($message);
        }
    }
}
