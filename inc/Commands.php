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
     * [--db]
     * : Sync the database.
     *
     * [--all]
     * : A shortcut to sync all allowed directories and the database.
     *
     * [--delete]
     * : Deletes files on the destination that do not exist on the source. Use with caution.
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
        $folders_to_sync = [];

        $available_folders = [
            'themes'  => 'wp-content/themes',
            'plugins' => 'wp-content/plugins',
            'mu-plugins' => 'wp-content/mu-plugins',
            'uploads' => 'wp-content/uploads',
        ];

        if (isset($assoc_args['all'])) {
            foreach ($available_folders as $key => $path) {
                if (!in_array($key, $denied_folders, true)) {
                    $folders_to_sync[] = $path;
                }
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

        if (!$sync_db && empty($folders_to_sync)) {
            \WP_CLI::log("Nothing to push. Please specify a valid component to sync (e.g., --db, --themes) or check your 'move.yml' configuration.");

            return;
        }

        \WP_CLI::log("Pushing to environment: $env" . ($this->executor->is_dry_run() ? ' (dry run)' : ''));
        $this->task_runner->run_push($env, $folders_to_sync, $sync_db, isset($assoc_args['delete']));

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
     * [--db]
     * : Sync the database.
     *
     * [--all]
     * : A shortcut to sync all directories and the database.
     *
     * [--delete]
     * : Deletes files on the destination that do not exist on the source. Use with caution.
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

        $sync_db         = isset($assoc_args['db']);
        $folders_to_sync = [];

        if (isset($assoc_args['all'])) {
            $sync_db = true;
            // For a pull, we sync the most common directories by default.
            $folders_to_sync = ['wp-content/themes', 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/uploads'];
        } else {
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
        }

        \WP_CLI::log("Pulling from environment: $env" . ($this->executor->is_dry_run() ? ' (dry run)' : ''));
        $this->task_runner->run_pull($env, $folders_to_sync, $sync_db, isset($assoc_args['delete']));

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
}
