# Update File Generator

A Laravel package for generating **fresh install** and **update packages** for a Laravel application.

The package provides two Artisan commands:

- `app:make-fresh` – Generates a fresh install SQL file (`install.sql`) and renames a view file to `install.blade.php`.
- `app:make-update` – Creates an update zip package with files, migrations, and archives based on a date range or configuration.

---

## ✨ Features

### Fresh Install
- Truncates specified tables (e.g., `projects`, `tasks`) while handling foreign key constraints.
- Exports the database schema to `install.sql` in the project root.
- Renames `resources/views/setup.blade.php` → `resources/views/install.blade.php`.

### Update Package
- Generates a zip file (e.g., `update_from_v2.0.1_to_2.0.2.zip`) with:
  - Metadata files (`package.json`, `updater.json`, `files.json`, `archives.json`, `folders.json`, `query.sql`, `rollback.sql`).
  - Files and archives under `update-files/` (e.g., `Controllers.zip`, `config/scribe.php`).
- Includes:
  - Configured `archiveable_dirs` and `single_files`.
  - Git-changed files (e.g., migrations).
- Supports:
  - Exclusions
  - File extensions
  - Advanced options (`--include-uncommitted`, `--dry-run`, `--rollback`).

---

## ⚙️ Requirements

- **PHP**: ^8.0
- **Laravel**: ^9.0 | ^10.0 | ^11.0
- **Dependencies**: `illuminate/support`, `symfony/process`
- **MySQL & mysqldump** (for `app:make-fresh`)
- **Git** (for `app:make-update`)

---

## 📦 Installation

### 1. Install the Package
```bash
composer require siddharthgor/update-file-generator
````

### 2. Publish the Configuration

```bash
php artisan vendor:publish --tag=config
```

This creates `config/update-file-generator.php`.

### 3. Configure Logging

Add to `config/logging.php`:

```php
'channels' => [
    'update-file-generator' => [
        'driver' => 'single',
        'path' => storage_path('logs/update-file-generator.log'),
        'level' => 'info',
    ],
],
```

### 4. Ensure Dependencies

#### MySQL and mysqldump

* Verify installation:

  ```bash
  mysqldump --version
  ```
* Add `mysqldump.exe` to PATH (Windows example: `C:\xampp\mysql\bin\mysqldump.exe`).

#### Git

* Verify installation:

  ```bash
  git --version
  ```

### 5. Verify Database Connection

Update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 6. Set Permissions (Windows)

```bash
icacls "path\to\project" /grant Everyone:F /T
icacls "path\to\project\storage" /grant Everyone:F /T
icacls "path\to\project\resources\views" /grant Everyone:F /T
```

---

## 🔧 Configuration

Edit `config/update-file-generator.php`:

```php
return [
    'archiveable_dirs' => [
        'app/Http/Controllers' => 'Controllers.zip',
        'app/Http/Middleware' => 'Middleware.zip',
        'app/Models' => 'Models.zip',
        'app/Console' => 'Console.zip',
        'app/Imports' => 'Imports.zip',
        'resources/views' => 'views.zip',
        'public/assets' => 'assets.zip',
    ],
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
    'file_extensions' => ['.php', '.jpg', '.png', '.css', '.js'],
    'exclusions' => ['.env', 'storage/', 'vendor/', 'node_modules/', '.git/', 'bootstrap/cache/', '*.log'],
    'truncate_tables' => ['projects', 'tasks'],
    'database_driver' => 'mysql',
    'versioning' => [
        'new_version' => '2.0.2',
        'prev_version' => '2.0.1',
    ],
    'logging' => [
        'channel' => 'update-file-generator',
        'file' => 'update-file-generator.log',
    ],
];
```

---

## 🚀 Usage

### 1. `app:make-fresh`

Generates `install.sql` and renames `setup.blade.php` → `install.blade.php`.

```bash
php artisan app:make-fresh --output=install.sql
```

**Actions:**

* Copies `setup.blade.php` → `install.blade.php`
* Truncates `projects`, `tasks`
* Exports schema via `mysqldump`
* Resets `.env` to generic settings

**Example Output:**

```
Copied and renamed resources/views/setup.blade.php to resources/views/install.blade.php
Truncated table: projects
Truncated table: tasks
Fresh install SQL generated at install.sql
```

Custom output:

```bash
php artisan app:make-fresh --output=storage/app/install.sql
```

---

### 2. `app:make-update`

Generates an update zip (e.g., `update_from_v2.0.1_to_2.0.2.zip`).

```bash
php artisan app:make-update \
  --from-date=2025-07-01 \
  --to-date=2025-08-01 \
  --output=update_from_v2.0.1_to_2.0.2.zip \
  --new-version=2.0.2 \
  --prev-version=2.0.1 \
  --debug-output
```

**Options:**

* `--from-date`, `--to-date` → Git change range
* `--output` → Custom zip name
* `--include-uncommitted` → Add uncommitted changes
* `--dry-run` → Simulate only
* `--rollback` → Generate rollback package

**Zip Structure:**

```
update_from_v2.0.1_to_2.0.2.zip
├── package.json
├── updater.json
├── files.json
├── archives.json
├── folders.json
├── query.sql
├── rollback.sql
└── update-files/
    ├── app/Http/Controllers/Controllers.zip
    ├── app/Http/Middleware/Middleware.zip
    ├── app/Models/Models.zip
    ├── app/Console/Console.zip
    ├── app/Imports/Imports.zip
    ├── resources/views/views.zip
    ├── public/assets/assets.zip
    ├── database/migrations/<migration_files>
    ├── config/scribe.php
    └── app/Http/Kernel.php
```

Verify:

```bash
unzip -l update_from_v2.0.1_to_2.0.2.zip
```

---

## 🛠 Troubleshooting

### `app:make-fresh`

* **mysqldump Not Found**
  Add MySQL bin folder to PATH.
  Verify:

  ```bash
  mysqldump --version
  ```

* **Foreign Key Constraint Error**
  Add dependent tables to `truncate_tables`.

* **Missing `setup.blade.php`**
  Ensure file exists or update `MakeFreshCommand.php`.

* **Empty install.sql**
  Check `storage/logs/update-file-generator.log`.

---

### `app:make-update`

* **No Files Included**
  Verify Git history:

  ```bash
  git log --since=2025-07-01 --until=2025-08-01 --name-only
  ```

* **Incorrect Zip Structure**
  Ensure metadata files are at root, others under `update-files/`.

* **UpdaterController Issues**
  Verify expected paths and check `storage/logs/update.log`.

---

## 📑 Logs

* **Location**: `storage/logs/update-file-generator.log`
* **Example**:

  ```
  [2025-09-15 13:11:xx] INFO: Copied and renamed resources/views/setup.blade.php to resources/views/install.blade.php
  [2025-09-15 13:11:xx] INFO: Truncated table: projects
  [2025-09-15 13:11:xx] INFO: Fresh install SQL generated at install.sql
  ```

---

## 🤝 Contributing

* Submit issues or PRs to the GitHub repository.
* Contact: **Siddharth Gor** ([infinitietechnologies09@gmail.com](mailto:infinitietechnologies09@gmail.com))

---

## 📜 License

MIT License

```

Do you also want me to make a **shorter README.md version** (like a GitHub landing readme) while keeping this one as a **detailed documentation file**?
```
