Update File Generator
A Laravel package to generate fresh install and date-based update packages.
Installation
composer require siddharthgor/update-file-generator
php artisan vendor:publish --tag=config

Usage

Fresh Install: Generate a clean database SQL file with truncated tables and generic .env:php artisan app:make-fresh --output=fresh_install.sql


Update Package: Generate a zip file with changed files between two dates:php artisan app:make-update --from-date=2025-08-01 --to-date=2025-09-15 --output=update.zip



Configuration
Edit config/update-file-generator.php to customize:

truncate_tables: Tables to truncate for fresh installs (e.g., ['projects', 'tasks']).
exclusions: Files/directories to exclude from updates (e.g., ['.env', 'storage/']).

Requirements

PHP 8.0 or higher
Laravel 9.0, 10.0, or 11.0
Git repository initialized in the Laravel project for app:make-update
MySQL for app:make-fresh (adjust for other databases if needed)
