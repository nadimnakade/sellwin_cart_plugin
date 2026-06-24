<?php
defined('ABSPATH') || exit;

class Sellwin_Mobile_Capture
{
    public static function enqueue_assets(): void
    {
        if (!function_exists('is_woocommerce') || !is_woocommerce() && !is_cart() && !is_checkout() && !is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'sellwin-capture',
            SELLWIN_PLUGIN_URL . 'assets/css/capture.css',
            [],
            SELLWIN_VERSION
        );

        wp_enqueue_script(
            'sellwin-capture',
            SELLWIN_PLUGIN_URL . 'assets/js/capture.js',
            ['jquery'],
            SELLWIN_VERSION,
            true
        );

        wp_localize_script('sellwin-capture', 'sellwin_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sellwin_mobile_nonce'),
            'has_mobile' => !empty($_COOKIE['sellwin_mobile']),
        ]);
    }

    public static function ajax_save_mobile(): void
    {
        check_ajax_referer('sellwin_mobile_nonce', 'nonce');

        $mobile = sanitize_text_field($_POST['mobile'] ?? '');
        $name   = sanitize_text_field($_POST['name'] ?? '');

        if (empty($mobile) || !preg_match('/^[0-9]{10,15}$/', $mobile)) {
            wp_send_json_error(['message' => 'Invalid mobile number']);
        }

        $session_key = (new Sellwin_Session())->get_session_id();

        setcookie('sellwin_mobile', $mobile, [
            'expires'  => time() + (30 * DAY_IN_SECONDS),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        setcookie('sellwin_name', $name, [
            'expires'  => time() + (30 * DAY_IN_SECONDS),
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $db = new Sellwin_Database();
        $db->upsert_session($session_key, [
            'mobile'     => $mobile,
            'name'       => $name,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Link any existing carts for this session with the mobile number
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sellwin_carts',
            ['mobile' => $mobile, 'name' => $name],
            ['session_key' => $session_key, 'mobile' => '']
        );

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, 'sellwin_mobile', $mobile);
            update_user_meta($user_id, 'sellwin_name', $name);
            $db->upsert_session($session_key, ['user_id' => $user_id]);
        }

        wp_send_json_success([
            'message' => 'Mobile saved successfully',
            'mobile'  => $mobile,
            'name'    => $name,
        ]);
    }
}
