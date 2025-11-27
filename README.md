# WP Move CLI

A simplified, modern, and PHP-native replacement for the Ruby tool `Wordmove`, designed to work as a WP-CLI command. This tool helps you synchronize your WordPress site's files and database between different environments (e.g., local, staging, production).

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

### Example `move.yml`

```yaml
local:
  vhost: "http://my-project.local"
  wp_path: "/var/www/my-project/wp"
  db:
    name: "my_project_local_db"
    user: "root"
    password: "password"
    host: "localhost"

production:
  vhost: "https://www.my-project.com"
  wp_path: "/home/user/public_html"
  ssh: "user@your-server.com"
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

## Available Commands

### `wp move push <environment>`
Pushes files and/or the database from your local environment to a remote one.

### `wp move pull <environment>`
Pulls files and/or the database from a remote environment to your local one.

### `wp move test <environment>`
Tests the connection and configuration for a specific environment.

### `wp move dump <environment>`
Creates a database dump for a specific environment.

### Common Options

- `--themes`: Sync the `wp-content/themes` directory.
- `--plugins`: Sync the `wp-content/plugins` directory.
- `--mu-plugins`: Sync the `wp-content/mu-plugins` directory.
- `--uploads`: Sync the `wp-content/uploads` directory.
- `--db`: Sync the database.
- `--all`: A shortcut to sync all configured directories and the database.
- `--delete`: Deletes files on the destination that do not exist on the source. **Use with caution.**
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

**Pull the entire site from staging to local, deleting local files that don't exist on staging**
```bash
wp move pull staging --all --delete
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

## Compatibility

This tool is developed and tested on **Linux** and **macOS**. It should work on any POSIX-compliant system where the prerequisites are met. It is not tested on Windows, and compatibility with environments like WSL (Windows Subsystem for Linux) may vary.

# LICENCE

© lrtrln. WP Move CLI is licensed under the GPL-3.0+ license.
