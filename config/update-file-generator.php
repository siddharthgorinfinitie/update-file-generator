<?php

return [
    // Directories to be archived as zip files (destination zip => target directory)
    'archiveable_dirs' => [
        'app/Http/Controllers' => 'Controllers.zip',
        'app/Http/Middleware' => 'Middleware.zip',
        'app/Models' => 'Models.zip',
        'app/Console' => 'Console.zip',
        'app/Imports' => 'Imports.zip',
        'resources/views' => 'views.zip',
        'public/assets' => 'assets.zip',
    ],
    // Specific single files to include in files.json (relative paths)
    'single_files' => [
        'app/Http/Kernel.php',
        'app/Providers/AppServiceProvider.php',
        'app/app_helpers.php',
        'app/Exceptions/Handler.php',
        'routes/api.php',
        'routes/web.php',
        'public/storage/Work_3.jpg',
        'config/scribe.php',
    ],
    // Files and directories to exclude from the update package
    'exclusions' => [
        '.env',
        'storage/',
        'vendor/',
        'node_modules/',
        '.git/',
        'bootstrap/cache/',
    ],
    // Tables to truncate for app:make-fresh
    'truncate_tables' => [
        // e.g., 'projects', 'tasks'
    ],
    // Versioning defaults (overridden by command options)
    'versioning' => [
        'version' => '2.0.2', // Default new version
        'previous_version' => '2.0.1', // Default previous version
    ],
];
