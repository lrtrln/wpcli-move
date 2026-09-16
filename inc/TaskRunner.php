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
     * @param $sync_wp
     * @param $sync_all_files
     * @param $force
     */
    public function run_push($env, $folders_to_sync, $sync_db, $use_delete, $sync_wp = false, $sync_all_files = false, $force = false)
    {
        $conf = $this->config->get_env_config($env);
        $ssh  = $conf['ssh'] ?? null;

        if ($sync_wp || $sync_all_files) {
            $this->guard_remote_wordpress_version($ssh, $conf, $force);
        }

        if ($sync_all_files) {
            $this->rsync('', $ssh, $conf['wp_path'], $conf['exclude'] ?? [], 'push', $use_delete);
        } elseif ($sync_wp) {
            $this->ensure_remote_wp_content_dir($ssh, $conf['wp_path']);
            foreach ($this->get_local_wordpress_core_paths() as $path) {
                $this->rsync($path, $ssh, $conf['wp_path'], $conf['exclude'] ?? [], 'push', $use_delete);
            }
        }

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
     * @param $sync_wp
     * @param $sync_all_files
     * @param $force
     */
    public function run_pull($env, $folders_to_sync, $sync_db, $use_delete, $sync_wp = false, $sync_all_files = false, $force = false)
    {
        $remote_conf = $this->config->get_env_config($env);
        $ssh         = $remote_conf['ssh'] ?? null;

        if (!$ssh) {
            \WP_CLI::error("❌ The 'pull' command requires an SSH configuration for the '$env' environment.");
        }

        if ($sync_wp || $sync_all_files) {
            $this->guard_local_wordpress_version($ssh, $remote_conf, $force);
        }

        if ($sync_all_files) {
            $this->rsync('', $ssh, $remote_conf['wp_path'], $remote_conf['exclude'] ?? [], 'pull', $use_delete);
        } elseif ($sync_wp) {
            foreach ($this->get_local_wordpress_core_paths() as $path) {
                $this->rsync($path, $ssh, $remote_conf['wp_path'], $remote_conf['exclude'] ?? [], 'pull', $use_delete);
            }
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

    /**
     * Opens the SSH master connection for an environment when it has an SSH target.
     *
     * @param string $env
     */
    public function warm_ssh_connection($env)
    {
        if ($this->executor->is_dry_run()) {
            return;
        }

        $conf = $this->config->get_env_config($env);
        $ssh  = $conf['ssh'] ?? null;
        if ($ssh) {
            $this->ensure_ssh_master_connection($ssh);
        }
    }

    // --- Méthodes de test ---
    // --- Test Methods ---

    /**
     * @param $ssh
     */
    private function test_ssh_connection($ssh)
    {
        \WP_CLI::log("1. Testing SSH connection: $ssh");
        $this->ensure_ssh_master_connection($ssh);
        \WP_CLI::success("✅ SSH connection successful.");
    }

    /**
     * Opens the SSH ControlMaster connection early so following ssh/scp/rsync calls reuse it.
     * A regular command keeps passphrase prompts attached to the current terminal.
     *
     * @param string $ssh
     */
    private function ensure_ssh_master_connection($ssh)
    {
        \WP_CLI::log("Opening SSH master connection: $ssh");
        $command = "ssh {$this->executor->ssh_options} -o ConnectTimeout=5 " . escapeshellarg($ssh) . " true";
        $result  = $this->executor->execute($command);
        if ($result && 0 !== $result->return_code) {
            $error_message = "❌ Failed to open SSH master connection to {$ssh}.";
            if (!empty($result->stderr)) {
                $error_message .= " " . trim($result->stderr);
            }
            \WP_CLI::error($error_message);
        }
    }

    /**
     * @param $vhost
     * @param $is_remote
     */
    private function test_vhost($vhost, $is_remote)
    {
        $step = $is_remote ? '2' : '1';
        \WP_CLI::log("$step. Testing URL (vhost): $vhost");

        $curl_result = $this->executor->execute("curl -L -sS -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 15 " . escapeshellarg($vhost), true);

        if ($curl_result && 0 === $curl_result->return_code) {
            $http_code = (int) trim($curl_result->stdout);
            if ($http_code >= 200 && $http_code < 300) {
                \WP_CLI::success("✅ URL is accessible (Code: $http_code).");

                return;
            }

            \WP_CLI::error("❌ URL returned an error code: $http_code.");
        }

        if ($curl_result && 127 !== $curl_result->return_code) {
            $error_message = "❌ Failed to connect to $vhost with curl.";
            if (!empty($curl_result->stderr)) {
                $error_message .= " " . trim($curl_result->stderr);
            }
            \WP_CLI::error($error_message);
        }

        \WP_CLI::warning("⚠️ curl is not available. Falling back to the WordPress HTTP API.");

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
            $cmd    = "ssh {$this->executor->ssh_options} $ssh '[ -d " . escapeshellarg($path) . " ] && { [ -f " . escapeshellarg(rtrim($path, '/') . '/wp-settings.php') . " ] && echo 2 || echo 1; } || echo 0'";
            $result = $this->executor->execute($cmd, true);
            if ($result) {
                $status = trim($result->stdout);
                if ('2' === $status) {
                    \WP_CLI::success("✅ Remote path exists and is a valid WordPress installation.");

                    return;
                }

                if ('1' === $status) {
                    \WP_CLI::warning("⚠️ Remote path '$path' exists but is not a WordPress installation.");

                    return;
                }

                $error_message = "❌ Remote path '$path' does not exist.";
                if (!empty($result->stderr)) {
                    $error_message .= "" . $result->stderr;
                }
                \WP_CLI::error($error_message);
            }
        } else {
            if (!is_dir($path)) {
                \WP_CLI::error("❌ Local path '$path' does not exist.");
            }

            if (file_exists($path . '/wp-settings.php')) {
                \WP_CLI::success("✅ Local path exists and is a valid WordPress installation.");

                return;
            }

            \WP_CLI::warning("⚠️ Local path '$path' exists but is not a WordPress installation.");
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
        $local_base  = rtrim(ABSPATH, '/');
        $remote_base = rtrim($remote_path, '/');
        $path        = trim($path, '/');
        $is_root     = '' === $path;
        $is_dir_sync = $is_root || '' === pathinfo($path, PATHINFO_EXTENSION);

        if ('pull' === $direction) {
            $source      = "$ssh:" . $remote_base . '/' . ($is_root ? '' : $path . ($is_dir_sync ? '/' : ''));
            $destination = $local_base . '/' . ($is_root ? '' : $path . ($is_dir_sync ? '' : ''));
        } elseif ($is_root) {
            $source      = $local_base . '/';
            $destination = "$ssh:" . $remote_base . '/';
        } elseif (is_dir($local_base . '/' . $path)) {
            $source      = $local_base . '/' . $path . '/';
            $destination = "$ssh:" . $remote_base . '/' . $path . '/';
        } else {
            $source      = $local_base . '/' . $path;
            $destination = "$ssh:" . $remote_base . '/' . $path;
        }

        \WP_CLI::log("Sync ($direction) " . ($is_root ? 'WordPress root' : $path));

        if ('pull' === $direction && $is_dir_sync && !is_dir($destination)) {
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
     * Creates the remote wp-content directory required by WordPress core.
     *
     * @param string $ssh
     * @param string $remote_wp_path
     */
    private function ensure_remote_wp_content_dir($ssh, $remote_wp_path)
    {
        $wp_content_dir = rtrim($remote_wp_path, '/') . '/wp-content';
        $result         = $this->execute_remote_command($ssh, sprintf('mkdir -p %s', escapeshellarg($wp_content_dir)));

        if ($result && 0 !== $result->return_code) {
            \WP_CLI::error("❌ Failed to create remote wp-content directory.");
        }
    }

    /**
     * Prevents pushing local core files over a newer remote WordPress installation.
     *
     * @param string|null $ssh
     * @param array $remote_conf
     * @param bool $force
     */
    private function guard_remote_wordpress_version($ssh, array $remote_conf, $force)
    {
        if (!$ssh) {
            \WP_CLI::error("❌ The '--wp' and '--all' push modes require an SSH configuration for the remote environment.");
        }

        if ($this->executor->is_dry_run()) {
            \WP_CLI::log("Skipping remote WordPress version guard in dry-run mode.");

            return;
        }

        $remote_wp_path = $remote_conf['wp_path'];
        $settings_file  = rtrim($remote_wp_path, '/') . '/wp-settings.php';
        $result         = $this->execute_remote_command(
            $ssh,
            sprintf("[ -f %s ] && echo 1 || echo 0", escapeshellarg($settings_file)),
            true
        );

        if (!$result || 0 !== $result->return_code) {
            \WP_CLI::error("❌ Unable to inspect the remote WordPress installation before pushing.");
        }

        if ('1' !== trim($result->stdout)) {
            return;
        }

        $local_version  = $this->get_local_wordpress_version();
        $remote_version = $this->get_remote_wordpress_version($ssh, $remote_conf);

        if ($local_version && $remote_version && version_compare($remote_version, $local_version, '>')) {
            \WP_CLI::error("❌ Remote WordPress is newer ({$remote_version}) than local WordPress ({$local_version}). Push aborted.");
        }

        if (!$force) {
            $versions = ($local_version && $remote_version) ? " Remote: {$remote_version}, local: {$local_version}." : '';
            \WP_CLI::error("❌ A WordPress installation already exists on the remote path '{$remote_wp_path}'.{$versions} Add --force to overwrite it.");
        }

        if (!$local_version || !$remote_version) {
            \WP_CLI::warning("⚠️ Unable to compare WordPress versions. Continuing because --force was provided.");

            return;
        }

        \WP_CLI::warning("⚠️ Remote WordPress exists. Continuing with --force: local {$local_version}, remote {$remote_version}.");
    }

    /**
     * Prevents pulling remote core files over a newer local WordPress installation.
     *
     * @param string $ssh
     * @param array $remote_conf
     * @param bool $force
     */
    private function guard_local_wordpress_version($ssh, array $remote_conf, $force)
    {
        if ($this->executor->is_dry_run()) {
            \WP_CLI::log("Skipping local WordPress version guard in dry-run mode.");

            return;
        }

        if (!file_exists(rtrim(ABSPATH, '/') . '/wp-settings.php')) {
            return;
        }

        $local_version  = $this->get_local_wordpress_version();
        $remote_version = $this->get_remote_wordpress_version($ssh, $remote_conf);

        if ($local_version && $remote_version && version_compare($local_version, $remote_version, '>')) {
            \WP_CLI::error("❌ Local WordPress is newer ({$local_version}) than remote WordPress ({$remote_version}). Pull aborted.");
        }

        if (!$force) {
            $versions = ($local_version && $remote_version) ? " Local: {$local_version}, remote: {$remote_version}." : '';
            \WP_CLI::error("❌ A WordPress installation already exists locally at '" . ABSPATH . "'.{$versions} Add --force to overwrite it.");
        }

        if (!$local_version || !$remote_version) {
            \WP_CLI::warning("⚠️ Unable to compare WordPress versions. Continuing because --force was provided.");

            return;
        }

        \WP_CLI::warning("⚠️ Local WordPress exists. Continuing with --force: local {$local_version}, remote {$remote_version}.");
    }

    /**
     * @return string
     */
    private function get_local_wordpress_version()
    {
        $version_file = ABSPATH . WPINC . '/version.php';
        if (!file_exists($version_file)) {
            return '';
        }

        $wp_version = '';
        include $version_file;

        return (string) $wp_version;
    }

    /**
     * @param string $ssh
     * @param array $remote_conf
     * @return string
     */
    private function get_remote_wordpress_version($ssh, array $remote_conf)
    {
        $remote_wp_path = rtrim($remote_conf['wp_path'], '/');
        $version_file   = $remote_wp_path . '/wp-includes/version.php';
        $php_cli        = !empty($remote_conf['php_cli']) ? escapeshellarg($remote_conf['php_cli']) : 'php';
        $php_code       = '$wp_version = ""; include ' . var_export($version_file, true) . '; echo $wp_version;';
        $command        = sprintf(
            "[ -f %s ] && %s -r %s || true",
            escapeshellarg($version_file),
            $php_cli,
            escapeshellarg($php_code)
        );

        $result = $this->execute_remote_command($ssh, $command, true);
        if (!$result || 0 !== $result->return_code) {
            return '';
        }

        return trim($result->stdout);
    }

    /**
     * @return string[]
     */
    private function get_local_wordpress_core_paths()
    {
        $paths = ['wp-admin', 'wp-includes'];
        if (is_dir(rtrim(ABSPATH, '/') . '/wp-content/languages')) {
            $paths[] = 'wp-content/languages';
        }

        $files = [
            'index.php',
            'license.txt',
            'readme.html',
            'wp-activate.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config-sample.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
        ];

        foreach ($files as $file) {
            if (file_exists(rtrim(ABSPATH, '/') . '/' . $file)) {
                $paths[] = $file;
            }
        }

        return $paths;
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
            $wp_cli_path = trim($env_conf['wp_cli_path']);

            $result = $this->execute_remote_command($ssh, '[ -f ' . escapeshellarg($wp_cli_path) . ' ]', true);
            if ($result && 0 === $result->return_code) {
                return sprintf(
                    "%s %s",
                    escapeshellarg($php_cli),
                    escapeshellarg($wp_cli_path)
                );
            }

            \WP_CLI::warning("⚠️ Remote WP-CLI path '{$wp_cli_path}' was not found. Falling back to automatic detection.");
        }

        $php_cli = $env_conf['php_cli'] ?? '';
        $wp_path = $this->get_remote_wp_path($ssh, (bool) $php_cli);

        if ($php_cli) {
            return sprintf("%s %s", escapeshellarg($php_cli), escapeshellarg($wp_path));
        }

        return escapeshellarg($wp_path);
    }

    /**
     * Determines the path to the remote WP-CLI binary and caches the result.
     *
     * @param string $ssh
     * @param bool $prefer_phar
     * @return string
     */
    private function get_remote_wp_path($ssh, $prefer_phar = false)
    {
        if (!$ssh) {
            \WP_CLI::error("❌ SSH target is required to run remote WP-CLI commands.");
        }

        $cache_key = $ssh . ($prefer_phar ? '|phar' : '|binary');
        if (isset($this->remote_wp_paths[$cache_key])) {
            return $this->remote_wp_paths[$cache_key];
        }

        $lookup_command = $prefer_phar
            ? 'for file in /usr/share/php/wp-cli/wp-cli.phar /usr/share/php/wp-cli/wp-cli-*.phar /usr/local/bin/wp-cli.phar /usr/bin/wp-cli.phar; do [ -f "$file" ] && { printf "%s\n" "$file"; exit 0; }; done; command -v wp || command -v wpcli || command -v wp-cli'
            : 'command -v wp || command -v wpcli || command -v wp-cli';

        $result = $this->execute_remote_command($ssh, $lookup_command, true);
        if (!$result || 0 !== $result->return_code || '' === trim($result->stdout)) {
            \WP_CLI::error("❌ Unable to locate WP-CLI on remote host '{$ssh}'. Please ensure `wp`, `wpcli`, or `wp-cli` is installed and in PATH.");
        }

        return $this->remote_wp_paths[$cache_key] = trim($result->stdout);
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
