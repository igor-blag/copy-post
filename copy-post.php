<?php
/**
 * Plugin Name: Copy Post
 * Plugin URI: https://github.com/igor-blag/copy-post
 * Description: Копирование записей и страниц между сайтами сети WordPress Multisite.
 * Version: 1.0.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Author: Igor Blag
 * Author URI: https://github.com/igor-blag
 * Update URI: https://github.com/igor-blag/copy-post
 * Network: true
 * Text Domain: copy-post
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('COPY_POST_VERSION', '1.0.0');
define('COPY_POST_FILE', __FILE__);
define('COPY_POST_DIR', plugin_dir_path(__FILE__));
define('COPY_POST_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    $prefix = 'CopyPost\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $kebab = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $relative));
    $file = COPY_POST_DIR . 'includes/class-' . $kebab . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['CopyPost\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['CopyPost\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
    CopyPost\Plugin::instance()->init();
});
