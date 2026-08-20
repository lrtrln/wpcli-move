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
        self::load_project_dotenv(dirname($config_file));

        if (!file_exists($config_file)) {
            $this->config = ['local' => []];

            return;
        }

        try {
            $this->config = $this->resolve_env_values(Yaml::parseFile($config_file));
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
            $config = $this->get_wp_cli_alias_env_config($env);
            if (!$config) {
                \WP_CLI::error("Environment '$env' is not defined in move.yml or WP-CLI aliases.");
            }
        } else {
            $config = $this->normalize_env_config($env, $this->config[$env] ?? []);
        }

        return $config;
    }

    /**
     * @param string $env
     * @param mixed $config
     * @return array
     */
    private function normalize_env_config($env, $config)
    {
        if (null === $config) {
            $config = [];
        }

        if (!is_array($config)) {
            \WP_CLI::error("Environment '$env' must be a YAML mapping in move.yml.");
        }

        if (isset($config['alias'])) {
            $alias_config = $this->get_wp_cli_alias_env_config($config['alias']);
            if (!$alias_config) {
                \WP_CLI::error("WP-CLI alias '{$config['alias']}' referenced by environment '$env' was not found or is not supported.");
            }

            unset($config['alias']);
            $config = array_replace_recursive($alias_config, $config);
        }

        if (!isset($config['wp_path']) && isset($config['wordpress_path'])) {
            $config['wp_path'] = $config['wordpress_path'];
        }

        if ('local' === $env) {
            $config = $this->with_local_env_defaults($config);
        }

        if (!isset($config['wp_path'])) {
            \WP_CLI::error("Environment '$env' must define either 'wp_path' or 'wordpress_path' in move.yml.");
        }

        return $config;
    }

    /**
     * @param string $env
     * @return array|null
     */
    private function get_wp_cli_alias_env_config($env)
    {
        if (!class_exists('\WP_CLI') || !method_exists('\WP_CLI', 'get_runner')) {
            return null;
        }

        $alias = 0 === strpos($env, '@') ? $env : '@' . $env;
        $alias_key = ltrim($alias, '@');
        $aliases = \WP_CLI::get_runner()->aliases ?? [];
        if (isset($aliases[$alias])) {
            $alias_config = $aliases[$alias];
        } elseif (isset($aliases[$alias_key])) {
            $alias_config = $aliases[$alias_key];
        } else {
            return null;
        }

        if (!is_array($alias_config)) {
            return null;
        }

        if (array_is_list($alias_config)) {
            \WP_CLI::error("WP-CLI alias '$alias' is a group alias. WP Move CLI requires an alias targeting one WordPress install.");
        }

        $config = [];

        if (!empty($alias_config['url'])) {
            $config['vhost'] = $alias_config['url'];
        }

        if (!empty($alias_config['path'])) {
            $config['wp_path'] = $alias_config['path'];
        }

        if (!empty($alias_config['ssh'])) {
            [$ssh, $path] = $this->split_wp_cli_alias_ssh($alias_config['ssh']);
            $config['ssh'] = $ssh;
            if (empty($config['wp_path']) && $path) {
                $config['wp_path'] = $path;
            }
        }

        if (empty($config['ssh']) && 0 !== strpos($alias, '@local')) {
            return null;
        }

        return $config;
    }

    /**
     * @param string $ssh
     * @return string[]
     */
    private function split_wp_cli_alias_ssh($ssh)
    {
        $path_pos = false;
        foreach (['/', '~'] as $marker) {
            $pos = strpos($ssh, $marker);
            if (false !== $pos && (false === $path_pos || $pos < $path_pos)) {
                $path_pos = $pos;
            }
        }

        if (false === $path_pos) {
            return [$ssh, ''];
        }

        $target = rtrim(substr($ssh, 0, $path_pos), ':');
        $path   = substr($ssh, $path_pos);

        return [$target, $path];
    }

    /**
     * @return array The local environment's configuration.
     */
    public function get_local_config()
    {
        return $this->get_env_config('local');
    }

    /**
     * Completes the local config from common WordPress .env variables.
     *
     * @param array $config
     * @return array
     */
    private function with_local_env_defaults(array $config)
    {
        if (empty($config['wp_path']) && defined('ABSPATH')) {
            $config['wp_path'] = rtrim(ABSPATH, '/');
        }

        if (empty($config['vhost'])) {
            $config['vhost'] = self::env('WP_HOME') ?: self::env('WP_SITEURL');
        }

        $config['db'] = $config['db'] ?? [];

        $db_map = [
            'host' => 'DB_HOST',
            'name' => 'DB_NAME',
            'user' => 'DB_USER',
            'password' => 'DB_PASSWORD',
        ];

        foreach ($db_map as $config_key => $env_key) {
            if (!array_key_exists($config_key, $config['db'])) {
                $config['db'][$config_key] = self::env($env_key);
            }
        }

        return $config;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public static function env($key)
    {
        $value = getenv($key);

        return false === $value ? null : $value;
    }

    /**
     * Loads the first readable .env file from common WordPress project locations.
     *
     * @param string $base_dir
     * @return string
     */
    public static function load_project_dotenv($base_dir)
    {
        $base_dir = rtrim($base_dir, '/');
        $paths    = array_unique(array_filter([
            $base_dir . '/.env',
            dirname($base_dir) . '/.env',
            getcwd() ? rtrim(getcwd(), '/') . '/.env' : null,
        ]));

        foreach ($paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                self::load_dotenv($path);

                return $path;
            }
        }

        return '';
    }

    /**
     * Loads variables from a .env file without overriding existing environment variables.
     *
     * @param string $env_file
     */
    public static function load_dotenv($env_file)
    {
        if (!file_exists($env_file) || !is_readable($env_file)) {
            return;
        }

        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line || '#' === $line[0]) {
                continue;
            }

            if (0 === strpos($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (false === strpos($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) || false !== getenv($key)) {
                continue;
            }

            putenv($key . '=' . self::normalize_dotenv_value($value));
        }
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalize_dotenv_value($value)
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (('"' === $quote || "'" === $quote) && $quote === $value[strlen($value) - 1]) {
                $value = substr($value, 1, -1);
                if ('"' === $quote) {
                    $value = stripcslashes($value);
                }
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function resolve_env_values($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->resolve_env_values($item);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/i', function ($matches) {
            $env_value = getenv($matches[1]);
            if (false === $env_value) {
                \WP_CLI::error("Environment variable '{$matches[1]}' is referenced in move.yml but is not defined.");
            }

            return $env_value;
        }, $value);
    }
}
