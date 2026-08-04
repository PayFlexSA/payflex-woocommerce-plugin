<?php
/**
 * PHPUnit bootstrap for the Payflex WooCommerce plugin.
 *
 * The plugin is loaded against hand-written WordPress/WooCommerce stubs rather
 * than a real WordPress install, so the suite runs with no database and no
 * network access. See README.md for the reasoning and the limits of this.
 */

declare(strict_types=1);

define('PAYFLEX_TESTS_DIR', __DIR__);
define('PAYFLEX_PLUGIN_ROOT', dirname(__DIR__));

if (!file_exists(PAYFLEX_PLUGIN_ROOT . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are missing. Run `composer install` first.\n");
    exit(1);
}

require_once PAYFLEX_PLUGIN_ROOT . '/vendor/autoload.php';

// The plugin bails out unless ABSPATH is defined, as WordPress does.
if (!defined('ABSPATH')) {
    define('ABSPATH', PAYFLEX_PLUGIN_ROOT . '/tests/fake-wp-root/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

require_once __DIR__ . '/stubs/State.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';
require_once __DIR__ . '/stubs/woocommerce-namespaced.php';

// Prime globals before the plugin file runs — it reads $wp_version at load time.
PF_State::reset();

/*
 * Load the plugin exactly as WordPress would: the main file first, then fire
 * `plugins_loaded`, which is what defines PAYFLEX_PLUGIN_URL/DIR and requires
 * the gateway class.
 */
require_once PAYFLEX_PLUGIN_ROOT . '/partpay.php';

do_action('plugins_loaded');

// Register the block checkout integration class the same way the plugin does.
require_once PAYFLEX_PLUGIN_ROOT . '/includes/class-payflex-woocommerce-block-checkout.php';

require_once __DIR__ . '/PF_PluginMeta.php';
require_once __DIR__ . '/PF_TestCase.php';
