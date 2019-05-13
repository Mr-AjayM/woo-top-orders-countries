<?php
/**
 * WC_Admin_Reports_Orders_Data_Store class file.
 *
 * @package WooCommerce Admin/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Admin_Reports_Orders_Data_Store.
 */
class WC_Admin_Reports_Countries_Data_Store extends WC_Admin_Reports_Data_Store implements WC_Admin_Reports_Data_Store_Interface {
//class WC_Admin_Reports_Countries_Data_Store {

    /**
     * Table used to get the data.
     *
     * @var string
     */
    const TABLE_NAME = 'wc_admin_orders_countries';

    /**
     * Mapping columns to data type to return correct response types.
     *
     * @var array
     */
    protected $column_types = array(
        'order_id'       => 'intval',
        'date_created'   => 'strval',
        'status'         => 'strval',
        'net_total'      => 'floatval',
        'order_country'  => 'strval',
    );

    /**
     * SQL columns to select in the db query and their mapping to SQL code.
     *
     * @var array
     */
    protected $report_columns = array(
        'net_total'      => 'net_total',
        'order_country'     => 'order_country',
        'orders_count' => 'orders_count'

    );

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        // Avoid ambigious columns in SQL query.
        $this->report_columns['order_country']     = $table_name . '.' . $this->report_columns['order_country'];
        $this->report_columns['orders_count'] = $table_name . '.' . $this->report_columns['orders_count'];
        $this->report_columns['net_total']  = $table_name . '.' . $this->report_columns['net_total'];
    }

    /**
     * Updates the database query with parameters used for orders report: coupons and products filters.
     *
     * @param array $query_args Query arguments supplied by the user.
     * @return array            Array of parameters used for SQL query.
     */
    protected function get_sql_query_params( $query_args ) {
        global $wpdb;
        $order_stats_lookup_table = $wpdb->prefix . self::TABLE_NAME;

        $sql_query_params = $this->get_time_period_sql_params( $query_args, $order_stats_lookup_table );
        $sql_query_params = array_merge( $sql_query_params, $this->get_limit_sql_params( $query_args ) );
        $sql_query_params = array_merge( $sql_query_params, $this->get_order_by_sql_params( $query_args ) );

        $status_subquery = $this->get_order_status_subquery( $query_args );
        if ( $status_subquery ) {
            $sql_query_params['where_clause'] .= " AND {$status_subquery}";
        }

        if ( $query_args['customer_type'] ) {
            $returning_customer                = 'returning' === $query_args['customer_type'] ? 1 : 0;
            $sql_query_params['where_clause'] .= " AND returning_customer = ${returning_customer}";
        }

        $included_coupons          = $this->get_included_coupons( $query_args );
        $excluded_coupons          = $this->get_excluded_coupons( $query_args );
        $order_coupon_lookup_table = $wpdb->prefix . 'wc_order_coupon_lookup';
        if ( $included_coupons || $excluded_coupons ) {
            $sql_query_params['from_clause'] .= " JOIN {$order_coupon_lookup_table} ON {$order_stats_lookup_table}.order_id = {$order_coupon_lookup_table}.order_id";
        }
        if ( $included_coupons ) {
            $sql_query_params['where_clause'] .= " AND {$order_coupon_lookup_table}.coupon_id IN ({$included_coupons})";
        }
        if ( $excluded_coupons ) {
            $sql_query_params['where_clause'] .= " AND {$order_coupon_lookup_table}.coupon_id NOT IN ({$excluded_coupons})";
        }

        $included_products          = $this->get_included_products( $query_args );
        $excluded_products          = $this->get_excluded_products( $query_args );
        $order_product_lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        if ( $included_products || $excluded_products ) {
            $sql_query_params['from_clause'] .= " JOIN {$order_product_lookup_table} ON {$order_stats_lookup_table}.order_id = {$order_product_lookup_table}.order_id";
        }
        if ( $included_products ) {
            $sql_query_params['where_clause'] .= " AND {$order_product_lookup_table}.product_id IN ({$included_products})";
        }
        if ( $excluded_products ) {
            $sql_query_params['where_clause'] .= " AND {$order_product_lookup_table}.product_id NOT IN ({$excluded_products})";
        }

        return $sql_query_params;
    }

	/**
	 * Returns order status subquery to be used in WHERE SQL query, based on query arguments from the user.
	 *
	 * @param array  $query_args Parameters supplied by the user.
	 * @param string $operator   AND or OR, based on match query argument.
	 * @return string
	 */
	protected function get_order_status_subquery( $query_args, $operator = 'AND' ) {
		global $wpdb;

		$subqueries        = array();
		$excluded_statuses = array();
		if ( isset( $query_args['status_is'] ) && is_array( $query_args['status_is'] ) && count( $query_args['status_is'] ) > 0 ) {
			$allowed_statuses = array_map( array( $this, 'normalize_order_status' ), $query_args['status_is'] );
			if ( $allowed_statuses ) {
				$subqueries[] = "{$wpdb->prefix}wc_admin_orders_countries.status IN ( '" . implode( "','", $allowed_statuses ) . "' )";
			}
		}

		if ( isset( $query_args['status_is_not'] ) && is_array( $query_args['status_is_not'] ) && count( $query_args['status_is_not'] ) > 0 ) {
			$excluded_statuses = array_map( array( $this, 'normalize_order_status' ), $query_args['status_is_not'] );
		}

		if ( ( ! isset( $query_args['status_is'] ) || empty( $query_args['status_is'] ) )
			&& ( ! isset( $query_args['status_is_not'] ) || empty( $query_args['status_is_not'] ) )
		) {
			$excluded_statuses = array_map( array( $this, 'normalize_order_status' ), $this->get_excluded_report_order_statuses() );
		}

		if ( $excluded_statuses ) {
			$subqueries[] = "{$wpdb->prefix}wc_admin_orders_countries.status NOT IN ( '" . implode( "','", $excluded_statuses ) . "' )";
		}

		return implode( " $operator ", $subqueries );
	}

    /**
     * Returns the report data based on parameters supplied by the user.
     *
     * @param array $query_args  Query parameters.
     * @return stdClass|WP_Error Data.
     */
    public function get_data( $query_args ) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // These defaults are only partially applied when used via REST API, as that has its own defaults.
        $defaults   = array(
            'per_page'         => get_option( 'posts_per_page' ),
            'page'             => 1,
            'order'            => 'DESC',
            'orderby'          => 'date_created',
            'before'           => WC_Admin_Reports_Interval::default_before(),
            'after'            => WC_Admin_Reports_Interval::default_after(),
            'fields'           => '*',
            'product_includes' => array(),
            'product_excludes' => array(),
            'coupon_includes'  => array(),
            'coupon_excludes'  => array(),
            'customer_type'    => null,
            'status_is'        => array(),
            'extended_info'    => false,
        );
        $query_args = wp_parse_args( $query_args, $defaults );
        $this->normalize_timezones( $query_args, $defaults );

        $cache_key = $this->get_cache_key( $query_args );
        $data      = wp_cache_get( $cache_key, $this->cache_group );

        if ( false === $data ) {
            $data = (object) array(
                'data'    => array(),
                'total'   => 0,
                'pages'   => 0,
                'page_no' => 0,
            );

            $selections       = $this->selected_columns( $query_args );
            $sql_query_params = $this->get_sql_query_params( $query_args );

            $db_records_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (
							SELECT
								{$table_name}.order_id
							FROM
								{$table_name}
								{$sql_query_params['from_clause']}
							WHERE
								1=1
								{$sql_query_params['where_time_clause']}
								{$sql_query_params['where_clause']}
					  		) AS tt"
            ); // WPCS: cache ok, DB call ok, unprepared SQL ok.

            if ( 0 === $sql_query_params['per_page'] ) {
                $total_pages = 0;
            } else {
                $total_pages = (int) ceil( $db_records_count / $sql_query_params['per_page'] );
            }
            if ( $query_args['page'] < 1 || $query_args['page'] > $total_pages ) {
                $data = (object) array(
                    'data'    => array(),
                    'total'   => $db_records_count,
                    'pages'   => 0,
                    'page_no' => 0,
                );
                return $data;
            }

            $orders_data = $wpdb->get_results(
                "SELECT
						 SUM(net_total) as net_total, count(order_country) as orders_count, order_country
					FROM
						{$table_name}
                    GROUP BY order_country
					
					ORDER BY
						orders_count desc
					{$sql_query_params['limit']}
					",
                ARRAY_A
            ); // WPCS: cache ok, DB call ok, unprepared SQL ok.

            if ( null === $orders_data ) {
                return $data;
            }

            if ( $query_args['extended_info'] ) {
                $this->include_extended_info( $orders_data, $query_args );
            }

            $orders_data = array_map( array( $this, 'cast_numbers' ), $orders_data );
            $data        = (object) array(
                'data'    => $orders_data,
                'total'   => $db_records_count,
                'pages'   => $total_pages,
                'page_no' => (int) $query_args['page'],
            );

            wp_cache_set( $cache_key, $data, $this->cache_group );
        }

        return $data;
    }

    /**
     * Normalizes order_by clause to match to SQL query.
     *
     * @param string $order_by Order by option requeste by user.
     * @return string
     */
    protected function normalize_order_by( $order_by ) {
        if ( 'date' === $order_by ) {
            return 'date_created';
        }

        return $order_by;
    }

    /**
     * Enriches the order data.
     *
     * @param array $orders_data Orders data.
     * @param array $query_args  Query parameters.
     */
    protected function include_extended_info( &$orders_data, $query_args ) {
        $mapped_orders    = $this->map_array_by_key( $orders_data, 'order_id' );
        $products         = $this->get_products_by_order_ids( array_keys( $mapped_orders ) );
        $mapped_products  = $this->map_array_by_key( $products, 'product_id' );
        $coupons          = $this->get_coupons_by_order_ids( array_keys( $mapped_orders ) );
        $customers        = $this->get_customers_by_orders( $orders_data );
        $mapped_customers = $this->map_array_by_key( $customers, 'customer_id' );

        $mapped_data = array();
        foreach ( $products as $product ) {
            if ( ! isset( $mapped_data[ $product['order_id'] ] ) ) {
                $mapped_data[ $product['order_id'] ]['products'] = array();
            }

            $mapped_data[ $product['order_id'] ]['products'][] = array(
                'id'       => $product['product_id'],
                'name'     => $product['product_name'],
                'quantity' => $product['product_quantity'],
            );
        }

        foreach ( $coupons as $coupon ) {
            if ( ! isset( $mapped_data[ $coupon['order_id'] ] ) ) {
                $mapped_data[ $product['order_id'] ]['coupons'] = array();
            }

            $mapped_data[ $coupon['order_id'] ]['coupons'][] = array(
                'id'   => $coupon['coupon_id'],
                'code' => wc_format_coupon_code( $coupon['coupon_code'] ),
            );
        }

        foreach ( $orders_data as $key => $order_data ) {
            $defaults                             = array(
                'products' => array(),
                'coupons'  => array(),
                'customer' => array(),
            );
            $orders_data[ $key ]['extended_info'] = isset( $mapped_data[ $order_data['order_id'] ] ) ? array_merge( $defaults, $mapped_data[ $order_data['order_id'] ] ) : $defaults;
            if ( $order_data['customer_id'] && isset( $mapped_customers[ $order_data['customer_id'] ] ) ) {
                $orders_data[ $key ]['extended_info']['customer'] = $mapped_customers[ $order_data['customer_id'] ];
            }
        }
    }

    /**
     * Returns the same array index by a given key
     *
     * @param array  $array Array to be looped over.
     * @param string $key Key of values used for new array.
     * @return array
     */
    protected function map_array_by_key( $array, $key ) {
        $mapped = array();
        foreach ( $array as $item ) {
            $mapped[ $item[ $key ] ] = $item;
        }
        return $mapped;
    }

    /**
     * Get product IDs, names, and quantity from order IDs.
     *
     * @param array $order_ids Array of order IDs.
     * @return array
     */
    protected function get_products_by_order_ids( $order_ids ) {
        global $wpdb;
        $order_product_lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $included_order_ids         = implode( ',', $order_ids );

        $products = $wpdb->get_results(
            "SELECT order_id, ID as product_id, post_title as product_name, product_qty as product_quantity
				FROM {$wpdb->prefix}posts
				JOIN {$order_product_lookup_table} ON {$order_product_lookup_table}.product_id = {$wpdb->prefix}posts.ID
				WHERE
					order_id IN ({$included_order_ids})
				",
            ARRAY_A
        ); // WPCS: cache ok, DB call ok, unprepared SQL ok.

        return $products;
    }

    /**
     * Get customer data from order IDs.
     *
     * @param array $orders Array of orders.
     * @return array
     */
    protected function get_customers_by_orders( $orders ) {
        global $wpdb;
        $customer_lookup_table = $wpdb->prefix . 'wc_customer_lookup';

        $customer_ids = array();
        foreach ( $orders as $order ) {
            if ( $order['customer_id'] ) {
                $customer_ids[] = $order['customer_id'];
            }
        }
        $customer_ids = implode( ',', $customer_ids );

        $customers = $wpdb->get_results(
            "SELECT * FROM {$customer_lookup_table} WHERE customer_id IN ({$customer_ids})",
            ARRAY_A
        ); // WPCS: cache ok, DB call ok, unprepared SQL ok.

        return $customers;
    }

    /**
     * Get coupon information from order IDs.
     *
     * @param array $order_ids Array of order IDs.
     * @return array
     */
    protected function get_coupons_by_order_ids( $order_ids ) {
        global $wpdb;
        $order_coupon_lookup_table = $wpdb->prefix . 'wc_order_coupon_lookup';
        $included_order_ids        = implode( ',', $order_ids );

        $coupons = $wpdb->get_results(
            "SELECT order_id, coupon_id, post_title as coupon_code
				FROM {$wpdb->prefix}posts
				JOIN {$order_coupon_lookup_table} ON {$order_coupon_lookup_table}.coupon_id = {$wpdb->prefix}posts.ID
				WHERE
					order_id IN ({$included_order_ids})
				",
            ARRAY_A
        ); // WPCS: cache ok, DB call ok, unprepared SQL ok.

        return $coupons;
    }

    /**
     * Returns string to be used as cache key for the data.
     *
     * @param array $params Query parameters.
     * @return string
     */
    protected function get_cache_key( $params ) {
        return 'woocommerce_' . self::TABLE_NAME . '_' . md5( wp_json_encode( $params ) );
    }

	/**
	 * Add order information to the lookup table when orders are created or modified.
	 *
	 * @param int $post_id Post ID.
	 * @return int|bool Returns -1 if order won't be processed, or a boolean indicating processing success.
	 */
	public static function sync_order( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return -1;
		}

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return -1;
		}

		return self::update( $order );
	}

	/**
	 * Update the database with stats data.
	 *
	 * @param WC_Order $order Order to update row for.
	 * @return int|bool Returns -1 if order won't be processed, or a boolean indicating processing success.
	 */
	public static function update( $order ) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		if ( ! $order->get_id() || ! $order->get_date_created() ) {
			return -1;
		}

		$data   = array(
			'order_id'           => $order->get_id(),
			'date_created'       => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
			'net_total'          => self::get_net_total( $order ),
			'status'             => self::normalize_order_status( $order->get_status() ),
			'customer_id'        => WC_Admin_Reports_Customers_Data_Store::get_or_create_customer_from_order( $order ),
			'order_country'      =>  $order->get_billing_country(),
		);
		$format = array(
			'%d',
			'%s',
			'%f',
			'%s',
			'%d',
			'%s'
		);

		// Update or add the information to the DB.
		$result = $wpdb->replace( $table_name, $data, $format );

		/**
		 * Fires when order's stats reports are updated.
		 *
		 * @param int $order_id Order ID.
		 */
		do_action( 'woocommerce_reports_update_order_stats', $order->get_id() );

		// Check the rows affected for success. Using REPLACE can affect 2 rows if the row already exists.
		return ( 1 === $result || 2 === $result );
	}

	/**
	 * Get the net amount from an order without shipping, tax, or refunds.
	 *
	 * @param array $order WC_Order object.
	 * @return float
	 */
	protected static function get_net_total( $order ) {
		$net_total = floatval( $order->get_total() ) - floatval( $order->get_total_tax() ) - floatval( $order->get_shipping_total() );

		$refunds = $order->get_refunds();
		foreach ( $refunds as $refund ) {
			$net_total += floatval( $refund->get_total() ) - floatval( $refund->get_total_tax() ) - floatval( $refund->get_shipping_total() );
		}

		return $net_total > 0 ? (float) $net_total : 0;
	}

	/**
	 * Maps order status provided by the user to the one used in the database.
	 *
	 * @param string $status Order status.
	 * @return string
	 */
	protected static function normalize_order_status( $status ) {
		$status = trim( $status );
		return 'wc-' . $status;
	}

}
