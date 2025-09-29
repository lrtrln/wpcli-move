<?php
/**
 * Plugin Name: WP Move CLI
 * Description: WP-CLI utility inspired by WordMove for deploying and synchronizing multiple environments
 * Version: 0.1.0
 * Author: lrtln
 * Author URI: https://lrtrln.fr
 * License: GPL3.0+
 */

$loadClass = [
    'Config',
    'Executor',
    'TaskRunner',
    'Commands',
];

if (defined('WP_CLI') && WP_CLI) {

    require_once __DIR__ . '/vendor/autoload.php';

    foreach ($loadClass as $class) {
        require_once __DIR__ . "/inc/{$class}.php";
    }

    WP_CLI::add_command('move', 'wpclimove\Commands');
}
