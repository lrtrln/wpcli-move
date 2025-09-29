<?php

namespace wpclimove;

class Executor
{
    /**
     * @var bool Indicates if we are in dry-run mode.
     */
    private $is_dry_run;

    /**
     * @var string SSH options for ControlMaster.
     */
    public $ssh_options = '';

    /**
     * @param bool $is_dry_run Whether to operate in dry-run mode.
     */
    public function __construct($is_dry_run = false)
    {
        $this->is_dry_run = $is_dry_run;

        // Defines a unique SSH control socket for this command execution.
        $socket_path       = "/tmp/wp-move-ssh-" . getmypid();
        $this->ssh_options = "-o ControlMaster=auto -o ControlPath={$socket_path} -o ControlPersist=60s";
    }

    /**
     * Executes a command or displays it in dry-run mode.
     *
     * @param string $command The command to execute.
     * @param bool $capture_stdout Whether to capture the standard output.
     * @return \WP_CLI\ProcessRun|null
     */
    public function execute($command, $capture_stdout = false)
    {
        // For rsync, dry-run is handled upstream by modifying the command.
        // We only block execution if the command is not an rsync command.
        if ($this->is_dry_run && 0 !== strpos($command, 'rsync')) {
            \WP_CLI::log(\WP_CLI::colorize('%C[Dry Run]%n ') . "Executing: $command");

            return (object) ['return_code' => 0]; // Simulate a successful execution
        }

        // For a real execution (or an rsync dry-run), we log and execute.
        $log_prefix = ($this->is_dry_run && 0 === strpos($command, 'rsync')) ? \WP_CLI::colorize('%C[Dry Run]%n ') : '';
        \WP_CLI::log($log_prefix . "Executing: $command");

        if ($this->is_dry_run) { // This only concerns rsync here
            passthru($command, $return_code);

            return (object) ['return_code' => $return_code];
        }

        if ($capture_stdout) {
            return \WP_CLI::launch($command, false, true);
        } else {
            passthru($command, $return_code);

            return (object) ['return_code' => $return_code];
        }
    }

    /**
     * @return mixed
     */
    public function is_dry_run()
    {
        return $this->is_dry_run;
    }
}
