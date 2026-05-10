<?php
/**
 * Plugin Name: OLX.ba WooCommerce Sync
 * Description: Publishes WooCommerce products to OLX.ba listings.
 * Version: 0.5.0
 * Author: Codex
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Text Domain: olx-ba-woocommerce-sync
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLX_BA_WC_SYNC_VERSION', '0.5.0');
define('OLX_BA_WC_SYNC_FILE', __FILE__);
define('OLX_BA_WC_SYNC_DIR', plugin_dir_path(__FILE__));

require_once OLX_BA_WC_SYNC_DIR . 'includes/class-olx-ba-logger.php';
require_once OLX_BA_WC_SYNC_DIR . 'includes/class-olx-ba-api-client.php';
require_once OLX_BA_WC_SYNC_DIR . 'includes/class-olx-ba-sync-service.php';
require_once OLX_BA_WC_SYNC_DIR . 'includes/class-olx-ba-admin.php';

final class OLX_BA_WooCommerce_Sync
{
    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot(): void
    {
        load_plugin_textdomain(
            'olx-ba-woocommerce-sync',
            false,
            dirname(plugin_basename(OLX_BA_WC_SYNC_FILE)) . '/languages'
        );

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        $client = new OLX_BA_API_Client();
        $sync_service = new OLX_BA_Sync_Service($client);
        new OLX_BA_Admin($client, $sync_service);

        add_action('save_post_product', [$sync_service, 'maybe_auto_sync_product'], 20, 3);
    }

    public function woocommerce_missing_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('OLX.ba WooCommerce Sync requires WooCommerce to be active.', 'olx-ba-woocommerce-sync');
        echo '</p></div>';
    }
}

OLX_BA_WooCommerce_Sync::instance();
