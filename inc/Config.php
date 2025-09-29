<?php

namespace wpclimove;

use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * @var array Content of the move.yml file.
     */
    private $config;

    /**
     * Loads and validates the configuration file.
     */
    public function __construct($config_file)
    {
        if (!file_exists($config_file)) {
            \WP_CLI::error("Configuration file '$config_file' not found!");
        }

        try {
            $this->config = Yaml::parseFile($config_file);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            \WP_CLI::error("Error parsing move.yml file: " . $e->getMessage());
        }

        if (!isset($this->config['local'])) {
            \WP_CLI::error("The 'local' section is missing in move.yml.");
        }
    }

    /**
     * Retrieves the configuration for a given environment.
     *
     * @param string $env The environment (e.g., 'local', 'prod').
     * @return array The environment's configuration.
     */
    public function get_env_config($env)
    {
        if (!isset($this->config[$env])) {
            \WP_CLI::error("Environment '$env' is not defined in move.yml.");
        }

        return $this->config[$env];
    }

    /**
     * @return array The local environment's configuration.
     */
    public function get_local_config()
    {
        return $this->config['local'];
    }
}
