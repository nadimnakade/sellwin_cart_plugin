<?php
defined('ABSPATH') || exit;

class Sellwin_Tracker
{
    private Sellwin_Database $db;
    private Sellwin_Session $session;

    public function __construct(Sellwin_Database $db, Sellwin_Session $session)
    {
        $this->db      = $db;
        $this->session = $session;

        $this->init_hooks();
    }

    private function init_hooks(): void
    {
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
        add_action('woocommerce_remove_cart_item', [$this, 'track_remove_cart_item'], 10, 2);
        add_action('woocommerce_before_checkout_form', [$this, 'track_checkout_started'], 10, 0);
        add_action('woocommerce_thankyou', [$this, 'track_order_placed'], 10, 1);
        add_action('woocommerce_cart_item_removed', [$this, 'track_remove_cart_item'], 10, 2);
        add_action('woocommerce_cart_updated', [$this, 'track_cart_update'], 10, 0);
        add_action('wp_login', [$this, 'sync_session_on_login'], 10, 2);
    }

    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data): void
    {
        $mobile      = $this->session->get_mobile();
        $session_key = $this->session->get_session_id();

        if (empty($mobile)) {
            return;
        }

        $product = wc_get_product($product_id);
        $name    = $product ? $product->get_name() : 'Unknown';

        $this->db->insert_event([
            'session_key'  => $session_key,
            'event_type'   => 'add_to_cart',
            'product_id'   => $product_id,
            'product_name' => $name,
            'quantity'     => $quantity,
            'mobile'       => $mobile,
        ]);

        $this->sync_cart_status();
    }

    public function track_remove_cart_item($cart_item_key, $cart): void
    {
        $mobile      = $this->session->get_mobile();
        $session_key = $this->session->get_session_id();

        if (empty($mobile)) {
            return;
        }

        $product_id = 0;
        $name       = 'Unknown';
        if (isset($cart->cart_contents[$cart_item_key])) {
            $item       = $cart->cart_contents[$cart_item_key];
            $product_id = $item['product_id'];
            $product    = wc_get_product($product_id);
            $name       = $product ? $product->get_name() : 'Unknown';
        }

        $this->db->insert_event([
            'session_key'  => $session_key,
            'event_type'   => 'remove_from_cart',
            'product_id'   => $product_id,
            'product_name' => $name,
            'mobile'       => $mobile,
        ]);

        $this->sync_cart_status();
    }

    public function track_cart_update(): void
    {
        $mobile      = $this->session->get_mobile();
        $session_key = $this->session->get_session_id();

        if (empty($mobile)) {
            return;
        }

        $this->sync_cart_status();
    }

    public function track_checkout_started(): void
    {
        $mobile      = $this->session->get_mobile();
        $session_key = $this->session->get_session_id();

        if (empty($mobile)) {
            return;
        }

        $cart_data = $this->session->get_wc_cart_data();

        $this->db->insert_event([
            'session_key'  => $session_key,
            'event_type'   => 'checkout_started',
            'mobile'       => $mobile,
            'cart_value'   => $cart_data['total'],
            'payload'      => json_encode($cart_data['products']),
        ]);

        $this->sync_cart_status();
    }

    public function track_order_placed($order_id): void
    {
        $session_key = $this->session->get_session_id();
        $order       = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $mobile = $order->get_meta('sellwin_mobile') ?: $this->session->get_mobile();

        if (empty($mobile)) {
            $mobile = $order->get_billing_phone();
        }

        if (empty($mobile)) {
            return;
        }

        $products = [];
        foreach ($order->get_items() as $item) {
            $products[] = [
                'product_id'   => $item->get_product_id(),
                'product_name' => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'total'        => (float) $item->get_total(),
            ];
        }

        $this->db->insert_event([
            'session_key'  => $session_key,
            'event_type'   => 'order_placed',
            'mobile'       => $mobile,
            'order_id'     => $order_id,
            'cart_value'   => (float) $order->get_total(),
            'payload'      => json_encode($products),
        ]);

        $this->db->mark_converted($session_key, $order_id);
    }

    public function sync_session_on_login($user_login, $user): void
    {
        if (!$user || !isset($user->ID)) {
            return;
        }

        $mobile = get_user_meta($user->ID, 'sellwin_mobile', true);
        $name   = get_user_meta($user->ID, 'sellwin_name', true);

        if (empty($mobile)) {
            return;
        }

        $session_key = $this->session->get_session_id();
        $this->db->upsert_session($session_key, [
            'mobile'  => $mobile,
            'name'    => $name,
            'user_id' => $user->ID,
        ]);
    }

    private function sync_cart_status(): void
    {
        $session_key = $this->session->get_session_id();
        $mobile      = $this->session->get_mobile();
        $name        = $this->session->get_name();
        $cart_data   = $this->session->get_wc_cart_data();

        $this->db->upsert_cart($session_key, [
            'mobile'       => $mobile,
            'name'         => $name,
            'cart_data'    => json_encode($cart_data['products']),
            'cart_value'   => $cart_data['total'],
            'product_count' => $cart_data['count'],
            'status'       => 'active',
            'last_activity' => current_time('mysql'),
        ]);
    }
}
