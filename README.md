# WP Move CLI

A simplified, modern, and PHP-native replacement for the Ruby tool `Wordmove`, designed to work as a WP-CLI command. This tool helps you synchronize your WordPress site's files and database between different environments (e.g., local, staging, production).

## Critical Warning

WP Move CLI can overwrite files, delete files with rsync, and replace entire WordPress databases. Use it only if you understand exactly which environment is the source and which environment is the destination.

Before running any command against staging or production, make sure you have a recent backup of both files and database for every environment involved. `--dry-run` is strongly recommended before any first run, before `--delete`, before `--all`, and before any database sync.

## Prerequisites
 
Before using WP Move CLI, ensure the following are installed and accessible in your system's `PATH` on both your local and remote machines:
 
- **PHP**: Version **8.2** or higher.
- **WP-CLI**: Version **2.6.0** or higher is recommended.
- **Composer**: For installing PHP dependencies.
- **rsync**: For efficient file synchronization.
- **SSH Client**: For secure remote connections. Password-less login via SSH keys is highly recommended.
- **MySQL Client**: The `mysql` command-line client.
- **MySQL Dump Utility**: `mysqldump` or `mariadb-dump`.

## Installation

1.  Clone this repository into your `wp-content/mu-plugins/` directory.
    ```bash
    git clone <repository_url> wp-content/mu-plugins/wp-move-cli
    ```
2.  Verify the installation by running `wp move`. You should see a list of available commands.

## Configuration

Create a `move.yml` file in the root directory of your WordPress installation (the same level as `wp-config.php`). This file defines your different environments.

You can generate a starter file from your `.env` convention:

```bash
wp move init
wp move init staging
wp move init staging --wpcli-aliases
```

By default, this creates `move.yml` with `local: {}` and one remote environment. If no environment is provided, WP Move CLI uses the first complete remote environment found in `.env` (`STAGING_*`, then `PRODUCTION_*`). Before writing the file, it checks that the matching remote variables exist in `.env` (for example `STAGING_VHOST`, `STAGING_WP_PATH`, and `STAGING_SSH`). If `move.yml` already exists, use `--force` to overwrite it. With `--wpcli-aliases`, WP Move CLI also creates or updates the project `wp-cli.yml` with a matching alias.

### Example `move.yml`

```yaml
local: {}

production:
  vhost: "${PRODUCTION_VHOST}"
  wordpress_path: "${PRODUCTION_WP_PATH}"
  ssh: "${PRODUCTION_SSH}"
  # Folders NOT allowed to be pushed to this environment.
  # By default, 'themes', 'plugins', and 'uploads' are pushable.
  not_push:
    - uploads
  # Files or directories to exclude during rsync
  exclude:
    - ".git"
    - ".DS_Store"
    - "node_modules"
  # Require a specific PHP binary for WP-CLI commands on this host.
  # Useful when the default CLI PHP version is older than 8.2 (common on shared hosts like IONOS).
  php_cli: "/usr/bin/php8.3-cli"
  # Skip wrappers that pin WP-CLI to an older PHP by executing the phar directly.
  wp_cli_path: "/usr/share/php/wp-cli/wp-cli-2.11.0.phar"
```

Values in `move.yml` can reference environment variables with `${VAR_NAME}`. WP Move CLI automatically loads a `.env` file placed next to `move.yml`, in its parent directory, or in the current working directory, without overriding variables already present in the shell environment.

For `local`, WP Move CLI also understands common WordPress `.env` variables:

- `WP_HOME` for `vhost`
- `WP_SITEURL` as a fallback for `vhost`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` for `db`
- `ABSPATH` for `wp_path`

Example `.env`:

```dotenv
WP_ENVIRONMENT_TYPE=development
WP_HOME=http://my-project.local
WP_SITEURL=http://my-project.local
DB_HOST=localhost
DB_NAME=my_project_local_db
DB_USER=root
DB_PASSWORD=password

PRODUCTION_VHOST=https://www.my-project.com
PRODUCTION_WP_PATH=/home/user/public_html
PRODUCTION_SSH=user@your-server.com
```

### WP-CLI aliases

WP Move CLI can also read WP-CLI aliases already declared in `wp-cli.yml`, `wp-cli.local.yml`, or the global WP-CLI config.

You can generate the project alias from `.env`:

```bash
wp move init staging --wpcli-aliases
```

With:

```dotenv
STAGING_VHOST=https://www.my-project.com
STAGING_WP_PATH=/home/user/public_html
STAGING_SSH=user@your-server.com
```

This creates or appends this alias in `wp-cli.yml`:

```yaml
@staging:
  ssh: "user@your-server.com"
  path: "/home/user/public_html"
  url: "https://www.my-project.com"
```

And `move.yml` references it:

```yaml
local: {}

staging:
  alias: "@staging"
  not_push:
    - uploads
  exclude:
    - ".git"
    - ".DS_Store"
    - "node_modules"
```

Create an alias with WP-CLI:

```bash
wp cli alias add @staging --set-ssh=user@your-server.com --set-path=/home/user/public_html --set-url=https://www.my-project.com --config=project
```

Then use it directly:

```bash
wp move push @staging --uploads
wp move pull @staging --db
```

When using a complete WP-CLI alias directly, `move.yml` is optional. Keep `move.yml` when you need WP Move CLI-specific options such as `exclude` or `not_push`.

Or keep a named environment in `move.yml` and point it to the alias:

```yaml
local: {}

staging:
  alias: "@staging"
  exclude:
    - ".git"
    - "node_modules"
  not_push:
    - uploads
```

WP Move CLI reads `ssh`, `path`, and `url` from the alias as `ssh`, `wp_path`, and `vhost`. Alias groups and HTTP aliases are not supported.

## Available Commands

Path keys:
- `wp_path` remains the canonical key for the absolute WordPress path.
- `wordpress_path` is also accepted as an alias and is normalized internally to `wp_path`.

### `wp move push <environment>`
Pushes files and/or the database from your local environment to a remote one.

### `wp move pull <environment>`
Pulls files and/or the database from a remote environment to your local one.

### `wp move test <environment>`
Tests the connection and configuration for a specific environment.

### `wp move dump <environment>`
Creates a database dump for a specific environment.

### `wp move init <environment>`
Creates a starter `move.yml` file after validating the remote environment variables in `.env`. The local section is generated as `local: {}` so WP Move CLI can read `WP_HOME`, `WP_SITEURL`, `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` from `.env`. Add `--wpcli-aliases` to also generate the matching project WP-CLI alias in `wp-cli.yml`.

### Common Options

- `--themes`: Sync the `wp-content/themes` directory.
- `--plugins`: Sync the `wp-content/plugins` directory.
- `--mu-plugins`: Sync the `wp-content/mu-plugins` directory.
- `--uploads`: Sync the `wp-content/uploads` directory.
- `--languages`: Sync the `wp-content/languages` directory.
- `--wp`: Sync native WordPress files (`wp-admin`, `wp-includes`, root core files, and `wp-content/languages` when present; `wp-config.php` is not included). On push, it also creates the remote `wp-content` directory if needed.
- `--db`: Sync the database.
- `--all`: Sync the whole WordPress directory and the database. On push, this ignores `not_push` because it is meant to send everything; `exclude` still applies.
- `--delete`: Deletes files on the destination that do not exist on the source. **Use with caution.**
- `--force`: Allows `--wp` or `--all` to overwrite an existing WordPress installation. It does not allow overwriting a newer destination WordPress version.
- `--yes`: Skip confirmation prompts for destructive operations.
- `--dry-run`: Simulates the operation and shows what changes would be made.
- `--purge`: (Used with `dump` command) Deletes all `.sql` files in the `wp-content/wpcli-move` directory.

### PHP / WP-CLI overrides

- `php_cli`: Path to the PHP binary that should run WP-CLI on remote hosts. If the shared host ships an older default PHP, point this at a higher version (e.g., `/usr/bin/php8.3`).
- `wp_cli_path`: When `/usr/bin/wp` is a wrapper pinned to an outdated PHP (like on IONOS), point this to the actual WP-CLI phar (for example `/usr/share/php/wp-cli/wp-cli-2.11.0.phar`) so the command is executed via `php_cli` instead of the wrapper.

## Usage Examples

**Push the database and uploads to production**
```bash
wp move push production --db --uploads
```

**Push native WordPress files to production**
```bash
wp move push production --wp --force
```

**Push WordPress language files to production**
```bash
wp move push production --languages
```

**Pull native WordPress files from staging**
```bash
wp move pull staging --wp --force
```

**Recover the entire site from staging to local, deleting local files that don't exist on staging**
```bash
wp move pull staging --all --delete --force
```

**Test the production environment configuration without making any changes**
```bash
wp move test production
```

**Create a database dump of the local environment**
```bash
wp move dump local
```

**Delete all database dumps on the production server**
```bash
wp move dump production --purge
```

**Simulate a full push to see what would change**
```bash
wp move push production --all --dry-run
```

When syncing with `--wp` or `--all`, WP Move CLI checks the destination WordPress installation first. If WordPress already exists on the destination, the sync is aborted unless `--force` is provided. If the destination installation is newer than the source, the sync is always aborted.

Destructive operations ask for confirmation unless `--dry-run` or `--yes` is provided. This includes database imports, uploads sync, `--delete`, `--all`, and `--wp --force`.

## Compatibility

This tool is developed and tested on **Linux**. It should work on any POSIX-compliant system where the prerequisites are met. It is not tested on Windows, and compatibility with environments like WSL (Windows Subsystem for Linux) may vary.

# LICENCE

© lrtrln. WP Move CLI is licensed under the GPL-3.0+ license.
