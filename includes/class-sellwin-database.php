<?php
defined('ABSPATH') || exit;

class Sellwin_Database
{
    private string $table_sessions;
    private string $table_events;
    private string $table_carts;

    public function __construct()
    {
        global $wpdb;
        $this->table_sessions = $wpdb->prefix . 'sellwin_sessions';
        $this->table_events   = $wpdb->prefix . 'sellwin_events';
        $this->table_carts    = $wpdb->prefix . 'sellwin_carts';
    }

    public function create_tables(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$this->table_sessions} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL UNIQUE,
            mobile VARCHAR(20) NOT NULL DEFAULT '',
            name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mobile (mobile),
            INDEX idx_session_key (session_key)
        ) $charset_collate;";

        $sql_events = "CREATE TABLE IF NOT EXISTS {$this->table_events} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT 0,
            product_name VARCHAR(255) DEFAULT '',
            quantity INT DEFAULT 0,
            cart_value DECIMAL(12,2) DEFAULT 0.00,
            order_id BIGINT UNSIGNED DEFAULT 0,
            mobile VARCHAR(20) DEFAULT '',
            payload LONGTEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_key),
            INDEX idx_event_type (event_type),
            INDEX idx_mobile (mobile),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        $sql_carts = "CREATE TABLE IF NOT EXISTS {$this->table_carts} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            mobile VARCHAR(20) NOT NULL DEFAULT '',
            name VARCHAR(100) NOT NULL DEFAULT '',
            cart_data LONGTEXT DEFAULT '',
            cart_value DECIMAL(12,2) DEFAULT 0.00,
            product_count INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            converted_to_order_id BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mobile (mobile),
            INDEX idx_status (status),
            INDEX idx_last_activity (last_activity),
            INDEX idx_session_key (session_key)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_sessions);
        dbDelta($sql_events);
        dbDelta($sql_carts);
    }

    public function get_table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sellwin_' . $name;
    }

    public function get_sessions_table(): string
    {
        return $this->table_sessions;
    }

    public function get_events_table(): string
    {
        return $this->table_events;
    }

    public function get_carts_table(): string
    {
        return $this->table_carts;
    }

    public function upsert_session(string $session_key, array $data): void
    {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$this->table_sessions} WHERE session_key = %s",
            $session_key
        ));

        if ($existing) {
            $wpdb->update($this->table_sessions, $data, ['session_key' => $session_key]);
        } else {
            $data['session_key'] = $session_key;
            $data['created_at'] = current_time('mysql');
            $data['updated_at'] = current_time('mysql');
            $wpdb->insert($this->table_sessions, $data);
        }
    }

    public function get_session_by_key(string $session_key): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_sessions} WHERE session_key = %s",
            $session_key
        ));
    }

    public function get_session_by_mobile(string $mobile): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_sessions} WHERE mobile = %s",
            $mobile
        ));
    }

    public function insert_event(array $data): void
    {
        global $wpdb;
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($this->table_events, $data);
    }

    public function upsert_cart(string $session_key, array $data): void
    {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$this->table_carts} WHERE session_key = %s",
            $session_key
        ));

        $data['updated_at'] = current_time('mysql');

        if ($existing) {
            $wpdb->update($this->table_carts, $data, ['session_key' => $session_key]);
        } else {
            $data['session_key'] = $session_key;
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($this->table_carts, $data);
        }
    }

    public function get_cart_by_session(string $session_key): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_carts} WHERE session_key = %s",
            $session_key
        ));
    }

    public function get_active_carts(int $minutes = 30): array
    {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_carts}
            WHERE status = 'active'
            AND converted_to_order_id = 0
            AND last_activity >= %s
            ORDER BY last_activity DESC",
            $cutoff
        ));
    }

    public function get_abandoned_carts(int $minutes = 30): array
    {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_carts}
            WHERE status = 'active'
            AND converted_to_order_id = 0
            AND last_activity < %s
            ORDER BY last_activity DESC",
            $cutoff
        ));
    }

    public function get_dashboard_stats(): array
    {
        global $wpdb;
        $today = date('Y-m-d');

        $orders_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}posts
            WHERE post_type = 'shop_order'
            AND post_date >= %s",
            $today
        ));

        $revenue_today = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(meta_value) FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_date >= %s
            AND pm.meta_key = '_order_total'",
            $today
        ));

        $active_carts = count($this->get_active_carts());
        $abandoned_carts = count($this->get_abandoned_carts());

        return [
            'ordersToday'    => $orders_today,
            'revenueToday'   => $revenue_today ?: 0,
            'activeCarts'    => $active_carts,
            'abandonedCarts' => $abandoned_carts,
        ];
    }

    public function mark_converted(string $session_key, int $order_id): void
    {
        global $wpdb;
        $wpdb->update(
            $this->table_carts,
            [
                'status'              => 'converted',
                'converted_to_order_id' => $order_id,
                'updated_at'          => current_time('mysql'),
            ],
            ['session_key' => $session_key]
        );
    }

    public function get_customer_history(string $mobile): array
    {
        global $wpdb;

        $session = $this->get_session_by_mobile($mobile);
        if (!$session) {
            return [];
        }

        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_events} WHERE mobile = %s ORDER BY created_at DESC LIMIT 50",
            $mobile
        ));

        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_carts} WHERE mobile = %s ORDER BY created_at DESC",
            $mobile
        ));

        $orders = wc_get_orders(['customer_id' => $session->user_id, 'limit' => 20]);
        $orders_data = [];
        foreach ($orders as $order) {
            $orders_data[] = [
                'id'     => $order->get_id(),
                'total'  => $order->get_total(),
                'status' => $order->get_status(),
                'date'   => $order->get_date_created()->format('Y-m-d H:i:s'),
            ];
        }

        $lifetime_value = array_sum(array_column($orders_data, 'total'));

        return [
            'mobile'        => $mobile,
            'name'          => $session->name,
            'email'         => $session->email,
            'events'        => $events,
            'carts'         => $carts,
            'orders'        => $orders_data,
            'lifetimeValue' => $lifetime_value,
            'productsViewed'=> count($events),
        ];
    }

    public function cleanup_old_data(int $days = 90): void
    {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_events} WHERE created_at < %s", $cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_carts} WHERE updated_at < %s AND status = 'converted'", $cutoff));
    }
}

add_action('sellwin_daily_cleanup', function () {
    $db = new Sellwin_Database();
    $db->cleanup_old_data();
});
