<?php

class CartBounty_REST_API {

    protected $namespace = 'sellwin/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/carts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_carts' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/abandoned-carts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_carts' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/active-carts', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_active_carts' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/carts/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_cart' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/carts/(?P<id>\d+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_status' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/carts/(?P<id>\d+)/whatsapp', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'mark_whatsapp_contacted' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/carts/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_cart' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/stats', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_stats' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/customer/(?P<mobile>[^/]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_customer' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/abandoned-trend', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_abandoned_trend' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/export/csv', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'export_csv' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
        register_rest_route( $this->namespace, '/health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'health_check' ),
            'permission_callback' => '__return_true',
        ));
        register_rest_route( $this->namespace, '/carts/(?P<id>\d+)/download', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'download_cart' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ));
    }

    public function check_permission( $request = null ) {
        if ( is_user_logged_in() ) {
            return true;
        }

        $key = '';
        $secret = '';

        if ( $request instanceof WP_REST_Request ) {
            $key = $request->get_param( 'consumer_key' );
            $secret = $request->get_param( 'consumer_secret' );
        }

        if ( empty( $key ) ) {
            $key = isset( $_GET['consumer_key'] ) ? sanitize_text_field( $_GET['consumer_key'] ) : '';
            $secret = isset( $_GET['consumer_secret'] ) ? sanitize_text_field( $_GET['consumer_secret'] ) : '';
        }

        if ( empty( $key ) && isset( $_SERVER['PHP_AUTH_USER'] ) ) {
            $key = $_SERVER['PHP_AUTH_USER'];
            $secret = isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '';
        }

        if ( ! empty( $key ) && ! empty( $secret ) ) {
            return $this->is_valid_wc_key( $key, $secret );
        }

        return false;
    }

    private function is_valid_wc_key( $key, $secret ) {
        global $wpdb;

        $table = $wpdb->prefix . 'woocommerce_api_keys';

        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( ! $table_exists ) {
            return false;
        }

        // WooCommerce stores keys using wc_api_hash() — use it directly for lookup
        if ( function_exists( 'wc_api_hash' ) ) {
            $hashed_key = wc_api_hash( $key );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT user_id, permissions, consumer_secret FROM {$table} WHERE consumer_key = %s LIMIT 1",
                $hashed_key
            ) );

            if ( $row && hash_equals( $row->consumer_secret, wc_api_hash( $secret ) ) ) {
                return in_array( $row->permissions, array( 'read', 'write', 'read_write' ), true );
            }
        }

        // Fallback: scan all rows with multiple comparison methods
        $hashed_key = function_exists( 'wc_api_hash' ) ? wc_api_hash( $key ) : hash( 'sha256', $key );

        $rows = $wpdb->get_results(
            "SELECT key_id, user_id, permissions, consumer_key, consumer_secret FROM {$table} ORDER BY key_id DESC"
        );

        foreach ( $rows as $row ) {
            $stored_key    = $row->consumer_key;
            $stored_secret = $row->consumer_secret;

            $key_matches = hash_equals( $stored_key, $key )
                || hash_equals( $stored_key, $hashed_key )
                || wp_check_password( $key, $stored_key );

            $secret_matches = hash_equals( $stored_secret, $secret )
                || ( function_exists( 'wc_api_hash' ) && hash_equals( $stored_secret, wc_api_hash( $secret ) ) )
                || wp_check_password( $secret, $stored_secret );

            if ( $key_matches && $secret_matches ) {
                return in_array( $row->permissions, array( 'read', 'write', 'read_write' ), true );
            }
        }

        return false;
    }

    private function get_table() {
        global $wpdb;
        return $wpdb->prefix . CARTBOUNTY_TABLE_NAME;
    }

    private function build_products( $cart_contents ) {
        $products = array();
        $cart_contents = maybe_unserialize( $cart_contents );
        if ( is_array( $cart_contents ) ) {
            foreach ( $cart_contents as $item ) {
                if ( isset( $item['product_id'] ) ) {
                    $thumbnail = get_the_post_thumbnail_url( $item['product_id'], 'thumbnail' );
                    $products[] = array(
                        'product_id' => (int) $item['product_id'],
                        'title'      => isset( $item['product_title'] ) ? $item['product_title'] : '',
                        'quantity'   => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
                        'price'      => isset( $item['product_price'] ) ? (float) $item['product_price'] : 0,
                        'thumbnail'  => $thumbnail ? $thumbnail : '',
                    );
                }
            }
        }
        return $products;
    }

    private function enrich_customer_fields( $row ) {
        if ( ! is_array( $row ) || empty( $row['session_id'] ) || ! ctype_digit( (string) $row['session_id'] ) ) {
            return $row;
        }

        $user_id = absint( $row['session_id'] );
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return $row;
        }

        if ( empty( $row['name'] ) ) {
            $row['name'] = get_user_meta( $user_id, 'billing_first_name', true );
            if ( empty( $row['name'] ) ) {
                $row['name'] = $user->first_name ? $user->first_name : $user->display_name;
            }
        }

        if ( empty( $row['surname'] ) ) {
            $row['surname'] = get_user_meta( $user_id, 'billing_last_name', true );
            if ( empty( $row['surname'] ) ) {
                $row['surname'] = $user->last_name;
            }
        }

        if ( empty( $row['email'] ) ) {
            $billing_email = get_user_meta( $user_id, 'billing_email', true );
            $row['email'] = $billing_email ? $billing_email : $user->user_email;
        }

        if ( empty( $row['phone'] ) ) {
            $billing_phone = get_user_meta( $user_id, 'billing_phone', true );
            $sellwin_mobile = get_user_meta( $user_id, 'sellwin_mobile', true );
            if ( $billing_phone ) {
                $row['phone'] = $billing_phone;
            } elseif ( $sellwin_mobile ) {
                $row['phone'] = $sellwin_mobile;
            } elseif ( preg_match( '/^[+0-9\s().-]{7,30}$/', $user->user_login ) ) {
                $row['phone'] = $user->user_login;
            }
        }

        return $row;
    }

    public function get_carts( $request ) {
        $table = $this->get_table();
        global $wpdb;

        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $page     = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?: '' );
        $filter   = sanitize_text_field( $request->get_param( 'status' ) ?: $request->get_param( 'filter' ) ?: '' );
        $idle_minutes = max( 1, absint( $request->get_param( 'idle_minutes' ) ) ?: 1 );
        $time_range = sanitize_text_field( $request->get_param( 'time_range' ) ?: '' );
        $offset   = ( $page - 1 ) * $per_page;

        $now_timestamp = current_time( 'timestamp' );
        $idle_threshold = date( 'Y-m-d H:i:s', $now_timestamp - ( $idle_minutes * MINUTE_IN_SECONDS ) );
        $where = "WHERE email != '' AND time <= %s";
        $where_args = array( $idle_threshold );

        // Apply time_range filter (lower bound — oldest cart to include)
        $time_from = '';
        switch ( $time_range ) {
            case '5m':
                $time_from = date( 'Y-m-d H:i:s', $now_timestamp - ( 5 * MINUTE_IN_SECONDS ) );
                break;
            case '1h':
                $time_from = date( 'Y-m-d H:i:s', $now_timestamp - HOUR_IN_SECONDS );
                break;
            case 'today':
                $time_from = date( 'Y-m-d 00:00:00', $now_timestamp );
                break;
            case 'week':
                $time_from = date( 'Y-m-d H:i:s', $now_timestamp - ( 7 * DAY_IN_SECONDS ) );
                break;
            case 'month':
                $time_from = date( 'Y-m-d H:i:s', $now_timestamp - ( 30 * DAY_IN_SECONDS ) );
                break;
            case 'year':
                $time_from = date( 'Y-m-d H:i:s', $now_timestamp - ( 365 * DAY_IN_SECONDS ) );
                break;
            case 'all':
            default:
                $time_from = '';
                break;
        }

        if ( $time_from ) {
            $where .= " AND time >= %s";
            $where_args[] = $time_from;
        }

        if ( $search ) {
            $where .= " AND (name LIKE %s OR surname LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_args = array_merge( $where_args, array( $like, $like, $like, $like ) );
        }

        if ( $filter === 'recovered' ) {
            $where .= " AND type = 'recovered'";
        } elseif ( $filter === 'recoverable' ) {
            $where .= " AND (type IS NULL OR type != 'recovered')";
        } elseif ( $filter === 'contacted' ) {
            $where .= " AND contacted_status = 'contacted'";
        } elseif ( $filter === 'new' ) {
            $new_minutes = defined( 'CARTBOUNTY_NEW_NOTICE' ) ? CARTBOUNTY_NEW_NOTICE : 240;
            $new_threshold = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $new_minutes * MINUTE_IN_SECONDS ) );
            $where .= " AND time >= %s";
            $where_args[] = $new_threshold;
        }

        $count_sql = "SELECT COUNT(id) FROM $table $where";
        if ( ! empty( $where_args ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $where_args );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        $orderby_param = sanitize_text_field( $request->get_param( 'orderby' ) ?: 'time' );
        $orderby = in_array( $orderby_param, array( 'time', 'cart_total', 'id', 'name' ), true ) ? $orderby_param : 'time';

        $order_param = strtoupper( sanitize_text_field( $request->get_param( 'order' ) ?: 'DESC' ) );
        $order = in_array( $order_param, array( 'ASC', 'DESC' ), true ) ? $order_param : 'DESC';

        $data_sql = "SELECT id, name, surname, email, phone, location, cart_contents, cart_total, currency, time, session_id, type, saved_via, contacted_status, contacted_time, contacted_via FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $data_args = array_merge( $where_args, array( $per_page, $offset ) );
        $results = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_args ), ARRAY_A );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        foreach ( $results as &$row ) {
            $row = $this->enrich_customer_fields( $row );
            $row['products'] = $this->build_products( $row['cart_contents'] );
            unset( $row['cart_contents'] );
        }

        return new WP_REST_Response( array(
            'carts'      => $results,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
            'data'       => $results,
        ), 200 );
    }

    public function get_active_carts( $request ) {
        $table = $this->get_table();
        global $wpdb;

        $minutes = absint( $request->get_param( 'minutes' ) ) ?: 5;
        $since  = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $minutes * MINUTE_IN_SECONDS ) );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, surname, email, phone, location, cart_contents, cart_total, currency, time, session_id, type, saved_via
                 FROM $table
                 WHERE cart_contents != '' AND time > %s AND (type IS NULL OR type != 'recovered')
                 ORDER BY time DESC
                 LIMIT 100",
                $since
            ),
            ARRAY_A
        );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        foreach ( $results as &$row ) {
            $row = $this->enrich_customer_fields( $row );
            $row['products'] = $this->build_products( $row['cart_contents'] );
            unset( $row['cart_contents'] );
        }

        return new WP_REST_Response( $results, 200 );
    }

    public function get_cart( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $id = absint( $request->get_param( 'id' ) );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Cart not found', array( 'status' => 404 ) );
        }

        $row = $this->enrich_customer_fields( $row );
        $row['products'] = $this->build_products( $row['cart_contents'] );
        unset( $row['cart_contents'] );

        return new WP_REST_Response( $row, 200 );
    }

    public function update_status( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $id = absint( $request->get_param( 'id' ) );

        $json = $request->get_json_params();
        $status = isset( $json['status'] ) ? sanitize_text_field( $json['status'] ) : '';
        if ( ! $status ) {
            $status = sanitize_text_field( $request->get_param( 'status' ) ?: 'pending' );
        }

        $data = array( 'contacted_status' => $status );
        if ( $status === 'contacted' ) {
            $data['contacted_time'] = current_time( 'mysql' );
        }

        $wpdb->update( $table, $data, array( 'id' => $id ) );
        return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
    }

    public function mark_whatsapp_contacted( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $id = absint( $request->get_param( 'id' ) );

        $wpdb->update( $table, array(
            'contacted_status' => 'contacted',
            'contacted_time'   => current_time( 'mysql' ),
            'contacted_via'    => 'whatsapp',
        ), array( 'id' => $id ) );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    public function delete_cart( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $id = absint( $request->get_param( 'id' ) );

        $wpdb->delete( $table, array( 'id' => $id ) );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    public function download_cart( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $id = absint( $request->get_param( 'id' ) );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Cart not found', array( 'status' => 404 ) );
        }

        $row = $this->enrich_customer_fields( $row );
        $products = $this->build_products( $row['cart_contents'] );
        $name = trim( ( isset( $row['name'] ) ? $row['name'] : '' ) . ' ' . ( isset( $row['surname'] ) ? $row['surname'] : '' ) );

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Abandoned Cart #' . $id . '</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;margin:40px;color:#333}h1{color:#2563eb;border-bottom:2px solid #eee;padding-bottom:10px}.info{margin:20px 0}.info td{padding:4px 12px 4px 0}.products{margin:20px 0;width:100%;border-collapse:collapse}.products th{background:#f3f4f6;padding:8px 12px;text-align:left;font-size:13px;text-transform:uppercase}.products td{padding:8px 12px;border-bottom:1px solid #eee}.total{font-size:18px;font-weight:bold;color:#2563eb}.footer{margin-top:30px;font-size:12px;color:#999;border-top:1px solid #eee;padding-top:10px}</style></head><body>';
        $html .= '<h1>Abandoned Cart #' . $id . '</h1>';

        $html .= '<table class="info">';
        if ( $name )      $html .= '<tr><td><strong>Name:</strong></td><td>' . esc_html( $name ) . '</td></tr>';
        if ( $row['email'] ) $html .= '<tr><td><strong>Email:</strong></td><td>' . esc_html( $row['email'] ) . '</td></tr>';
        if ( $row['phone'] ) $html .= '<tr><td><strong>Phone:</strong></td><td>' . esc_html( $row['phone'] ) . '</td></tr>';
        $html .= '<tr><td><strong>Last Activity:</strong></td><td>' . esc_html( $row['time'] ) . '</td></tr>';
        $html .= '<tr><td><strong>Status:</strong></td><td>' . esc_html( $row['contacted_status'] ?: 'Pending' ) . '</td></tr>';
        $html .= '</table>';

        if ( ! empty( $products ) ) {
            $html .= '<table class="products"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead><tbody>';
            foreach ( $products as $item ) {
                $subtotal = (float) $item['price'] * (int) $item['quantity'];
                $html .= '<tr><td>' . esc_html( $item['title'] ) . '</td><td>' . $item['quantity'] . '</td><td>' . wc_price( $item['price'] ) . '</td><td>' . wc_price( $subtotal ) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p class="total">Total: ' . wc_price( $row['cart_total'] ) . '</p>';
        }

        $html .= '<p class="footer">Generated by Sellwin Call Karo on ' . current_time( 'Y-m-d H:i:s' ) . '</p>';
        $html .= '</body></html>';

        header( 'Content-Type: text/html' );
        header( 'Content-Disposition: attachment; filename="abandoned-cart-' . $id . '.html"' );
        header( 'Content-Length: ' . strlen( $html ) );
        echo $html;
        exit;
    }

    public function get_stats( $request ) {
        $table = $this->get_table();
        global $wpdb;

        $idle_threshold = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - MINUTE_IN_SECONDS );
        $total      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table WHERE cart_contents != '' AND time <= %s", $idle_threshold ) );
        $contacted  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table WHERE contacted_status = 'contacted'" );
        $pending    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table WHERE cart_contents != '' AND time <= %s AND (contacted_status IS NULL OR contacted_status != 'contacted')", $idle_threshold ) );
        $recovered  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table WHERE type = %s", 'recovered' ) );
        $total_value = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cart_total) FROM $table WHERE cart_contents != '' AND time <= %s", $idle_threshold ) );

        return new WP_REST_Response( array(
            'total'       => $total,
            'active'      => $total - $recovered,
            'recovered'   => $recovered,
            'contacted'   => $contacted,
            'pending'     => $pending,
            'total_value' => (float) $total_value,
            'total_carts' => $total,
            'total_revenue' => (float) $total_value,
        ), 200 );
    }

    public function get_customer( $request ) {
        $table = $this->get_table();
        global $wpdb;
        $mobile = sanitize_text_field( $request->get_param( 'mobile' ) );

        if ( ! $mobile ) {
            return new WP_REST_Response( array( 'error' => 'Mobile number required' ), 400 );
        }

        $clean = preg_replace( '/[^0-9]/', '', $mobile );
        $like = '%' . $wpdb->esc_like( $clean ) . '%';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, surname, email, phone, cart_total, currency, time, contacted_status, contacted_via, contacted_time
                 FROM $table
                 WHERE phone LIKE %s AND cart_contents != ''
                 ORDER BY time DESC LIMIT 50",
                $like
            ),
            ARRAY_A
        );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        $customer_name = '';
        $customer_email = '';
        if ( ! empty( $results ) ) {
            $customer_name = trim( ( isset( $results[0]['name'] ) ? $results[0]['name'] : '' ) . ' ' . ( isset( $results[0]['surname'] ) ? $results[0]['surname'] : '' ) );
            $customer_email = isset( $results[0]['email'] ) ? $results[0]['email'] : '';
        }

        return new WP_REST_Response( array(
            'name'    => $customer_name,
            'email'   => $customer_email,
            'mobile'  => $mobile,
            'carts'   => $results,
            'total'   => count( $results ),
        ), 200 );
    }

    public function get_abandoned_trend( $request ) {
        $table = $this->get_table();
        global $wpdb;

        $days = absint( $request->get_param( 'days' ) ) ?: 7;
        $since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(time) as date, COUNT(id) as count
                 FROM $table
                 WHERE cart_contents != '' AND time >= %s
                 GROUP BY DATE(time)
                 ORDER BY date ASC",
                $since
            ),
            ARRAY_A
        );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        return new WP_REST_Response( $results, 200 );
    }

    public function export_csv( $request ) {
        $table = $this->get_table();
        global $wpdb;

        $results = $wpdb->get_results( "SELECT id, name, surname, email, phone, cart_total, currency, time, contacted_status, contacted_time, contacted_via FROM $table WHERE cart_contents != '' ORDER BY time DESC", ARRAY_A );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=abandoned-carts-export.csv' );
        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'ID', 'Name', 'Surname', 'Email', 'Phone', 'Cart Total', 'Currency', 'Time', 'Contacted Status', 'Contacted Time', 'Contacted Via' ) );
        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                fputcsv( $output, $row );
            }
        }
        fclose( $output );
        exit;
    }

    public function health_check() {
        $table = $this->get_table();
        global $wpdb;
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;

        return new WP_REST_Response( array(
            'status'            => 'ok',
            'namespace'         => $this->namespace,
            'plugin'            => CARTBOUNTY_PLUGIN_NAME,
            'version'           => CARTBOUNTY_VERSION_NUMBER,
            'table_exists'      => $table_exists,
            'db_version'        => $wpdb->db_version(),
            'php_version'       => PHP_VERSION,
            'woocommerce_active'=> class_exists( 'WooCommerce' ),
        ), 200 );
    }
}
