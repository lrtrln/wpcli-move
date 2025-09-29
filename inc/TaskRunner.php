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

            $export_cmd = sprintf(
                "ssh %s %s 'mkdir -p %s && %s wp --path=%s db export %s --allow-root'",
                $this->executor->ssh_options, $ssh, escapeshellarg($remote_dump_dir), $dumper_path_env, escapeshellarg($remote_wp_path), escapeshellarg($remote_dump_path)
            );
        } else {
            $dump_dir = WP_CONTENT_DIR . '/wpcli-move';
            if (!is_dir($dump_dir)) {
                mkdir($dump_dir, 0755, true);
            }
            $local_dump_file = $dump_dir . '/' . $dump_filename;
            $export_cmd      = sprintf(
                "%s wp --path=%s db export %s --allow-root",
                $dumper_path_env, escapeshellarg(ABSPATH), escapeshellarg($local_dump_file)
            );
        }

        $result = $this->executor->execute($export_cmd);
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
            // The command deletes all .sql files in the directory, without touching the directory itself.
            $purge_cmd = sprintf(
                "ssh %s %s 'rm -f %s/*.sql'",
                $this->executor->ssh_options, $ssh, escapeshellarg($remote_dump_dir)
            );
        } else {
            $dump_dir  = WP_CONTENT_DIR . '/wpcli-move';
            $purge_cmd = sprintf("rm -f %s/*.sql", escapeshellarg($dump_dir));
        }

        $this->executor->execute($purge_cmd);

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
            $cmd = "ssh {$this->executor->ssh_options} $ssh 'cd {$conf['wp_path']} && wp db check --allow-root'";
            $this->executor->execute($cmd);
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
        $excludeArgs = implode(' ', array_map(fn($ex) => "--exclude={$ex}", $excludes));
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
        $remote_dump_path = rtrim($remote_wp_path, '/') . '/wp-content/wpcli-move/' . basename($local_dump_file);

        // 1. Export DB locale
        \WP_CLI::log("Exporting local database...");
        $dumper_path_env = $this->get_dumper_path_env();
        $result          = $this->executor->execute("{$dumper_path_env}wp --path=" . escapeshellarg(ABSPATH) . " db export {$local_dump_file} --allow-root");

        // Check that the export was successful before continuing.
        // In dry-run mode, the file will not exist, so we only check if it's not a dry-run.
        if (!$this->executor->is_dry_run() && (($result && 0 !== $result->return_code) || !file_exists($local_dump_file))) {
            \WP_CLI::error("❌ Failed to export local database. Dump file could not be created at: {$local_dump_file}. See output above for details.");
        }

        $result = $this->executor->execute($mkdir_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create remote directory for dump: {$remote_dump_dir}. See output above for details.");
        }

        // 2. Transfert
        \WP_CLI::log("Transferring dump file...");
        $scp_cmd = "scp {$this->executor->ssh_options} " . escapeshellarg($local_dump_file) . " " . escapeshellarg("{$ssh}:{$remote_dump_path}");
        $result  = $this->executor->execute($scp_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to transfer dump file to remote server. See output above for details.");
        }

        // 3. Import et Search-Replace distant
        \WP_CLI::log("Importing and running search-replace on remote...");

        $import_cmd = sprintf(
            "ssh %s %s 'wp --path=%s db import %s --allow-root && wp --path=%s search-replace %s %s --all-tables --skip-columns=guid --allow-root'",
            $this->executor->ssh_options,
            $ssh,
            escapeshellarg($remote_wp_path),
            escapeshellarg($remote_dump_path),
            escapeshellarg($remote_wp_path), // The path is also needed for search-replace
            escapeshellarg($local_conf['vhost']),
            escapeshellarg($remote_conf['vhost'])
        );
        $result = $this->executor->execute($import_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("Failed to import database or run search-replace on remote server. See output above for details.");
        }

        // 4. Nettoyage local
        // 4. Local cleanup
        // if ( ! $this->executor->is_dry_run() && file_exists( $local_dump_file ) ) {
        //     unlink( $local_dump_file );
        // }
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

        // Assurer que le répertoire distant existe avant l'exportation
        $remote_dump_dir = dirname($remote_dump_path);
        $mkdir_cmd       = sprintf(
            "ssh %s %s 'mkdir -p %s'",
            $this->executor->ssh_options, $ssh, escapeshellarg($remote_dump_dir)
        );
        $result = $this->executor->execute($mkdir_cmd);
        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create remote directory for dump: {$remote_dump_dir}. See output above for details.");
        }

        $export_cmd = "ssh {$this->executor->ssh_options} {$ssh} '{$dumper_path_env}wp --path=" . escapeshellarg($remote_wp_path) . " db export " . escapeshellarg($remote_dump_path) . " --allow-root'";
        $result     = $this->executor->execute($export_cmd);
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
            escapeshellarg(ABSPATH), escapeshellarg($local_dump_file), escapeshellarg(ABSPATH), escapeshellarg($remote_conf['vhost']), escapeshellarg($local_conf['vhost'])
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
}
