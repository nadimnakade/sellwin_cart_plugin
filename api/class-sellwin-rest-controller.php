<?php
defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

class Sellwin_REST_Controller
{
    private Sellwin_Database $db;
    private Sellwin_Session $session;
    private string $namespace = 'sellwin/v1';

    public function __construct(Sellwin_Database $db, Sellwin_Session $session)
    {
        $this->db      = $db;
        $this->session = $session;
    }

    public function register_routes(): void
    {
        // Dashboard stats
        register_rest_route($this->namespace, '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_dashboard'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Orders
        register_rest_route($this->namespace, '/orders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_orders'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Single Order
        register_rest_route($this->namespace, '/order/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_order'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Active Carts
        register_rest_route($this->namespace, '/active-carts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_active_carts'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Abandoned Carts
        register_rest_route($this->namespace, '/abandoned-carts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_abandoned_carts'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Customer by mobile
        register_rest_route($this->namespace, '/customer/(?P<mobile>[0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_customer'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Revenue trend
        register_rest_route($this->namespace, '/revenue-trend', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_revenue_trend'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Orders trend
        register_rest_route($this->namespace, '/orders-trend', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_orders_trend'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Abandoned cart trend
        register_rest_route($this->namespace, '/abandoned-trend', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_abandoned_trend'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Top products
        register_rest_route($this->namespace, '/top-products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_top_products'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Auth check
        register_rest_route($this->namespace, '/auth/check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'auth_check'],
            'permission_callback' => '__return_true',
        ]);

        // Debug endpoint (no auth needed)
        register_rest_route($this->namespace, '/debug', [
            'methods'             => 'GET',
            'callback'            => [$this, 'debug_endpoint'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function check_admin_permission(WP_REST_Request $request): bool
    {
        // Accept WooCommerce API key authentication (query params)
        $key    = $request->get_param('consumer_key');
        $secret = $request->get_param('consumer_secret');
        if (!empty($key) && !empty($secret) && $this->is_valid_wc_key($key, $secret)) {
            return true;
        }

        // Accept WordPress user login
        if (is_user_logged_in()) {
            if (current_user_can('administrator') || current_user_can('manage_options')) {
                return true;
            }
            if (class_exists('WooCommerce') && current_user_can('manage_woocommerce')) {
                return true;
            }
        }

        return false;
    }

    private function is_valid_wc_key(string $key, string $secret): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'woocommerce_api_keys';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return false;
        }

        // WooCommerce stores keys using wc_api_hash() — use it directly for lookup
        if (function_exists('wc_api_hash')) {
            $hashed_key = wc_api_hash($key);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT user_id, permissions, consumer_secret FROM {$table} WHERE consumer_key = %s LIMIT 1",
                $hashed_key
            ));

            if ($row && hash_equals($row->consumer_secret, wc_api_hash($secret))) {
                return in_array($row->permissions, ['read', 'write', 'read_write'], true);
            }
        }

        // Fallback: scan all rows with multiple comparison methods
        $hashed_key = function_exists('wc_api_hash') ? wc_api_hash($key) : hash('sha256', $key);

        $rows = $wpdb->get_results(
            "SELECT key_id, user_id, permissions, consumer_key, consumer_secret FROM {$table} ORDER BY key_id DESC"
        );

        foreach ($rows as $row) {
            $stored_key    = $row->consumer_key;
            $stored_secret = $row->consumer_secret;

            $key_matches = hash_equals($stored_key, $key)
                || hash_equals($stored_key, $hashed_key)
                || wp_check_password($key, $stored_key);

            $secret_matches = hash_equals($stored_secret, $secret)
                || (function_exists('wc_api_hash') && hash_equals($stored_secret, wc_api_hash($secret)))
                || wp_check_password($secret, $stored_secret);

            if ($key_matches && $secret_matches) {
                return in_array($row->permissions, ['read', 'write', 'read_write'], true);
            }
        }

        return false;
    }

    public function get_dashboard(WP_REST_Request $request): WP_REST_Response
    {
        $stats = $this->db->get_dashboard_stats();
        return new WP_REST_Response($stats, 200);
    }

    public function get_orders(WP_REST_Request $request): WP_REST_Response
    {
        $per_page = (int) $request->get_param('per_page') ?: 20;
        $page     = (int) $request->get_param('page') ?: 1;
        $status   = $request->get_param('status');
        $search   = $request->get_param('search');

        $args = [
            'limit'  => $per_page,
            'page'   => $page,
            'return' => 'objects',
        ];

        if ($status) {
            $args['status'] = $status;
        }

        if ($search) {
            $args['s'] = $search;
        }

        $orders = wc_get_orders($args);
        $data   = [];

        foreach ($orders as $order) {
            $mobile = $order->get_meta('sellwin_mobile') ?: $order->get_billing_phone();
            $data[] = [
                'id'              => $order->get_id(),
                'orderNumber'     => $order->get_order_number(),
                'customerName'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'mobile'          => $mobile,
                'email'           => $order->get_billing_email(),
                'total'           => (float) $order->get_total(),
                'status'          => $order->get_status(),
                'dateCreated'     => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
                'paymentMethod'   => $order->get_payment_method_title(),
                'currency'        => $order->get_currency(),
            ];
        }

        return new WP_REST_Response([
            'orders' => $data,
            'total'  => count($data),
            'page'   => $page,
            'perPage'=> $per_page,
        ], 200);
    }

    public function get_order(WP_REST_Request $request): WP_REST_Response
    {
        $order_id = (int) $request->get_param('id');
        $order    = wc_get_order($order_id);

        if (!$order) {
            return new WP_REST_Response(['message' => 'Order not found'], 404);
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'productId'   => $item->get_product_id(),
                'name'        => $item->get_name(),
                'sku'         => $product ? $product->get_sku() : '',
                'quantity'    => $item->get_quantity(),
                'price'       => (float) $item->get_total(),
                'subtotal'    => (float) $item->get_subtotal(),
                'image'       => $product ? wp_get_attachment_url($product->get_image_id()) : '',
            ];
        }

        $mobile = $order->get_meta('sellwin_mobile') ?: $order->get_billing_phone();

        $data = [
            'id'             => $order->get_id(),
            'orderNumber'    => $order->get_order_number(),
            'status'         => $order->get_status(),
            'currency'       => $order->get_currency(),
            'dateCreated'    => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
            'datePaid'       => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d H:i:s') : '',
            'paymentMethod'  => $order->get_payment_method_title(),
            'customer'       => [
                'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'mobile'=> $mobile,
                'email' => $order->get_billing_email(),
            ],
            'billing'        => [
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'mobile'    => $mobile,
                'email'     => $order->get_billing_email(),
                'address1'  => $order->get_billing_address_1(),
                'address2'  => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            ],
            'shipping'       => [
                'firstName' => $order->get_shipping_first_name(),
                'lastName'  => $order->get_shipping_last_name(),
                'address1'  => $order->get_shipping_address_1(),
                'address2'  => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
            ],
            'products'       => $items,
            'subtotal'       => (float) $order->get_subtotal(),
            'discountTotal'  => (float) $order->get_discount_total(),
            'taxTotal'       => (float) $order->get_total_tax(),
            'shippingTotal'  => (float) $order->get_shipping_total(),
            'total'          => (float) $order->get_total(),
            'note'           => $order->get_customer_note(),
        ];

        return new WP_REST_Response($data, 200);
    }

    public function get_active_carts(WP_REST_Request $request): WP_REST_Response
    {
        $minutes = max(1, (int) ($request->get_param('minutes') ?: 5));
        $carts = $this->db->get_active_carts($minutes);

        $data = array_map(function ($cart) {
            $last_activity = strtotime($cart->last_activity);
            $ago_seconds   = time() - $last_activity;
            $ago_minutes   = round($ago_seconds / 60);

            return [
                'id'           => (int) $cart->id,
                'mobile'       => $cart->mobile,
                'name'         => $cart->name,
                'products'     => (int) $cart->product_count,
                'cartValue'    => (float) $cart->cart_value,
                'lastActivity' => $cart->last_activity,
                'lastActivityAgo' => $ago_minutes <= 1 ? 'Just now' : "{$ago_minutes} min ago",
                'cartData'     => json_decode($cart->cart_data, true) ?: [],
            ];
        }, $carts);

        return new WP_REST_Response($data, 200);
    }

    public function get_abandoned_carts(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $filter   = sanitize_text_field($request->get_param('filter') ?: 'all');
        $search   = sanitize_text_field($request->get_param('search') ?: '');
        $sort     = sanitize_text_field($request->get_param('sort') ?: 'last_activity');
        $order    = strtoupper(sanitize_text_field($request->get_param('order') ?: 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = min(100, max(1, (int) ($request->get_param('per_page') ?: 50)));
        $page     = max(1, (int) ($request->get_param('page') ?: 1));
        $offset   = ($page - 1) * $per_page;

        $table      = $this->db->get_table('carts');
        $s_table    = $wpdb->prefix . 'sellwin_sessions';
        $wc_orders  = $wpdb->prefix . 'posts';

        // Build WHERE
        $where_parts = ["c.status = 'active'", "c.converted_to_order_id = 0"];
        $params = [];

        // Time filter
        $minutes_filter = $this->parse_filter_minutes($filter);
        if ($minutes_filter > 0) {
            $threshold = date('Y-m-d H:i:s', strtotime("-{$minutes_filter} minutes"));
            $where_parts[] = 'c.last_activity < %s';
            $params[] = $threshold;
        } elseif ($filter === 'today') {
            $where_parts[] = 'c.last_activity < %s';
            $params[] = current_time('Y-m-d') . ' 00:00:00';
        } elseif ($filter === 'week') {
            $where_parts[] = 'c.last_activity < %s';
            $params[] = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        }
        // 'all' = no time constraint

        // Search
        if ($search) {
            $where_parts[] = '(c.mobile LIKE %s OR c.name LIKE %s)';
            $pattern = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $where_sql = implode(' AND ', $where_parts);

        // Sort mapping
        $sort_map = [
            'last_activity' => 'c.last_activity',
            'cart_value'    => 'c.cart_value',
            'product_count' => 'c.product_count',
            'name'          => 'c.name',
            'mobile'        => 'c.mobile',
        ];
        $sort_col = $sort_map[$sort] ?? 'c.last_activity';

        // Count
        $count_sql = "SELECT COUNT(*) FROM {$table} c WHERE {$where_sql}";
        $total = empty($params) ? (int) $wpdb->get_var($count_sql) : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        $total_pages = max(1, (int) ceil($total / $per_page));

        // Fetch
        $params[] = $per_page;
        $params[] = $offset;
        $data_sql = "SELECT c.* FROM {$table} c WHERE {$where_sql} ORDER BY {$sort_col} {$order} LIMIT %d OFFSET %d";
        $carts = $wpdb->get_results($wpdb->prepare($data_sql, ...$params));

        $data = array_map(function ($cart) {
            $last_activity = strtotime($cart->last_activity);
            $ago_seconds   = time() - $last_activity;
            $hours         = floor($ago_seconds / 3600);
            $minutes       = floor(($ago_seconds % 3600) / 60);

            $abandoned_since = '';
            if ($hours > 0) {
                $abandoned_since = $hours . ' Hour' . ($hours > 1 ? 's' : '');
                if ($minutes > 0) {
                    $abandoned_since .= ' ' . $minutes . ' Min';
                }
            } else {
                $abandoned_since = $minutes . ' Min';
            }

            return [
                'id'             => (int) $cart->id,
                'mobile'         => $cart->mobile,
                'name'           => $cart->name,
                'products'       => (int) $cart->product_count,
                'cartValue'      => (float) $cart->cart_value,
                'lastActivity'   => $cart->last_activity,
                'abandonedSince' => $abandoned_since,
                'cartData'       => json_decode($cart->cart_data, true) ?: [],
            ];
        }, $carts);

        return new WP_REST_Response([
            'carts'      => $data,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $per_page,
            'totalPages' => $total_pages,
        ], 200);
    }

    private function parse_filter_minutes(string $filter): int
    {
        $map = [
            '5min'  => 5,
            '10min' => 10,
            '30min' => 30,
        ];
        return $map[$filter] ?? 0;
    }

    public function get_customer(WP_REST_Request $request): WP_REST_Response
    {
        $mobile = $request->get_param('mobile');

        if (!preg_match('/^[0-9]{10,15}$/', $mobile)) {
            return new WP_REST_Response(['message' => 'Invalid mobile number'], 400);
        }

        $history = $this->db->get_customer_history($mobile);

        if (empty($history)) {
            return new WP_REST_Response(['message' => 'Customer not found'], 404);
        }

        return new WP_REST_Response($history, 200);
    }

    public function get_revenue_trend(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $days = (int) $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(p.post_date) as date, SUM(pm.meta_value) as revenue
            FROM {$wpdb->prefix}posts p
            INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_date >= %s
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND pm.meta_key = '_order_total'
            GROUP BY DATE(p.post_date)
            ORDER BY date ASC",
            $start_date
        ));

        $trend = [];
        $current = new DateTime($start_date);
        $end = new DateTime();

        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');
            $revenue = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $revenue = (float) $row->revenue;
                    break;
                }
            }
            $trend[] = ['date' => $date_str, 'revenue' => $revenue];
            $current->modify('+1 day');
        }

        return new WP_REST_Response($trend, 200);
    }

    public function get_orders_trend(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $days = (int) $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) as date, COUNT(*) as count
            FROM {$wpdb->prefix}posts
            WHERE post_type = 'shop_order'
            AND post_date >= %s
            GROUP BY DATE(post_date)
            ORDER BY date ASC",
            $start_date
        ));

        $trend = [];
        $current = new DateTime($start_date);
        $end = new DateTime();

        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');
            $count = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $count = (int) $row->count;
                    break;
                }
            }
            $trend[] = ['date' => $date_str, 'orders' => $count];
            $current->modify('+1 day');
        }

        return new WP_REST_Response($trend, 200);
    }

    public function get_abandoned_trend(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $days = (int) $request->get_param('days') ?: 7;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $table = $this->db->get_table('carts');

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(last_activity) as date, COUNT(*) as count
            FROM {$table}
            WHERE status = 'active'
            AND converted_to_order_id = 0
            AND last_activity >= %s
            GROUP BY DATE(last_activity)
            ORDER BY date ASC",
            $start_date
        ));

        $trend = [];
        $current = new DateTime($start_date);
        $end = new DateTime();

        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');
            $count = 0;
            foreach ($results as $row) {
                if ($row->date === $date_str) {
                    $count = (int) $row->count;
                    break;
                }
            }
            $trend[] = ['date' => $date_str, 'carts' => $count];
            $current->modify('+1 day');
        }

        return new WP_REST_Response($trend, 200);
    }

    public function get_top_products(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $limit = (int) $request->get_param('limit') ?: 10;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT oim.meta_value as product_id, COUNT(*) as count
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            GROUP BY oim.meta_value
            ORDER BY count DESC
            LIMIT %d",
            $limit
        ));

        $products = [];
        foreach ($results as $row) {
            $product = wc_get_product($row->product_id);
            $products[] = [
                'productId'   => (int) $row->product_id,
                'name'        => $product ? $product->get_name() : 'Unknown',
                'image'       => $product ? wp_get_attachment_url($product->get_image_id()) : '',
                'price'       => $product ? (float) $product->get_price() : 0,
                'orderCount'  => (int) $row->count,
            ];
        }

        return new WP_REST_Response($products, 200);
    }

    public function auth_check(WP_REST_Request $request): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return new WP_REST_Response([
                'authenticated' => false,
                'message'      => 'Not authenticated',
            ], 200);
        }

        return new WP_REST_Response([
            'authenticated' => true,
            'user'         => [
                'id'       => get_current_user_id(),
                'username' => wp_get_current_user()->user_login,
                'roles'    => wp_get_current_user()->roles,
            ],
        ], 200);
    }

    public function debug_endpoint(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        $result = [
            'plugin_active'    => true,
            'db_prefix'        => $wpdb->prefix,
            'wc_keys_table'    => $table,
            'wc_keys_exists'   => (bool) $table_exists,
            'wc_active'        => class_exists('WooCommerce'),
            'php_version'      => phpversion(),
        ];

        if ($table_exists) {
            $row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $result['key_count'] = $row_count;

            // Show first key's structure (first 8 chars only for security)
            $sample = $wpdb->get_row("SELECT key_id, user_id, permissions, LEFT(consumer_key, 12) as key_prefix, LENGTH(consumer_key) as key_len, LENGTH(consumer_secret) as secret_len FROM {$table} ORDER BY key_id DESC LIMIT 1");
            if ($sample) {
                $result['latest_key'] = [
                    'key_id'      => (int) $sample->key_id,
                    'user_id'     => (int) $sample->user_id,
                    'permissions' => $sample->permissions,
                    'key_prefix'  => $sample->key_prefix . '...',
                    'key_len'     => (int) $sample->key_len,
                    'secret_len'  => (int) $sample->secret_len,
                ];
            }

            $key    = $request->get_param('consumer_key');
            $secret = $request->get_param('consumer_secret');
            if (!empty($key) && !empty($secret)) {
                $all_rows = $wpdb->get_results("SELECT key_id, consumer_key, consumer_secret FROM {$table} ORDER BY key_id DESC");
                $wc_api_hash_available = function_exists('wc_api_hash');
                $hashed_key = $wc_api_hash_available ? wc_api_hash($key) : 'N/A';
                $hashed_secret = $wc_api_hash_available ? wc_api_hash($secret) : 'N/A';
                $auth_results = [];
                foreach ($all_rows as $r) {
                    $auth_results[] = [
                        'key_id'             => (int) $r->key_id,
                        'key_plain_match'    => hash_equals($r->consumer_key, $key),
                        'key_wcapihash_match'=> $wc_api_hash_available ? hash_equals($r->consumer_key, $hashed_key) : null,
                        'key_wpcheck'        => wp_check_password($key, $r->consumer_key),
                        'secret_plain_match' => hash_equals($r->consumer_secret, $secret),
                        'secret_wcapihash_match' => $wc_api_hash_available ? hash_equals($r->consumer_secret, $hashed_secret) : null,
                        'secret_wpcheck'     => wp_check_password($secret, $r->consumer_secret),
                    ];
                }
                $result['auth_test'] = [
                    'sent_key_len'  => strlen($key),
                    'sent_key_prefix' => substr($key, 0, 8),
                    'sent_secret_len' => strlen($secret),
                    'sent_secret_prefix' => substr($secret, 0, 4),
                    'wc_api_hash_available' => $wc_api_hash_available,
                    'hashed_key_lookup' => is_string($hashed_key) && $hashed_key !== 'N/A' ? substr($hashed_key, 0, 16) . '...' : $hashed_key,
                    'row_results' => $auth_results,
                ];
            } else {
                $result['auth_test'] = 'no key/secret provided in request';
            }
        }

        // Check sellwin tables
        foreach (['sellwin_carts', 'sellwin_events', 'sellwin_sessions'] as $t) {
            $full = $wpdb->prefix . $t;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
            $result['tables'][$t] = (bool) $exists;
        }

        return new WP_REST_Response($result, 200);
    }
}
