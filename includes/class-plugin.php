<?php

namespace CopyPost;

defined('ABSPATH') || exit;

/**
 * Точка входа плагина.
 */
class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        load_plugin_textdomain(
            'copy-post',
            false,
            dirname(plugin_basename(COPY_POST_FILE)) . '/languages'
        );

        if (!is_multisite()) {
            add_action('admin_notices', [$this, 'multisite_notice']);
            add_action('network_admin_notices', [$this, 'multisite_notice']);
            return;
        }

        (new Settings())->init();
        (new Ajax())->init();
        (new Updater())->init();

        if (is_admin()) {
            (new Admin())->init();
        }

        if (defined('WP_CLI') && WP_CLI) {
            (new Cli())->init();
        }
    }

    public static function activate(): void
    {
        if (!is_multisite()) {
            return;
        }

        if (get_site_option(Settings::OPTION, null) === null) {
            add_site_option(Settings::OPTION, Settings::defaults());
        }
    }

    public static function deactivate(): void
    {
        // Данные копий намеренно не удаляются при деактивации.
    }

    public function multisite_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Плагин Copy Post работает только в сети WordPress Multisite.', 'copy-post');
        echo '</p></div>';
    }
}
