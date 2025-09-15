<?php

return [
    // Directories to be archived as zip files (source directory => zip filename)
    'archiveable_dirs' => [
        // Example :
        // 'app/Http/Controllers' => 'Controllers.zip',
        // 'app/Http/Middleware' => 'Middleware.zip',
        // 'app/Models' => 'Models.zip',
        // 'app/Console' => 'Console.zip',
        // 'app/Imports' => 'Imports.zip',
        // 'resources/views' => 'views.zip',
        // 'public/assets' => 'assets.zip',
    ],
    // Specific single files to include in files.json (relative paths)
    'single_files' => [
        // Example:
        // 'app/Http/Kernel.php',
        // 'app/Providers/AppServiceProvider.php',
        // 'app/app_helpers.php',
        // 'app/Exceptions/Handler.php',
        // 'routes/api.php',
        // 'routes/web.php',
        // 'public/storage/Work_3.jpg',
        // 'config/scribe.php',
    ],
    // File extensions to include in single files
    'file_extensions' => [
        '.php',
        '.jpg',
        '.png',
        '.css',
        '.js',
    ],
    // Files and directories to exclude from the update package
    'exclusions' => [
        '.env',
        'storage/',
        'vendor/',
        'node_modules/',
        '.git/',
        'bootstrap/cache/',
        '*.log',
    ],
    // Tables to truncate for app:make-fresh
    'truncate_tables' => [
        // Example:  'projects',

    ],
    // Database driver for app:make-fresh
    'database_driver' => 'mysql', // Options: mysql, pgsql, sqlite
    // Versioning defaults
    'versioning' => [
        // Add Our New version  'new_version' => '2.0.2',
        // Add Our Previous version  'prev_version' => '2.0.1',
    ],
    // Logging settings
    'logging' => [
        'channel' => 'update-file-generator',
        'file' => 'update-file-generator.log',
    ],
];
