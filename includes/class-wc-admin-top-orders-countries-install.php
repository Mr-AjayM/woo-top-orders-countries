<?php

/**
 * Installation related functions & actions.
 *
 * @package WooCommerce Admin Top Orders Countries / Includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Admin_Top_Orders_Countries_Install Class.
 */
class WC_Admin_Top_Orders_Countries_Install{

	protected static $queue = null;

	/**
	 * Action scheduler group.
	 */
	const QUEUE_GROUP = 'wc-admin-top-countries';

	const ORDERS_BATCH_ACTION = 'wc-admin_process_orders_countries_batch';

	public static function init(){
		add_action( self::ORDERS_BATCH_ACTION, array(__CLASS__, 'orders_lookup_process_batch') );
	}

	/**
	 * Create database tables.
	 */
	public static function create_tables(){
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_schema() );
	}

	private static function get_schema(){
		global $wpdb;

		if( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$tables = "
		CREATE TABLE {$wpdb->prefix}wc_admin_orders_countries (
			order_id bigint(20) unsigned NOT NULL,
			date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			net_total double DEFAULT 0 NOT NULL,
			status varchar(200) NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			order_country char(2),
			PRIMARY KEY (order_id),
			KEY date_created (date_created),
			KEY customer_id (customer_id),
			KEY status (status)
		) $collate;
		";

		return $tables;
	}

	public static function orders_lookup_batch_init(){
		$batch_size = 10;
		$order_query = new WC_Order_Query( array('return' => 'ids', 'limit' => 1, 'paginate' => true,) );
		$result = $order_query->get_orders();

		if( 0 === $result->total ) {
			return;
		}

		$num_batches = ceil( $result->total / $batch_size );

		self::queue_batches( 1, $num_batches, self::ORDERS_BATCH_ACTION );
	}

	/**
	 * Queue a large number of batch jobs, respecting the batch size limit.
	 * Reduces a range of batches down to "single batch" jobs.
	 *
	 * @param int $range_start Starting batch number.
	 * @param int $range_end Ending batch number.
	 * @param string $single_batch_action Action to schedule for a single batch.
	 * @return void
	 */
	public static function queue_batches( $range_start, $range_end, $single_batch_action ){
		$batch_size = 10;
		$range_size = 1 + ($range_end - $range_start);
		$action_timestamp = time() + 5;

		// Otherwise, queue the single batches.
		for ( $i = $range_start; $i <= $range_end; $i++ ) {
			//WC()->queue()->schedule_single( $action_timestamp, $single_batch_action, array($i), self::QUEUE_GROUP );
			wp_schedule_single_event( $action_timestamp, $single_batch_action, array($i) );
		}

	}

	public static function queue(){
		if( is_null( self::$queue ) ) {
			self::$queue = WC()->queue();
		}

		return self::$queue;
	}

	/**
	 * Process a batch of orders to update (stats and products).
	 *
	 * @param int $batch_number Batch number to process (essentially a query page number).
	 * @return void
	 */
	public static function orders_lookup_process_batch( $batch_number ){

		$batch_size = 10;
		$order_query = new WC_Order_Query( array('return' => 'ids', 'limit' => $batch_size, 'page' => $batch_number, 'orderby' => 'ID', 'order' => 'ASC',) );
		$order_ids = $order_query->get_orders();

		foreach ( $order_ids as $order_id ) {
			self::orders_lookup_process_order( $order_id );

		}
	}

	/**
	 * Process a single order to update lookup tables for.
	 * If an error is encountered in one of the updates, a retry action is scheduled.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function orders_lookup_process_order( $order_id ) {
		$result = array_sum(
			array(
				WC_Admin_Reports_Countries_Data_Store::sync_order( $order_id )
			)
		);

		// If all updates were either skipped or successful, we're done.
		// The update methods return -1 for skip, or a boolean success indicator.
		if ( 1 === absint( $result ) ) {
			return;
		}

		// Otherwise assume an error occurred and reschedule.
		self::schedule_single_order_process( $order_id );
	}

	/**
	 * Remove any details from db that created by this plugin.
	 */
	public static function remove_top_orders_countries_details(){
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_admin_orders_countries';
		$sql = "DROP TABLE IF EXISTS $table_name";
		$wpdb->query($sql);
		//delete_option("my_plugin_db_version");
	}
}
WC_Admin_Top_Orders_Countries_Install::init();