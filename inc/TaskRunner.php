<?php

namespace wpclimove;

class TaskRunner
{
    /**
     * @var mixed
     */
    private $config;
    /**
     * @var mixed
     */
    private $executor;

    /**
     * @var string[] Cache of remote WP-CLI paths keyed by SSH target.
     */
    private $remote_wp_paths = [];

    /**
     * @param Config $config
     * @param Executor $executor
     */
    public function __construct(Config $config, Executor $executor)
    {
        $this->config   = $config;
        $this->executor = $executor;
    }

    /**
     * @param $env
     */
    public function run_tests($env)
    {
        $conf      = $this->config->get_env_config($env);
        $is_remote = isset($conf['ssh']);

        \WP_CLI::log("Testing environment: $env");

        if ($is_remote) {
            $this->test_ssh_connection($conf['ssh']);
        }

        $this->test_vhost($conf['vhost'], $is_remote);
        $this->test_wp_path($conf['wp_path'], $is_remote ? $conf['ssh'] : null, $is_remote);
        $this->test_db_connection($conf, $is_remote ? $conf['ssh'] : null, $is_remote);

        \WP_CLI::success("✅ All tests for environment '$env' passed.");
    }

    /**
     * @param $env
     * @param $folders_to_sync
     * @param $sync_db
     * @param $use_delete
     */
    public function run_push($env, $folders_to_sync, $sync_db, $use_delete)
    {
        $conf = $this->config->get_env_config($env);
        $ssh  = $conf['ssh'] ?? null;

        foreach ($folders_to_sync as $folder) {
            $this->rsync($folder, $ssh, $conf['wp_path'], $conf['exclude'] ?? [], 'push', $use_delete);
        }

        if ($sync_db) {
            $this->db_push($ssh, $conf['wp_path'], $this->config->get_local_config(), $conf);
        }
    }

    /**
     * @param $env
     * @param $folders_to_sync
     * @param $sync_db
     * @param $use_delete
     */
    public function run_pull($env, $folders_to_sync, $sync_db, $use_delete)
    {
        $remote_conf = $this->config->get_env_config($env);
        $ssh         = $remote_conf['ssh'] ?? null;

        if (!$ssh) {
            \WP_CLI::error("❌ The 'pull' command requires an SSH configuration for the '$env' environment.");
        }

        foreach ($folders_to_sync as $folder) {
            $this->rsync($folder, $ssh, $remote_conf['wp_path'], $remote_conf['exclude'] ?? [], 'pull', $use_delete);
        }

        if ($sync_db) {
            $this->db_pull($ssh, $remote_conf['wp_path'], $this->config->get_local_config(), $remote_conf);
        }
    }

    /**
     * @param $env
     * @param $purge
     * @return null
     */
    public function run_dump($env, $purge = false)
    {
        if ($purge) {
            $this->purge_dumps($env);

            return;
        }

        \WP_CLI::log("Creating a dump for environment: $env");
        $conf      = $this->config->get_env_config($env);
        $is_remote = isset($conf['ssh']);
        $ssh       = $conf['ssh'] ?? null;

        $dump_filename   = $env . '_' . date('Ymd_His') . '.sql';
        $dumper_path_env = $this->get_dumper_path_env();

        if ($is_remote) {
            $remote_wp_path   = $conf['wp_path'];
            $remote_dump_path = rtrim($remote_wp_path, '/') . '/wp-content/wpcli-move/' . $dump_filename;
            $remote_dump_dir  = dirname($remote_dump_path);

            $remote_wp_cmd = $this->build_remote_wp_command($conf, $ssh);
            $remote_command = sprintf(
                "mkdir -p %s && %s%s --path=%s db export %s --allow-root",
                escapeshellarg($remote_dump_dir),
                $dumper_path_env,
                $remote_wp_cmd,
                escapeshellarg($remote_wp_path),
                escapeshellarg($remote_dump_path)
            );
            $result = $this->execute_remote_command($ssh, $remote_command);
        } else {
            $dump_dir = WP_CONTENT_DIR . '/wpcli-move';
            if (!is_dir($dump_dir)) {
                mkdir($dump_dir, 0755, true);
            }
            $local_dump_file = $dump_dir . '/' . $dump_filename;
            $export_cmd      = sprintf(
                "%s wp --path=%s db export %s --allow-root",
                $dumper_path_env,
                escapeshellarg(ABSPATH),
                escapeshellarg($local_dump_file)
            );
        }

        if (!$is_remote) {
            $result = $this->executor->execute($export_cmd);
        }

        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create dump for environment '$env'. See output above for details.");
        } else {
            \WP_CLI::success("✅ Dump for environment '$env' created successfully.");
        }
    }

    /**
     * @param $env
     */
    private function purge_dumps($env)
    {
        \WP_CLI::log("Purging dumps for environment: $env");
        $conf      = $this->config->get_env_config($env);
        $is_remote = isset($conf['ssh']);
        $ssh       = $conf['ssh'] ?? null;

        if ($is_remote) {
            $remote_wp_path  = $conf['wp_path'];
            $remote_dump_dir = rtrim($remote_wp_path, '/') . '/wp-content/wpcli-move';
            $purge_cmd       = sprintf("rm -f %s/*.sql", escapeshellarg($remote_dump_dir));
            $this->execute_remote_command($ssh, $purge_cmd);
        } else {
            $dump_dir  = WP_CONTENT_DIR . '/wpcli-move';
            $purge_cmd = sprintf("rm -f %s/*.sql", escapeshellarg($dump_dir));
        }

        if (!$is_remote) {
            $this->executor->execute($purge_cmd);
        }

        \WP_CLI::success("✅ Dump directory for environment '$env' purged.");
    }

    // --- Méthodes de test ---
    // --- Test Methods ---

    /**
     * @param $ssh
     */
    private function test_ssh_connection($ssh)
    {
        \WP_CLI::log("1. Testing SSH connection: $ssh");
        $command = "ssh {$this->executor->ssh_options} -o ConnectTimeout=5 {$ssh} 'echo 1'";
        $result  = $this->executor->execute($command, true);
        if ($result && 0 !== $result->return_code) {
            $error_message = "❌ Failed to connect via SSH to {$ssh}.";
            if (!empty($result->stderr)) {
                $error_message .= "" . $result->stderr;
            }
            \WP_CLI::error($error_message);
        }
        \WP_CLI::success("✅ SSH connection successful.");
    }

    /**
     * @param $vhost
     * @param $is_remote
     */
    private function test_vhost($vhost, $is_remote)
    {
        $step = $is_remote ? '2' : '1';
        \WP_CLI::log("$step. Testing URL (vhost): $vhost");
        $response = wp_remote_get($vhost);
        if (is_wp_error($response)) {
            \WP_CLI::error("❌ Failed to connect to $vhost. Error: " . $response->get_error_message());
        }
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code >= 200 && $http_code < 300) {
            \WP_CLI::success("✅ URL is accessible (Code: $http_code).");
        } else {
            \WP_CLI::error("❌ URL returned an error code: $http_code.");
        }
    }

    /**
     * @param $path
     * @param $ssh
     * @param $is_remote
     */
    private function test_wp_path($path, $ssh, $is_remote)
    {
        $step = $is_remote ? '3' : '2';
        \WP_CLI::log("$step. Testing WordPress path: $path");
        if ($is_remote) {
            $cmd    = "ssh {$this->executor->ssh_options} $ssh 'cd \"$path\" && [ -f \"wp-settings.php\" ] && echo 1 || echo 0'";
            $result = $this->executor->execute($cmd, true);
            if ($result && '1' !== trim($result->stdout)) {
                $error_message = "❌ Remote path '$path' does not exist or is not a WordPress installation.";
                if (!empty($result->stderr)) {
                    $error_message .= "" . $result->stderr;
                }
                \WP_CLI::error($error_message);
            }
            \WP_CLI::success("✅ Remote path is a valid WordPress installation.");
        } else {
            if (is_dir($path) && file_exists($path . '/wp-settings.php')) {
                \WP_CLI::success("✅ Local path is a valid WordPress installation.");
            } else {
                \WP_CLI::error("❌ Local path '$path' does not exist or is not a WordPress installation.");
            }
        }
    }

    /**
     * @param $conf
     * @param $ssh
     * @param $is_remote
     */
    private function test_db_connection($conf, $ssh, $is_remote)
    {
        $step = $is_remote ? '4' : '3';
        \WP_CLI::log("$step. Testing database connection.");
        if ($is_remote) {
            $remote_wp_cmd = $this->build_remote_wp_command($conf, $ssh);
            $remote_command = sprintf(
                "cd %s && %s db check --allow-root",
                escapeshellarg($conf['wp_path']),
                $remote_wp_cmd
            );
            $this->execute_remote_command($ssh, $remote_command);
        } else {
            $db_conf = $conf['db'];
            $mysqli  = @new \mysqli($db_conf['host'] ?? 'localhost', $db_conf['user'], $db_conf['password'], $db_conf['name']);
            if ($mysqli->connect_error) {
                \WP_CLI::error("❌ Failed to connect to local database: " . $mysqli->connect_error);
            }
            \WP_CLI::success("✅ Local database connection successful.");
            $mysqli->close();
        }
    }

    // --- Méthodes de synchronisation ---
    // --- Sync Methods ---

    /**
     * @param $path
     * @param $ssh
     * @param $remote_path
     * @param $excludes
     * @param $direction
     * @param $use_delete
     */
    private function rsync($path, $ssh, $remote_path, $excludes, $direction, $use_delete)
    {
        $excludeArgs = implode(' ', array_map(fn ($ex) => "--exclude={$ex}", $excludes));
        $source      = ('pull' === $direction) ? "$ssh:" . rtrim($remote_path, '/') . '/' . $path . '/' : rtrim(ABSPATH, '/') . '/' . $path . '/';
        $destination = ('pull' === $direction) ? rtrim(ABSPATH, '/') . '/' . $path : "$ssh:" . rtrim($remote_path, '/') . '/' . $path;

        \WP_CLI::log("Sync ($direction) $path");

        if ('pull' === $direction && !is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $rsync_options = '-avz';
        if ($use_delete) {
            $rsync_options .= ' --delete';
        }

        if ($this->executor->is_dry_run()) {
            // -n or --dry-run: performs a trial run with no changes made.
            // -i or --itemize-changes: gives a summary of changes.
            $rsync_options .= ' -ni';
        } else {
            $rsync_options .= ' --progress';
        }

        $cmd = "rsync {$rsync_options} -e 'ssh {$this->executor->ssh_options}' {$excludeArgs} {$source} {$destination}";
        $this->executor->execute($cmd);
    }

    /**
     * @param $ssh
     * @param $remote_wp_path
     * @param $local_conf
     * @param $remote_conf
     */
    private function db_push($ssh, $remote_wp_path, $local_conf, $remote_conf)
    {
        $dump_dir = WP_CONTENT_DIR . '/wpcli-move';
        if (!is_dir($dump_dir)) {
            mkdir($dump_dir, 0755, true);
        }
    
        $local_dump_file  = $dump_dir . '/local_' . date('Ymd_His') . '.sql';
        $processed_dump   = $dump_dir . '/processed_' . date('Ymd_His') . '.sql';
        $remote_dump_path = rtrim($remote_wp_path, '/') . '/wp-content/wpcli-move/' . basename($processed_dump);
        $remote_dump_dir  = dirname($remote_dump_path);

        // 1. Export DB locale
        \WP_CLI::log("Exporting local database...");
        $dumper_path_env = $this->get_dumper_path_env();
        $result = $this->executor->execute("{$dumper_path_env}wp --path=" . escapeshellarg(ABSPATH) . " db export {$local_dump_file} --allow-root");

        if (!$this->executor->is_dry_run() && (($result && 0 !== $result->return_code) || !file_exists($local_dump_file))) {
            \WP_CLI::error("❌ Failed to export local database.");
        }

        // 2. Search-replace EN LOCAL (avant le transfert)
        \WP_CLI::log("Running search-replace locally...");
        $result = $this->executor->execute(
            "{$dumper_path_env}wp --path=" . escapeshellarg(ABSPATH) .
            " search-replace " . escapeshellarg($local_conf['vhost']) .
            " " . escapeshellarg($remote_conf['vhost']) .
            " --export={$processed_dump} --all-tables --skip-columns=guid --allow-root"
        );

        if (!$this->executor->is_dry_run() && (($result && 0 !== $result->return_code) || !file_exists($processed_dump))) {
            \WP_CLI::error("❌ Failed to run search-replace locally.");
        }

        // 3. Créer le répertoire distant
        $mkdir_cmd = sprintf("mkdir -p %s", escapeshellarg($remote_dump_dir));
        $result    = $this->execute_remote_command($ssh, $mkdir_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create remote directory.");
        }

        // 4. Transfert du dump DÉJÀ TRAITÉ
        \WP_CLI::log("Transferring processed dump file...");
        $scp_cmd = "scp {$this->executor->ssh_options} " .
                   escapeshellarg($processed_dump) . " " .
                   escapeshellarg("{$ssh}:{$remote_dump_path}");
        $result = $this->executor->execute($scp_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to transfer dump file.");
        }

        // 5. Import distant (simple, sans search-replace)
        \WP_CLI::log("Importing database on remote server...");
        $remote_wp_cmd  = $this->build_remote_wp_command($remote_conf, $ssh);
        $remote_command = sprintf(
            "%s --path=%s db import %s --allow-root",
            $remote_wp_cmd,
            escapeshellarg($remote_wp_path),
            escapeshellarg($remote_dump_path)
        );
        $result         = $this->execute_remote_command($ssh, $remote_command);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to import database on remote server.");
        }

        $this->ensure_remote_site_urls($ssh, $remote_wp_path, $remote_conf, $remote_wp_cmd);

        // 6. Nettoyage local (optionnel)
        \WP_CLI::success("✅ Database pushed successfully!");
    }
    /**
     * @param $ssh
     * @param $remote_wp_path
     * @param $local_conf
     * @param $remote_conf
     */
    private function db_pull($ssh, $remote_wp_path, $local_conf, $remote_conf)
    {
        $dump_dir = WP_CONTENT_DIR . '/wpcli-move';
        if (!is_dir($dump_dir)) {
            mkdir($dump_dir, 0755, true);
        }
        $remote_dump_file_name = 'wp_move_remote_' . date('Ymd_His') . '.sql';
        $remote_dump_path      = rtrim($remote_wp_path, '/') . '/wp-content/wpcli-move/' . $remote_dump_file_name;
        $local_dump_file       = $dump_dir . '/' . $remote_dump_file_name;

        // 1. Export DB distante
        \WP_CLI::log("Exporting remote database...");
        $dumper_path_env = $this->get_dumper_path_env();
        $remote_wp_cmd   = $this->build_remote_wp_command($remote_conf, $ssh);

        // Assurer que le répertoire distant existe avant l'exportation
        $remote_dump_dir = dirname($remote_dump_path);
        $mkdir_cmd       = sprintf("mkdir -p %s", escapeshellarg($remote_dump_dir));
        $result          = $this->execute_remote_command($ssh, $mkdir_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create remote directory for dump: {$remote_dump_dir}. See output above for details.");
        }

        $remote_command = sprintf(
            "%s%s --path=%s db export %s --allow-root",
            $dumper_path_env,
            $remote_wp_cmd,
            escapeshellarg($remote_wp_path),
            escapeshellarg($remote_dump_path)
        );
        $result = $this->execute_remote_command($ssh, $remote_command);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to export remote database. See output above for details.");
        }

        // 2. Rapatriement
        \WP_CLI::log("Pulling dump file...");
        $scp_cmd = "scp {$this->executor->ssh_options} " . escapeshellarg("{$ssh}:{$remote_dump_path}") . " " . escapeshellarg($local_dump_file);
        $result  = $this->executor->execute($scp_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to pull dump file from remote server. See output above for details.");
        }

        // 3. Import et Search-Replace local
        \WP_CLI::log("Importing and running search-replace locally...");
        $dumper_path_env = $this->get_dumper_path_env(); // Re-get for local
        $import_cmd      = sprintf(
            "{$dumper_path_env}wp --path=%s db import %s --allow-root && {$dumper_path_env}wp --path=%s search-replace %s %s --all-tables --skip-columns=guid --allow-root",
            escapeshellarg(ABSPATH),
            escapeshellarg($local_dump_file),
            escapeshellarg(ABSPATH),
            escapeshellarg($remote_conf['vhost']),
            escapeshellarg($local_conf['vhost'])
        );
        $result = $this->executor->execute($import_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to import database or run search-replace locally. See output above for details.");
        }

        // 4. Cleanup
        // WP_CLI::log( "Nettoyage des fichiers de dump..." );
        // $this->executor->execute( "ssh {$this->executor->ssh_options} {$ssh} 'rm {$remote_dump_path}'" );
        // if ( ! $this->executor->is_dry_run() && file_exists( $local_dump_file ) ) {
        //     unlink( $local_dump_file );
        // }
    }

    /**
     * Finds the path to mysqldump or mariadb-dump and returns the environment variable for WP-CLI.
     *
     * This method first tries to find the binaries in the system's PATH and falls back
     * to a list of common hardcoded paths if the dynamic search fails.
     *
     * @return string
     */
    private function get_dumper_path_env()
    {
        // 1. Try to find binaries dynamically using `command -v`
        $binaries = ['mysqldump', 'mariadb-dump'];
        foreach ($binaries as $binary) {
            // Use shell_exec to run `command -v` which is more reliable than `which`.
            // Redirect stderr to /dev/null to suppress "not found" messages.
            $path = shell_exec("command -v $binary 2>/dev/null");
            if ($path) {
                $path = trim($path); // Remove trailing newline
                if (is_executable($path)) {
                    return 'MYSQLDUMP_PATH=' . escapeshellarg($path) . ' ';
                }
            }
        }

        // 2. Fallback to the original hardcoded paths for specific setups (e.g., MAMP)
        $possible_paths = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/bin/mariadb-dump',
            '/usr/local/bin/mariadb-dump',
            '/Applications/MAMP/Library/bin/mysqldump',
        ];

        foreach ($possible_paths as $path) {
            if (is_executable($path)) {
                return 'MYSQLDUMP_PATH=' . escapeshellarg($path) . ' ';
            }
        }

        // 3. If nothing is found, return empty string and let WP-CLI handle it.

        return '';
    }

    /**
     * Builds the remote WP-CLI invocation, optionally prefixing it with a dedicated PHP binary
     * and bypassing the system wrapper when a phar path is supplied.
     *
     * @param array $env_conf
     * @param string $ssh
     * @return string
     */
    private function build_remote_wp_command(array $env_conf, $ssh)
    {
        if (!empty($env_conf['wp_cli_path'])) {
            $php_cli = $env_conf['php_cli'] ?? 'php';

            return sprintf(
                "%s %s",
                escapeshellarg($php_cli),
                escapeshellarg($env_conf['wp_cli_path'])
            );
        }

        $wp_path = $this->get_remote_wp_path($ssh);
        $php_cli = $env_conf['php_cli'] ?? '';

        if ($php_cli) {
            return sprintf("%s %s", escapeshellarg($php_cli), escapeshellarg($wp_path));
        }

        return escapeshellarg($wp_path);
    }

    /**
     * Determines the path to the remote `wp` binary and caches the result.
     *
     * @param string $ssh
     * @return string
     */
    private function get_remote_wp_path($ssh)
    {
        if (!$ssh) {
            \WP_CLI::error("❌ SSH target is required to run remote WP-CLI commands.");
        }

        if (isset($this->remote_wp_paths[$ssh])) {
            return $this->remote_wp_paths[$ssh];
        }

        $result = $this->execute_remote_command($ssh, 'command -v wp', true);
        if (!$result || 0 !== $result->return_code || '' === trim($result->stdout)) {
            \WP_CLI::error("❌ Unable to locate WP-CLI on remote host '{$ssh}'. Please ensure `wp` is installed and in PATH.");
        }

        return $this->remote_wp_paths[$ssh] = trim($result->stdout);
    }

    /**
     * Executes a remote shell command via SSH with proper escaping.
     *
     * @param string $ssh
     * @param string $remote_command
     * @param bool $capture_stdout
     * @return \WP_CLI\ProcessRun|null
     */
    private function execute_remote_command($ssh, $remote_command, $capture_stdout = false)
    {
        $ssh_cmd = sprintf(
            "ssh %s %s %s",
            $this->executor->ssh_options,
            escapeshellarg($ssh),
            escapeshellarg($remote_command)
        );

        return $this->executor->execute($ssh_cmd, $capture_stdout);
    }

    /**
     * Forces the remote siteurl/home options to match the configured vhost.
     *
     * @param string $ssh
     * @param string $remote_wp_path
     * @param array $remote_conf
     * @param string $remote_wp_cmd
     */
    private function ensure_remote_site_urls($ssh, $remote_wp_path, array $remote_conf, $remote_wp_cmd)
    {
        $remote_vhost = $remote_conf['vhost'] ?? '';
        if (!$remote_vhost) {
            return;
        }

        \WP_CLI::log("Fixing remote siteurl/home to {$remote_vhost}...");

        $escaped_wp_path = escapeshellarg($remote_wp_path);
        $escaped_vhost   = escapeshellarg($remote_vhost);
        $command         = sprintf(
            "%s --path=%s option update home %s --allow-root && %s --path=%s option update siteurl %s --allow-root",
            $remote_wp_cmd,
            $escaped_wp_path,
            $escaped_vhost,
            $remote_wp_cmd,
            $escaped_wp_path,
            $escaped_vhost
        );
        $result = $this->execute_remote_command($ssh, $command);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::warning("⚠️ Échec de la mise à jour de la home/siteurl distante ({$remote_vhost}).");
        }
    }
}
