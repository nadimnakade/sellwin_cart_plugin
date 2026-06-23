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

        $abandoned_minutes = (int) get_option('sellwin_abandoned_minutes', 30);
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

        $abandoned_minutes = (int) get_option('sellwin_abandoned_minutes', 30);
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
                            <td><?php echo esc_html($c->mobile ?: '—'); ?></td>
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

        $abandoned_minutes = (int) get_option('sellwin_abandoned_minutes', 30);
        $threshold = date('Y-m-d H:i:s', strtotime("-{$abandoned_minutes} minutes"));
        $country_code = get_option('sellwin_whatsapp_country_code', '91');

        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, s.mobile, s.customer_name, s.woocommerce_user_id
             FROM {$wpdb->prefix}sellwin_carts c
             LEFT JOIN {$wpdb->prefix}sellwin_sessions s ON c.session_id = s.session_id
             WHERE c.updated_at < %s AND c.is_active = 1
             ORDER BY c.updated_at DESC
             LIMIT 100",
            $threshold
        ));
        ?>
        <div class="wrap">
            <h1>Abandoned Carts</h1>
            <p>Customers who added products but haven't returned in <?php echo $abandoned_minutes; ?>+ minutes.</p>
            <?php if (empty($carts)) : ?>
                <p>No abandoned carts found.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed striped">
                    <thead><tr><th>Customer</th><th>Mobile</th><th>Products</th><th>Cart Value</th><th>Abandoned Since</th><th>Actions</th></tr></thead>
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
                        $whatsapp_msg = rawurlencode('Hi, you recently added products to your cart on Sellwin. Can we help you complete your order?');
                    ?>
                        <tr>
                            <td><?php echo esc_html($c->customer_name ?: 'Guest'); ?></td>
                            <td><?php echo esc_html($c->mobile ?: '—'); ?></td>
                            <td><?php echo (int) $products; ?></td>
                            <td>₹<?php echo number_format($cart_value, 0, '.', ','); ?></td>
                            <td><?php echo $ago; ?></td>
                            <td>
                                <?php if ($c->mobile) : ?>
                                    <a href="https://wa.me/<?php echo esc_attr($country_code . $c->mobile); ?>?text=<?php echo $whatsapp_msg; ?>" target="_blank" class="button button-primary">Recover via WhatsApp</a>
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

    public static function render_settings_page(): void
    {
        if (isset($_POST['sellwin_save_settings'])) {
            check_admin_referer('sellwin_settings');
            update_option('sellwin_abandoned_minutes', (int) ($_POST['abandoned_minutes'] ?? 30));
            update_option('sellwin_cleanup_days', (int) ($_POST['cleanup_days'] ?? 90));
            update_option('sellwin_whatsapp_country_code', sanitize_text_field($_POST['whatsapp_country_code'] ?? '91'));
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $abandoned_minutes    = get_option('sellwin_abandoned_minutes', 30);
        $cleanup_days         = get_option('sellwin_cleanup_days', 90);
        $whatsapp_country_code = get_option('sellwin_whatsapp_country_code', '91');
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
}
