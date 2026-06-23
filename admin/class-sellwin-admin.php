<?php
defined('ABSPATH') || exit;

class Sellwin_Admin
{
    public static function add_admin_menu(): void
    {
        $capability = (class_exists('WooCommerce') && current_user_can('manage_woocommerce'))
            ? 'manage_woocommerce'
            : 'manage_options';

        add_menu_page(
            'Sellwin Tracker',
            'Sellwin',
            $capability,
            'sellwin-dashboard',
            [self::class, 'render_dashboard_page'],
            'dashicons-chart-line',
            55
        );

        add_submenu_page(
            'sellwin-dashboard',
            'Dashboard',
            'Dashboard',
            $capability,
            'sellwin-dashboard',
            [self::class, 'render_dashboard_page']
        );

        add_submenu_page(
            'sellwin-dashboard',
            'Active Carts',
            'Active Carts',
            $capability,
            'sellwin-active-carts',
            [self::class, 'render_active_carts_page']
        );

        add_submenu_page(
            'sellwin-dashboard',
            'Abandoned Carts',
            'Abandoned Carts',
            $capability,
            'sellwin-abandoned-carts',
            [self::class, 'render_abandoned_carts_page']
        );

        add_submenu_page(
            'sellwin-dashboard',
            'Orders',
            'Orders',
            $capability,
            'sellwin-orders',
            [self::class, 'render_orders_page']
        );

        add_submenu_page(
            'sellwin-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'sellwin-settings',
            [self::class, 'render_settings_page']
        );
    }

    public static function add_action_links(array $links): array
    {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=sellwin-dashboard') . '">Dashboard</a>',
            '<a href="' . admin_url('admin.php?page=sellwin-settings') . '">Settings</a>',
        ];
        return array_merge($plugin_links, $links);
    }

    public static function render_dashboard_page(): void
    {
        global $wpdb;

        $today = current_time('Y-m-d');

        $orders_today = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
             WHERE p.post_type = 'shop_order'
               AND DATE(p.post_date) = '{$today}'
               AND p.post_status NOT IN ('trash', 'cancelled', 'failed')"
        );

        $revenue_today = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(pm.meta_value), 0) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
             WHERE p.post_type = 'shop_order'
               AND DATE(p.post_date) = '{$today}'
               AND p.post_status NOT IN ('trash', 'cancelled', 'failed')"
        );

        $abandoned_minutes = (int) get_option('sellwin_abandoned_minutes', 5);
        $threshold = date('Y-m-d H:i:s', strtotime("-{$abandoned_minutes} minutes"));

        $active_carts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}sellwin_carts
             WHERE updated_at >= '{$threshold}' AND is_active = 1"
        );

        $abandoned_carts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}sellwin_carts
             WHERE updated_at < '{$threshold}' AND is_active = 1"
        );

        $total_sessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_sessions");
        $total_events   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_events");
        ?>
        <div class="wrap">
            <h1>Sellwin Cart Tracker - Dashboard</h1>
            <div class="sellwin-stats-grid">
                <div class="sellwin-stat-card">
                    <h3>Revenue Today</h3>
                    <div class="value">₹<?php echo number_format($revenue_today, 0, '.', ','); ?></div>
                </div>
                <div class="sellwin-stat-card">
                    <h3>Orders Today</h3>
                    <div class="value"><?php echo (int) $orders_today; ?></div>
                </div>
                <div class="sellwin-stat-card">
                    <h3>Active Carts</h3>
                    <div class="value"><?php echo $active_carts; ?></div>
                </div>
                <div class="sellwin-stat-card">
                    <h3>Abandoned Carts</h3>
                    <div class="value"><?php echo $abandoned_carts; ?></div>
                </div>
            </div>
            <h2>Database Overview</h2>
            <table class="widefat striped" style="max-width:400px;">
                <tr><td>Total Sessions</td><td><?php echo $total_sessions; ?></td></tr>
                <tr><td>Total Events</td><td><?php echo $total_events; ?></td></tr>
            </table>
            <style>
                .sellwin-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin: 20px 0; }
                .sellwin-stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
                .sellwin-stat-card h3 { margin: 0 0 5px 0; color: #64748b; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; }
                .sellwin-stat-card .value { font-size: 32px; font-weight: 700; color: #0f172a; }
            </style>
        </div>
        <?php
    }

    public static function render_active_carts_page(): void
    {
        global $wpdb;

        $abandoned_minutes = (int) get_option('sellwin_abandoned_minutes', 5);
        $threshold = date('Y-m-d H:i:s', strtotime("-{$abandoned_minutes} minutes"));
        $country_code = get_option('sellwin_whatsapp_country_code', '91');

        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, s.mobile, s.customer_name, s.woocommerce_user_id
             FROM {$wpdb->prefix}sellwin_carts c
             LEFT JOIN {$wpdb->prefix}sellwin_sessions s ON c.session_id = s.session_id
             WHERE c.updated_at >= %s AND c.is_active = 1
             ORDER BY c.updated_at DESC",
            $threshold
        ));
        ?>
        <div class="wrap">
            <h1>Active Carts</h1>
            <p>Customers with products in their cart, active within the last <?php echo $abandoned_minutes; ?> minutes.</p>
            <?php if (empty($carts)) : ?>
                <p>No active carts found.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed striped">
                    <thead><tr><th>Customer</th><th>Mobile</th><th>Products</th><th>Cart Value</th><th>Last Activity</th><th>WhatsApp</th></tr></thead>
                    <tbody>
                    <?php foreach ($carts as $c) :
                        $products = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_events WHERE session_id = %s AND event_type IN ('add_to_cart', 'cart_update')",
                            $c->session_id
                        ));
                        $cart_value = (float) $wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(CAST(meta_value AS DECIMAL(10,2))), 0) FROM {$wpdb->prefix}sellwin_events WHERE session_id = %s AND event_type = 'add_to_cart' AND meta_key = 'price'",
                            $c->session_id
                        ));
                        $ago = human_time_diff(strtotime($c->updated_at), current_time('timestamp')) . ' ago';
                    ?>
                        <tr>
                            <td><?php echo esc_html($c->customer_name ?: 'Guest'); ?></td>
                            <td>
                                <?php if ($c->mobile) : ?>
                                    <a href="https://wa.me/<?php echo esc_attr($country_code . $c->mobile); ?>" target="_blank" style="text-decoration:none;color:#25D366;font-weight:600;" title="Chat on WhatsApp">
                                        <?php echo esc_html($c->mobile); ?>
                                    </a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $products; ?></td>
                            <td>₹<?php echo number_format($cart_value, 0, '.', ','); ?></td>
                            <td><?php echo $ago; ?></td>
                            <td>
                                <?php if ($c->mobile) : ?>
                                    <a href="https://wa.me/<?php echo esc_attr($country_code . $c->mobile); ?>" target="_blank" class="button">WhatsApp</a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_abandoned_carts_page(): void
    {
        global $wpdb;

        $country_code = get_option('sellwin_whatsapp_country_code', '91');
        $filter   = sanitize_text_field($_GET['filter'] ?? '30min');
        $search   = sanitize_text_field($_GET['s'] ?? '');
        $sort     = sanitize_text_field($_GET['sort'] ?? 'updated_at');
        $order    = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = 20;
        $page     = max(1, (int) ($_GET['paged'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $filters = [
            '5min'   => ['label' => 'Last 5 min', 'minutes' => 5],
            '10min'  => ['label' => 'Last 10 min', 'minutes' => 10],
            '30min'  => ['label' => 'Last 30 min', 'minutes' => 30],
            'today'  => ['label' => 'Today', 'minutes' => 0],
            'week'   => ['label' => 'This Week', 'minutes' => 0],
            'all'    => ['label' => 'All Time', 'minutes' => -1],
        ];

        if (!isset($filters[$filter])) {
            $filter = '30min';
        }

        $allowed_sorts = ['updated_at', 'cart_value', 'product_count', 'customer_name', 'mobile'];
        if (!in_array($sort, $allowed_sorts, true)) {
            $sort = 'updated_at';
        }

        // Build WHERE
        $where_parts = ['c.is_active = 1'];
        $params = [];

        if ($filter === 'all') {
            // no time constraint
        } elseif ($filter === 'today') {
            $where_parts[] = 'c.updated_at >= %s';
            $params[] = current_time('Y-m-d') . ' 00:00:00';
        } elseif ($filter === 'week') {
            $monday = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
            $where_parts[] = 'c.updated_at >= %s';
            $params[] = $monday;
        } else {
            $minutes = $filters[$filter]['minutes'];
            $threshold = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
            $where_parts[] = 'c.updated_at < %s';
            $params[] = $threshold;
        }

        if ($search) {
            $where_parts[] = '(s.mobile LIKE %s OR s.customer_name LIKE %s OR c.name LIKE %s)';
            $search_pattern = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_pattern;
            $params[] = $search_pattern;
            $params[] = $search_pattern;
        }

        $where_sql = implode(' AND ', $where_parts);

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_carts c LEFT JOIN {$wpdb->prefix}sellwin_sessions s ON c.session_id = s.session_id WHERE {$where_sql}";
        $total = empty($params) ? (int) $wpdb->get_var($count_sql) : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        $total_pages = max(1, ceil($total / $per_page));

        // Sort mapping
        $sort_map = [
            'updated_at'    => 'c.updated_at',
            'cart_value'    => 'c.cart_value',
            'product_count' => 'c.product_count',
            'customer_name' => 's.customer_name',
            'mobile'        => 's.mobile',
        ];
        $sort_col = $sort_map[$sort] ?? 'c.updated_at';

        // Fetch
        $params[] = $per_page;
        $params[] = $offset;
        $data_sql = "SELECT c.*, s.mobile, s.customer_name, s.woocommerce_user_id
                     FROM {$wpdb->prefix}sellwin_carts c
                     LEFT JOIN {$wpdb->prefix}sellwin_sessions s ON c.session_id = s.session_id
                     WHERE {$where_sql}
                     ORDER BY {$sort_col} {$order}
                     LIMIT %d OFFSET %d";
        $carts = $wpdb->get_results($wpdb->prepare($data_sql, ...$params));

        // Build base URL for sorting/pagination
        $base_args = ['page' => 'sellwin-abandoned-carts', 'filter' => $filter, 's' => $search];
        $sort_url = function ($col) use ($base_args, $sort, $order) {
            $new_order = ($sort === $col && $order === 'DESC') ? 'ASC' : 'DESC';
            return admin_url('admin.php?' . http_build_query(array_merge($base_args, ['sort' => $col, 'order' => $new_order])));
        };
        $sort_icon = function ($col) use ($sort, $order) {
            if ($sort !== $col) return '';
            return $order === 'ASC' ? ' &#9650;' : ' &#9660;';
        };
        ?>
        <div class="wrap">
            <h1>Abandoned Carts <span style="font-size:14px;color:#666;">(<?php echo $total; ?> found)</span></h1>

            <!-- Filters -->
            <div style="margin: 15px 0; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <?php foreach ($filters as $key => $f) :
                    $is_active = ($key === $filter) ? 'button-primary' : '';
                    $url = admin_url('admin.php?' . http_build_query(array_merge($base_args, ['filter' => $key, 'sort' => $sort, 'order' => $order])));
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="button <?php echo $is_active; ?>"><?php echo esc_html($f['label']); ?></a>
                <?php endforeach; ?>

                <form method="get" style="display:flex;gap:5px;margin-left:auto;">
                    <input type="hidden" name="page" value="sellwin-abandoned-carts">
                    <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                    <input type="hidden" name="sort" value="<?php echo esc_attr($sort); ?>">
                    <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by name or phone..." class="regular-text">
                    <button type="submit" class="button">Search</button>
                    <?php if ($search) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?' . http_build_query(array_merge($base_args, ['sort' => $sort, 'order' => $order])))); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($carts)) : ?>
                <p>No abandoned carts found for this filter.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed striped">
                    <thead>
                        <tr>
                            <th style="width:180px;"><a href="<?php echo esc_url($sort_url('customer_name')); ?>">Customer<?php echo $sort_icon('customer_name'); ?></a></th>
                            <th style="width:130px;"><a href="<?php echo esc_url($sort_url('mobile')); ?>">Phone<?php echo $sort_icon('mobile'); ?></a></th>
                            <th style="width:80px;"><a href="<?php echo esc_url($sort_url('product_count')); ?>">Products<?php echo $sort_icon('product_count'); ?></a></th>
                            <th style="width:100px;"><a href="<?php echo esc_url($sort_url('cart_value')); ?>">Cart Value<?php echo $sort_icon('cart_value'); ?></a></th>
                            <th style="width:110px;"><a href="<?php echo esc_url($sort_url('updated_at')); ?>">Last Activity<?php echo $sort_icon('updated_at'); ?></a></th>
                            <th>Cart Items</th>
                            <th style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($carts as $c) :
                        $events = $wpdb->get_results($wpdb->prepare(
                            "SELECT product_name, quantity FROM {$wpdb->prefix}sellwin_events WHERE session_id = %s AND event_type IN ('add_to_cart', 'cart_update') ORDER BY created_at DESC",
                            $c->session_id
                        ));
                        $cart_value = (float) $wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(CAST(meta_value AS DECIMAL(10,2))), 0) FROM {$wpdb->prefix}sellwin_events WHERE session_id = %s AND event_type = 'add_to_cart' AND meta_key = 'price'",
                            $c->session_id
                        ));
                        $ago = human_time_diff(strtotime($c->updated_at), current_time('timestamp'));
                        $has_phone = !empty($c->mobile);
                        $item_names = [];
                        foreach ($events as $ev) {
                            $item_names[] = esc_html($ev->product_name) . ' &times; ' . (int) $ev->quantity;
                        }
                        $item_list = implode(', ', $item_names);
                        $item_title = strip_tags($item_list);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($c->customer_name ?: 'Guest'); ?></strong></td>
                            <td>
                                <?php if ($has_phone) : ?>
                                    <a href="https://wa.me/<?php echo esc_attr($country_code . $c->mobile); ?>?text=<?php echo rawurlencode('Hi, you recently added products to your cart on Sellwin. Can we help you complete your order?'); ?>" target="_blank" style="text-decoration:none;color:#25D366;font-weight:600;" title="Chat on WhatsApp">
                                        <?php echo esc_html($c->mobile); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $c->product_count; ?></td>
                            <td><strong>₹<?php echo number_format($cart_value, 0, '.', ','); ?></strong></td>
                            <td><?php echo $ago; ?> ago</td>
                            <td title="<?php echo esc_attr($item_title); ?>" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $item_list ?: '—' ?></td>
                            <td>
                                <?php if ($has_phone) : ?>
                                    <a href="https://wa.me/<?php echo esc_attr($country_code . $c->mobile); ?>?text=<?php echo rawurlencode('Hi, you recently added products to your cart on Sellwin. Can we help you complete your order?'); ?>" target="_blank" class="button button-primary" style="margin-bottom:2px;">
                                        WhatsApp
                                    </a>
                                <?php endif; ?>
                                <a href="tel:<?php echo esc_attr($c->mobile); ?>" class="button" style="margin-bottom:2px;<?php echo !$has_phone ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
                                    Call
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            $pagination_args = [
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $total_pages,
                                'prev_text' => '&laquo; Previous',
                                'next_text' => 'Next &raquo;',
                            ];
                            echo paginate_links($pagination_args);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_orders_page(): void
    {
        global $wpdb;

        $per_page = 20;
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($page - 1) * $per_page;
        $search = sanitize_text_field($_GET['s'] ?? '');
        $status = sanitize_text_field($_GET['status'] ?? '');

        $where = "p.post_type = 'shop_order'";
        $params = [];

        if ($search) {
            $where .= " AND (p.ID = %d OR p.ID IN (
                SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_phone', '_billing_email')
            ))";
            $params[] = $search;
            $params[] = $search;
        }

        if ($status) {
            $where .= " AND p.post_status = %s";
            $params[] = 'wc-' . $status;
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}");
        $total_pages = ceil($total / $per_page);

        $params[] = $per_page;
        $params[] = $offset;
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_date, p.post_status FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.post_date DESC LIMIT %d OFFSET %d",
            ...$params
        ));

        $status_labels = [
            'wc-processing' => 'Processing',
            'wc-on-hold' => 'On Hold',
            'wc-completed' => 'Completed',
            'wc-cancelled' => 'Cancelled',
            'wc-refunded' => 'Refunded',
            'wc-failed' => 'Failed',
            'wc-pending' => 'Pending',
        ];
        ?>
        <div class="wrap">
            <h1>Orders</h1>

            <div style="margin: 15px 0; display: flex; gap: 10px; align-items: center;">
                <form method="get" style="display:flex;gap:5px;">
                    <input type="hidden" name="page" value="sellwin-orders">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by name, phone, email, or order #" class="regular-text">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="on-hold" <?php echo $status === 'on-hold' ? 'selected' : ''; ?>>On Hold</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                    <button type="submit" class="button">Search</button>
                </form>
                <span style="color:#666;"><?php echo $total; ?> orders found</span>
            </div>

            <?php if (empty($orders)) : ?>
                <p>No orders found.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o) :
                        $order = wc_get_order($o->ID);
                        if (!$order) continue;
                        $status_class = 'status-' . $order->get_status();
                        $phone = $order->get_billing_phone();
                        $invoice_url = add_query_arg([
                            'sellwin_invoice' => 1,
                            'order_id'        => $order->get_id(),
                            'consumer_key'    => get_option('sellwin_consumer_key', ''),
                            'consumer_secret' => get_option('sellwin_consumer_secret', ''),
                        ], home_url('/'));
                    ?>
                        <tr>
                            <td><strong><?php echo $order->get_order_number(); ?></strong></td>
                            <td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                            <td>
                                <?php if ($phone) : ?>
                                    <a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($order->get_billing_email() ?: '—'); ?></td>
                            <td><?php echo $order->get_date_created() ? $order->get_date_created()->format('d M Y, h:i A') : '—'; ?></td>
                            <td><span class="<?php echo $status_class; ?>" style="padding:3px 8px;border-radius:3px;font-size:12px;background:<?php echo $order->get_status() === 'completed' ? '#d4edda' : ($order->get_status() === 'processing' ? '#cce5ff' : '#f8f9fa'); ?>;"><?php echo esc_html($order->get_status()); ?></span></td>
                            <td class="text-right"><strong><?php echo wc_price($order->get_total()); ?></strong></td>
                            <td>
                                <a href="<?php echo admin_url("post.php?post={$order->get_id()}&action=edit"); ?>" class="button" style="margin-bottom:2px;">View</a>
                                <a href="<?php echo esc_url($invoice_url); ?>" class="button button-primary" target="_blank" style="margin-bottom:2px;">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links([
                                'base'    => add_query_arg('paged', '%#%'),
                                'format'  => '',
                                'current' => $page,
                                'total'   => $total_pages,
                                'prev_text' => '&laquo; Previous',
                                'next_text' => 'Next &raquo;',
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_settings_page(): void
    {
        if (isset($_POST['sellwin_save_settings'])) {
            check_admin_referer('sellwin_settings');
            update_option('sellwin_abandoned_minutes', (int) ($_POST['abandoned_minutes'] ?? 30));
            update_option('sellwin_cleanup_days', (int) ($_POST['cleanup_days'] ?? 90));
            update_option('sellwin_whatsapp_country_code', sanitize_text_field($_POST['whatsapp_country_code'] ?? '91'));
            update_option('sellwin_consumer_key', sanitize_text_field($_POST['sellwin_consumer_key'] ?? ''));
            update_option('sellwin_consumer_secret', sanitize_text_field($_POST['sellwin_consumer_secret'] ?? ''));
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $abandoned_minutes    = get_option('sellwin_abandoned_minutes', 5);
        $cleanup_days         = get_option('sellwin_cleanup_days', 90);
        $whatsapp_country_code = get_option('sellwin_whatsapp_country_code', '91');
        $consumer_key    = get_option('sellwin_consumer_key', '');
        $consumer_secret = get_option('sellwin_consumer_secret', '');
        ?>
        <div class="wrap">
            <h1>Sellwin Cart Tracker - Settings</h1>
            <form method="post">
                <?php wp_nonce_field('sellwin_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="abandoned_minutes">Abandoned Cart Threshold (minutes)</label></th>
                        <td><input type="number" id="abandoned_minutes" name="abandoned_minutes" value="<?php echo esc_attr($abandoned_minutes); ?>" min="1" max="1440" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="cleanup_days">Auto Cleanup (days)</label></th>
                        <td><input type="number" id="cleanup_days" name="cleanup_days" value="<?php echo esc_attr($cleanup_days); ?>" min="7" max="365" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="whatsapp_country_code">WhatsApp Country Code</label></th>
                        <td><input type="text" id="whatsapp_country_code" name="whatsapp_country_code" value="<?php echo esc_attr($whatsapp_country_code); ?>" maxlength="5" class="small-text"> e.g. 91 for India</td>
                    </tr>
                    <tr>
                        <th><label for="sellwin_consumer_key">WooCommerce Consumer Key</label></th>
                        <td><input type="text" id="sellwin_consumer_key" name="sellwin_consumer_key" value="<?php echo esc_attr($consumer_key); ?>" class="large-text" placeholder="ck_..."> Used for invoice PDF links</td>
                    </tr>
                    <tr>
                        <th><label for="sellwin_consumer_secret">WooCommerce Consumer Secret</label></th>
                        <td><input type="password" id="sellwin_consumer_secret" name="sellwin_consumer_secret" value="<?php echo esc_attr($consumer_secret); ?>" class="large-text" placeholder="cs_..."> Used for invoice PDF links</td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="sellwin_save_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>
            <hr>
            <h2>Database Stats</h2>
            <?php
            global $wpdb;
            $sessions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_sessions");
            $events   = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_events");
            $carts    = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sellwin_carts");
            ?>
            <table class="widefat striped" style="max-width:400px;">
                <tr><td>Sessions</td><td><?php echo (int) $sessions; ?></td></tr>
                <tr><td>Events</td><td><?php echo (int) $events; ?></td></tr>
                <tr><td>Carts</td><td><?php echo (int) $carts; ?></td></tr>
            </table>
        </div>
        <?php
    }

    public static function render_html_invoice($order): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $items = $order->get_items();
        $site_name = get_bloginfo('name');
        $site_url  = home_url();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Invoice #<?php echo $order->get_order_number(); ?> - <?php echo esc_html($site_name); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1a1a1a; padding: 40px; background: #fff; }
                .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; border-bottom: 3px solid #2563eb; padding-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: 700; color: #2563eb; }
                .company-url { font-size: 12px; color: #666; margin-top: 4px; }
                .invoice-title { font-size: 28px; font-weight: 700; color: #2563eb; text-align: right; }
                .invoice-number { font-size: 14px; color: #666; margin-top: 4px; }
                .invoice-meta { display: flex; justify-content: space-between; margin-bottom: 30px; }
                .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; width: 48%; }
                .meta-box h4 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; margin-bottom: 8px; }
                .meta-box p { font-size: 13px; line-height: 1.6; }
                .meta-box .name { font-weight: 600; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
                thead th { background: #1e293b; color: #fff; padding: 10px 14px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
                tbody td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
                tbody tr:nth-child(even) { background: #f8fafc; }
                .text-right { text-align: right; }
                .totals { width: 320px; margin-left: auto; }
                .totals tr td { padding: 6px 14px; font-size: 13px; }
                .totals tr.total td { border-top: 2px solid #1e293b; font-weight: 700; font-size: 16px; background: #f1f5f9; }
                .footer { margin-top: 40px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 16px; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="invoice-header">
                <div>
                    <div class="company-name"><?php echo esc_html($site_name); ?></div>
                    <div class="company-url"><?php echo esc_html($site_url); ?></div>
                </div>
                <div>
                    <div class="invoice-title">INVOICE</div>
                    <div class="invoice-number">#<?php echo $order->get_order_number(); ?></div>
                </div>
            </div>
            <div class="invoice-meta">
                <div class="meta-box">
                    <h4>Bill To</h4>
                    <p class="name"><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></p>
                    <p><?php echo esc_html($order->get_billing_address_1()); ?></p>
                    <?php if ($order->get_billing_address_2()) : ?>
                        <p><?php echo esc_html($order->get_billing_address_2()); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode()); ?></p>
                    <p><?php echo esc_html($order->get_billing_country()); ?></p>
                    <?php if ($order->get_billing_phone()) : ?>
                        <p>Phone: <?php echo esc_html($order->get_billing_phone()); ?></p>
                    <?php endif; ?>
                    <?php if ($order->get_billing_email()) : ?>
                        <p>Email: <?php echo esc_html($order->get_billing_email()); ?></p>
                    <?php endif; ?>
                </div>
                <div class="meta-box">
                    <h4>Order Details</h4>
                    <p><strong>Order Date:</strong> <?php echo $order->get_date_created() ? $order->get_date_created()->format('d M Y, h:i A') : '—'; ?></p>
                    <p><strong>Payment:</strong> <?php echo esc_html($order->get_payment_method_title()); ?></p>
                    <p><strong>Status:</strong> <?php echo esc_html(ucfirst($order->get_status())); ?></p>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>#</th><th>Item</th><th>SKU</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($items as $item) :
                    $product = $item->get_product();
                ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo esc_html($item->get_name()); ?></td>
                        <td><?php echo $product ? esc_html($product->get_sku()) : '—'; ?></td>
                        <td class="text-right"><?php echo $item->get_quantity(); ?></td>
                        <td class="text-right"><?php echo wc_price($item->get_subtotal() / max(1, $item->get_quantity())); ?></td>
                        <td class="text-right"><?php echo wc_price($item->get_subtotal()); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <table class="totals">
                <tr><td>Subtotal:</td><td class="text-right"><?php echo wc_price($order->get_subtotal()); ?></td></tr>
                <?php if ((float) $order->get_discount_total() > 0) : ?>
                <tr><td>Discount:</td><td class="text-right">-<?php echo wc_price($order->get_discount_total()); ?></td></tr>
                <?php endif; ?>
                <?php if ((float) $order->get_shipping_total() > 0) : ?>
                <tr><td>Shipping:</td><td class="text-right"><?php echo wc_price($order->get_shipping_total()); ?></td></tr>
                <?php endif; ?>
                <?php if ((float) $order->get_total_tax() > 0) : ?>
                <tr><td>Tax:</td><td class="text-right"><?php echo wc_price($order->get_total_tax()); ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td>Total:</td><td class="text-right"><?php echo wc_price($order->get_total()); ?></td></tr>
            </table>
            <div class="footer"><?php echo esc_html($site_name); ?> &bull; <?php echo esc_html($site_url); ?></div>
            <script>
                // Auto-trigger print dialog for PDF save (Ctrl+P / Cmd+P)
                window.onload = function() {
                    setTimeout(function() { window.print(); }, 500);
                };
            </script>
        </body>
        </html>
        <?php
    }
}
