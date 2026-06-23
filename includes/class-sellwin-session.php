<?php
defined('ABSPATH') || exit;

class Sellwin_Session
{
    private string $cookie_key = 'sellwin_session';

    public function __construct()
    {
        if (!headers_sent() && empty($_COOKIE[$this->cookie_key])) {
            $this->set_session_cookie();
        }
    }

    public function get_session_id(): string
    {
        if (!empty($_COOKIE[$this->cookie_key])) {
            return sanitize_text_field($_COOKIE[$this->cookie_key]);
        }
        return $this->set_session_cookie();
    }

    private function set_session_cookie(): string
    {
        $session_id = wp_generate_uuid4();
        $expiry     = time() + (30 * DAY_IN_SECONDS);
        setcookie($this->cookie_key, $session_id, [
            'expires'  => $expiry,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$this->cookie_key] = $session_id;
        return $session_id;
    }

    public function get_mobile(): string
    {
        if (!empty($_COOKIE['sellwin_mobile'])) {
            return sanitize_text_field($_COOKIE['sellwin_mobile']);
        }
        $session = $this->get_session_from_db();
        return $session ? $session->mobile : '';
    }

    public function get_name(): string
    {
        if (!empty($_COOKIE['sellwin_name'])) {
            return sanitize_text_field($_COOKIE['sellwin_name']);
        }
        $session = $this->get_session_from_db();
        return $session ? $session->name : '';
    }

    private function get_session_from_db(): ?object
    {
        global $wpdb;
        $session_key = $this->get_session_id();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sellwin_sessions WHERE session_key = %s",
            $session_key
        ));
    }

    public function get_wc_cart_data(): array
    {
        if (!function_exists('WC') || null === WC()->cart) {
            return ['products' => [], 'total' => 0, 'count' => 0];
        }

        $cart = WC()->cart;
        $products = [];

        foreach ($cart->get_cart() as $item) {
            $product = $item['data'];
            $products[] = [
                'product_id'   => $product->get_id(),
                'product_name' => $product->get_name(),
                'quantity'     => $item['quantity'],
                'price'        => (float) $product->get_price(),
                'subtotal'     => (float) $item['line_total'],
            ];
        }

        return [
            'products' => $products,
            'total'    => (float) $cart->get_total('edit'),
            'count'    => $cart->get_cart_contents_count(),
        ];
    }
}
