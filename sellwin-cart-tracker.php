<?php
/**
 * Plugin Name: Sellwin Cart Tracker
 * Plugin URI: https://sellwin.in
 * Description: Track WooCommerce carts, orders, and customer mobile numbers for WhatsApp follow-ups
 * Version: 1.0.0
 * Author: Sellwin
 * Text Domain: sellwin-cart-tracker
 * Domain Path: /languages
 * Requires WP: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 */

defined('ABSPATH') || exit;

define('SELLWIN_VERSION', '1.0.0');
define('SELLWIN_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SELLWIN_PLUGIN_URL', plugin_dir_url(__FILE__));

// PHP version check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Sellwin Cart Tracker requires PHP 7.4 or higher. Current PHP version: ' . PHP_VERSION . '</p></div>';
    });
    return;
}

// Autoload
require_once SELLWIN_PLUGIN_PATH . 'includes/class-sellwin-database.php';
require_once SELLWIN_PLUGIN_PATH . 'includes/class-sellwin-session.php';
require_once SELLWIN_PLUGIN_PATH . 'includes/class-sellwin-mobile-capture.php';
require_once SELLWIN_PLUGIN_PATH . 'api/class-sellwin-rest-controller.php';
require_once SELLWIN_PLUGIN_PATH . 'admin/class-sellwin-admin.php';

// Activation / Deactivation
register_activation_hook(__FILE__, 'sellwin_activate');
register_deactivation_hook(__FILE__, 'sellwin_deactivate');

function sellwin_activate(): void
{
    $db = new Sellwin_Database();
    $db->create_tables();
    if (!wp_next_scheduled('sellwin_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'sellwin_daily_cleanup');
    }
    set_transient('sellwin_flush_rewrite', true);
}

function sellwin_deactivate(): void
{
    $timestamp = wp_next_scheduled('sellwin_daily_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sellwin_daily_cleanup');
    }
}

// Flush rewrite rules on activation
add_action('init', function () {
    if (get_transient('sellwin_flush_rewrite')) {
        delete_transient('sellwin_flush_rewrite');
        flush_rewrite_rules();
    }
});

// WooCommerce dependency check (admin notice only, does not block plugin)
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning is-dismissible"><p>Sellwin Cart Tracker: WooCommerce is recommended for full functionality. Install and activate WooCommerce for order tracking features.</p></div>';
        });
    }
});

// Register REST routes early and ensure they're available
add_action('rest_api_init', 'sellwin_register_routes', 5);
function sellwin_register_routes(): void
{
    $db = new Sellwin_Database();
    $session = new Sellwin_Session();
    $controller = new Sellwin_REST_Controller($db, $session);
    $controller->register_routes();
}

// Register public health-check endpoint with no auth
add_action('rest_api_init', function () {
    register_rest_route('sellwin/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new WP_REST_Response([
                'status'  => 'ok',
                'plugin'  => 'Sellwin Cart Tracker',
                'version' => SELLWIN_VERSION,
                'php'     => PHP_VERSION,
                'wp'      => get_bloginfo('version'),
                'wc'      => class_exists('WooCommerce') ? WC()->version : 'not active',
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
}, 0);

// Frontend mobile capture hooks
add_action('wp_enqueue_scripts', ['Sellwin_Mobile_Capture', 'enqueue_assets']);
add_action('wp_ajax_sellwin_save_mobile', ['Sellwin_Mobile_Capture', 'ajax_save_mobile']);
add_action('wp_ajax_nopriv_sellwin_save_mobile', ['Sellwin_Mobile_Capture', 'ajax_save_mobile']);

// Daily cleanup
add_action('sellwin_daily_cleanup', function () {
    $db = new Sellwin_Database();
    $db->cleanup_old_data();
});

// WooCommerce event hooks
add_action('woocommerce_add_to_cart', 'sellwin_track_add_to_cart', 10, 6);
add_action('woocommerce_remove_cart_item', 'sellwin_track_cart_change', 10, 2);
add_action('woocommerce_cart_item_removed', 'sellwin_track_cart_change', 10, 2);
add_action('woocommerce_cart_updated', 'sellwin_sync_cart', 10, 0);
add_action('woocommerce_before_checkout_form', 'sellwin_track_checkout', 10, 0);
add_action('woocommerce_thankyou', 'sellwin_track_order', 10, 1);
add_action('wp_login', 'sellwin_sync_session_login', 10, 2);

/**
 * Helper: get current session
 */
function sellwin_get_session(): Sellwin_Session
{
    static $session = null;
    if ($session === null) {
        $session = new Sellwin_Session();
    }
    return $session;
}

/**
 * Helper: get current database
 */
function sellwin_get_db(): Sellwin_Database
{
    static $db = null;
    if ($db === null) {
        $db = new Sellwin_Database();
    }
    return $db;
}

function sellwin_track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data): void
{
    $mobile      = sellwin_get_session()->get_mobile();
    $session_key = sellwin_get_session()->get_session_id();

    if (empty($mobile)) return;

    $product = wc_get_product($product_id);
    $name    = $product ? $product->get_name() : 'Unknown';

    sellwin_get_db()->insert_event([
        'session_key'  => $session_key,
        'event_type'   => 'add_to_cart',
        'product_id'   => $product_id,
        'product_name' => $name,
        'quantity'     => $quantity,
        'mobile'       => $mobile,
    ]);

    sellwin_sync_cart();
}

function sellwin_track_cart_change($cart_item_key, $cart): void
{
    $mobile = sellwin_get_session()->get_mobile();
    if (empty($mobile)) return;
    sellwin_sync_cart();
}

function sellwin_sync_cart(): void
{
    $session_key = sellwin_get_session()->get_session_id();
    $mobile      = sellwin_get_session()->get_mobile();
    $name        = sellwin_get_session()->get_name();

    if (empty($mobile)) return;

    $cart_data = sellwin_get_session()->get_wc_cart_data();

    sellwin_get_db()->upsert_cart($session_key, [
        'mobile'       => $mobile,
        'name'         => $name,
        'cart_data'    => json_encode($cart_data['products']),
        'cart_value'   => $cart_data['total'],
        'product_count' => $cart_data['count'],
        'status'       => 'active',
        'last_activity' => current_time('mysql'),
    ]);
}

function sellwin_track_checkout(): void
{
    $mobile = sellwin_get_session()->get_mobile();
    if (empty($mobile)) return;

    $cart_data = sellwin_get_session()->get_wc_cart_data();

    sellwin_get_db()->insert_event([
        'session_key'  => sellwin_get_session()->get_session_id(),
        'event_type'   => 'checkout_started',
        'mobile'       => $mobile,
        'cart_value'   => $cart_data['total'],
        'payload'      => json_encode($cart_data['products']),
    ]);

    sellwin_sync_cart();
}

function sellwin_track_order($order_id): void
{
    $session_key = sellwin_get_session()->get_session_id();
    $order       = wc_get_order($order_id);

    if (!$order) return;

    $mobile = $order->get_meta('sellwin_mobile') ?: sellwin_get_session()->get_mobile();
    $mobile = $mobile ?: $order->get_billing_phone();

    if (empty($mobile)) return;

    $products = [];
    foreach ($order->get_items() as $item) {
        $products[] = [
            'product_id'   => $item->get_product_id(),
            'product_name' => $item->get_name(),
            'quantity'     => $item->get_quantity(),
            'total'        => (float) $item->get_total(),
        ];
    }

    sellwin_get_db()->insert_event([
        'session_key'  => $session_key,
        'event_type'   => 'order_placed',
        'mobile'       => $mobile,
        'order_id'     => $order_id,
        'cart_value'   => (float) $order->get_total(),
        'payload'      => json_encode($products),
    ]);

    sellwin_get_db()->mark_converted($session_key, $order_id);
}

function sellwin_sync_session_login($user_login, $user): void
{
    if (!$user || !isset($user->ID)) return;

    $mobile = get_user_meta($user->ID, 'sellwin_mobile', true);
    $name   = get_user_meta($user->ID, 'sellwin_name', true);

    if (empty($mobile)) return;

    sellwin_get_db()->upsert_session(sellwin_get_session()->get_session_id(), [
        'mobile'  => $mobile,
        'name'    => $name,
        'user_id' => $user->ID,
    ]);
}

// Admin menu
add_action('admin_menu', ['Sellwin_Admin', 'add_admin_menu']);
add_filter('plugin_action_links_' . plugin_basename(__FILE__), ['Sellwin_Admin', 'add_action_links']);
