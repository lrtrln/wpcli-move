<?php

namespace wpclimove;

class Commands extends \WP_CLI_Command
{

    /**
     * @var Config
     */
    private $config_handler;

    /**
     * @var TaskRunner
     */
    private $task_runner;

    /**
     * @var Executor
     */
    private $executor;

    /**
     * Push to a remote environment (files + DB).
     *
     * ## OPTIONS
     *
     * [--themes]
     * : Sync the themes directory.
     *
     * [--plugins]
     * : Sync the plugins directory.
     *
     * [--mu-plugins]
     * : Sync the mu-plugins directory.
     *
     * [--uploads]
     * : Sync the uploads directory.
     *
     * [--languages]
     * : Sync the languages directory.
     *
     * [--wp]
     * : Sync native WordPress core files only (wp-admin, wp-includes and root core files).
     *
     * [--db]
     * : Sync the database.
     *
     * [--all]
     * : Sync the whole WordPress directory and the database.
     *
     * [--delete]
     * : Deletes files on the destination that do not exist on the source. Use with caution.
     *
     * [--force]
     * : Allows --wp or --all to overwrite an existing remote WordPress installation.
     *
     * [--dry-run]
     * : Show the commands that would be run, without actually running them.
     *
     * [<e>]
     * : The environment to push to (e.g., local, staging, prod).
     *
     * ## EXAMPLES
     *
     *     wp move push staging --themes --db
     *
     * @subcommand push
     */
    public function push($args, $assoc_args)
    {
        $env = $args[0] ?? 'staging';
        $this->init_services($assoc_args);

        $conf           = $this->config_handler->get_env_config($env);
        $denied_folders = $conf['not_push'] ?? [];

        $sync_db         = !empty($assoc_args['db']) || !empty($assoc_args['all']);
        $sync_wp         = !empty($assoc_args['wp']) || !empty($assoc_args['all']);
        $sync_all_files  = !empty($assoc_args['all']);
        $folders_to_sync = [];

        $available_folders = [
            'themes'  => 'wp-content/themes',
            'plugins' => 'wp-content/plugins',
            'mu-plugins' => 'wp-content/mu-plugins',
            'uploads' => 'wp-content/uploads',
            'languages' => 'wp-content/languages',
        ];

        if ($sync_all_files) {
            if ($denied_folders) {
                \WP_CLI::warning("⚠️ The '--all' option pushes the whole WordPress directory and ignores the 'not_push' list for the '$env' environment.");
            }
        } else {
            foreach ($available_folders as $key => $path) {
                if (isset($assoc_args[$key])) {
                    if (!in_array($key, $denied_folders, true)) {
                        $folders_to_sync[] = $path;
                    } else {
                        \WP_CLI::warning("⚠️ The '$key' directory is in the 'not_push' list for the '$env' environment. Skipping.");
                    }
                }
            }
        }

        if (!$sync_db && !$sync_wp && empty($folders_to_sync)) {
            \WP_CLI::log("Nothing to push. Please specify a valid component to sync (e.g., --db, --themes, --wp) or check your 'move.yml' configuration.");

            return;
        }

        \WP_CLI::log("Pushing to environment: $env" . ($this->executor->is_dry_run() ? ' (dry run)' : ''));
        $this->task_runner->run_push($env, $folders_to_sync, $sync_db, isset($assoc_args['delete']), $sync_wp, $sync_all_files, isset($assoc_args['force']));

        if ($this->executor->is_dry_run()) {
            \WP_CLI::success("✅ Dry run finished. No changes were made.");
        } else {
            \WP_CLI::success("✅ Push completed.");
        }
    }

    /**
     * Pull from a remote environment (files + DB).
     *
     * ## OPTIONS
     *
     * [--themes]
     * : Sync the themes directory.
     *
     * [--plugins]
     * : Sync the plugins directory.
     *
     * [--mu-plugins]
     * : Sync the mu-plugins directory.
     *
     * [--uploads]
     * : Sync the uploads directory.
     *
     * [--languages]
     * : Sync the languages directory.
     *
     * [--wp]
     * : Sync native WordPress core files only (wp-admin, wp-includes and root core files).
     *
     * [--db]
     * : Sync the database.
     *
     * [--all]
     * : Sync the whole WordPress directory and the database.
     *
     * [--delete]
     * : Deletes files on the destination that do not exist on the source. Use with caution.
     *
     * [--force]
     * : Allows --wp or --all to overwrite an existing local WordPress installation.
     *
     * [--dry-run]
     * : Show the commands that would be run, without actually running them.
     *
     * [<e>]
     * : The environment to pull from (e.g., staging, prod).
     *
     * ## EXAMPLES
     *
     *     wp move pull staging --uploads --db
     *
     * @subcommand pull
     */
    public function pull($args, $assoc_args)
    {
        $env = $args[0] ?? 'staging';
        $this->init_services($assoc_args);

        $sync_db         = !empty($assoc_args['db']) || !empty($assoc_args['all']);
        $sync_wp         = !empty($assoc_args['wp']) || !empty($assoc_args['all']);
        $sync_all_files  = !empty($assoc_args['all']);
        $folders_to_sync = [];

        if (!$sync_all_files) {
            if (isset($assoc_args['themes'])) {
                $folders_to_sync[] = 'wp-content/themes';
            }
            if (isset($assoc_args['plugins'])) {
                $folders_to_sync[] = 'wp-content/plugins';
            }
            if (isset($assoc_args['mu-plugins'])) {
                $folders_to_sync[] = 'wp-content/mu-plugins';
            }
            if (isset($assoc_args['uploads'])) {
                $folders_to_sync[] = 'wp-content/uploads';
            }
            if (isset($assoc_args['languages'])) {
                $folders_to_sync[] = 'wp-content/languages';
            }
        }

        if (!$sync_db && !$sync_wp && empty($folders_to_sync)) {
            \WP_CLI::log("Nothing to pull. Please specify a valid component to sync (e.g., --db, --themes, --wp).");

            return;
        }

        \WP_CLI::log("Pulling from environment: $env" . ($this->executor->is_dry_run() ? ' (dry run)' : ''));
        $this->task_runner->run_pull($env, $folders_to_sync, $sync_db, isset($assoc_args['delete']), $sync_wp, $sync_all_files, isset($assoc_args['force']));

        if ($this->executor->is_dry_run()) {
            \WP_CLI::success("✅ Dry run finished. No changes were made.");
        } else {
            \WP_CLI::success("✅ Pull completed.");
        }
    }

    /**
     * Tests an environment's configuration.
     *
     * ## OPTIONS
     *
     * [<e>]
     * : The environment to test (e.g., local, staging, prod).
     *
     * ## EXAMPLES
     *
     *     wp move test local
     *     wp move test staging
     *
     * @subcommand test
     */
    public function test($args, $assoc_args)
    {
        $env = $args[0] ?? 'local';
        $this->init_services($assoc_args);
        $this->task_runner->run_tests($env);
    }

    /**
     * Creates a database dump of an environment.
     *
     * The dump is saved in the `wp-content/wpcli-move/` directory of the target environment.
     * The `--purge` option allows cleaning this directory.
     *
     * ## OPTIONS
     *
     * [<e>]
     * : The environment to dump (e.g., local, staging, prod). Defaults to 'local'.
     *
     * [--purge]
     * : Deletes all dump files in the `wpcli-move` directory.
     *
     * ## EXAMPLES
     *
     *     wp move dump
     *     wp move dump staging
     *
     * @subcommand dump
     */
    public function dump($args, $assoc_args)
    {
        $env = $args[0] ?? 'local';
        $this->init_services($assoc_args);
        $this->task_runner->run_dump($env, isset($assoc_args['purge']));
    }

    /**
     * Creates a starter move.yml configuration file.
     *
     * ## OPTIONS
     *
     * [<e>]
     * : The remote environment to include (e.g., staging, production). Defaults to 'production'.
     *
     * [--force]
     * : Overwrite an existing move.yml file.
     *
     * ## EXAMPLES
     *
     *     wp move init
     *     wp move init staging
     *
     * @subcommand init
     */
    public function init($args, $assoc_args)
    {
        $config_file = ABSPATH . 'move.yml';
        if (file_exists($config_file) && !isset($assoc_args['force'])) {
            \WP_CLI::error("❌ move.yml already exists. Use --force to overwrite it.");
        }

        $env_file = Config::load_project_dotenv(ABSPATH);
        if (!$env_file) {
            \WP_CLI::error("❌ No readable .env file found near the WordPress root. Checked ABSPATH, its parent directory and the current directory.");
        }

        $env = $args[0] ?? $this->detect_init_env();
        if ('local' === $env) {
            \WP_CLI::error("❌ The generated remote environment cannot be named 'local'.");
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $env)) {
            \WP_CLI::error("❌ Environment name can only contain letters, numbers, underscores and dashes.");
        }

        $prefix  = $this->get_init_env_prefix($env);
        $missing = $this->get_missing_init_env_vars($prefix);
        if ($missing) {
            \WP_CLI::error("❌ Missing or empty .env variables for '$env': " . implode(', ', $missing));
        }

        $content = $this->build_initial_config($env, $prefix);

        if (false === file_put_contents($config_file, $content)) {
            \WP_CLI::error("❌ Unable to write move.yml.");
        }

        \WP_CLI::success("✅ move.yml generated.");
    }

    /**
     * Initializes the necessary services.
     */
    private function init_services($assoc_args)
    {
        $is_dry_run  = isset($assoc_args['dry-run']);
        $config_file = ABSPATH . 'move.yml';

        $this->config_handler = new Config($config_file);
        $this->executor       = new Executor($is_dry_run);
        $this->task_runner    = new TaskRunner($this->config_handler, $this->executor);
    }

    /**
     * @param string $env
     * @param string $prefix
     * @return string
     */
    private function build_initial_config($env, $prefix)
    {
        return <<<YAML
local: {}

{$env}:
  vhost: "\${{$prefix}_VHOST}"
  wordpress_path: "\${{$prefix}_WP_PATH}"
  ssh: "\${{$prefix}_SSH}"
  not_push:
    - uploads
  exclude:
    - ".git"
    - ".DS_Store"
    - "node_modules"

YAML;
    }

    /**
     * @return string
     */
    private function detect_init_env()
    {
        foreach (['staging', 'production'] as $env) {
            $prefix = $this->get_init_env_prefix($env);
            if (!$this->get_missing_init_env_vars($prefix)) {
                return $env;
            }
        }

        \WP_CLI::error("❌ No complete remote environment found in .env. Expected STAGING_VHOST/STAGING_WP_PATH/STAGING_SSH or PRODUCTION_VHOST/PRODUCTION_WP_PATH/PRODUCTION_SSH.");
    }

    /**
     * @param string $env
     * @return string
     */
    private function get_init_env_prefix($env)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $env));
    }

    /**
     * @param string $prefix
     */
    private function get_missing_init_env_vars($prefix)
    {
        $missing = [];

        foreach (["{$prefix}_VHOST", "{$prefix}_WP_PATH", "{$prefix}_SSH"] as $key) {
            $value = Config::env($key);
            if (null === $value || '' === trim($value)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
