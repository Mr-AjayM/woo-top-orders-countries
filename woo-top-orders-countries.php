<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @since             1.0.0
 * @package           Wc_Admin_Top_Orders_Countries
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce Admin Top Orders Countries
 * Description:       WooCommerce Admin Top Orders Countries will create the leaderboard in the WooCommerce Admin dashboard that represent the top countries from where your getting most number of orders.
 * Author: Ajay Ghaghretiya
 * Author URI: https://about.me/ajay_ghaghretiya
 * Version:           1.0.0
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woocommerce-admin-top-orders-countries
 * Domain Path:       /languages
 *
 * WC requires at least: 3.5.0
 * WC tested up to: 3.6.2
 *
 * WC admin requires at least: 0.11.0
 */

defined( 'ABSPATH' ) || exit;

if( !defined( 'WC_ADMIN_TOP_COUNTRIES_ABSPATH' ) ) {
	define( 'WC_ADMIN_TOP_COUNTRIES_ABSPATH', dirname( __FILE__ ) . '/' );
}

/**
 * WooCommerce_Admin_Top_Orders_Countires
 */
class WooCommerce_Admin_Top_Orders_Countires{
	/**
	 *
	 * add_action of customer register.
	 */
	public function __construct(){
		add_action( 'plugins_loaded', array($this, 'init'), 98 );
		require_once WC_ADMIN_TOP_COUNTRIES_ABSPATH . 'includes/class-wc-admin-top-orders-countries-install.php';
	}

	/**
	 * Initialize the include libraries.
	 */
	public function init(){
		if( !class_exists( 'WC_Admin_Reports_Data_Store' ) ) {
			return;
		}
		require_once WC_ADMIN_TOP_COUNTRIES_ABSPATH . 'includes/class-wc-admin-top-orders-countries.php';

		add_filter( 'woocommerce_leaderboards', array($this, 'top_countries_orders_leaderboard'), 30, 5 );
	}

	/**
	 * Add the top countries leaderboard.
	 *
	 * @param $leaderboards
	 * @param $per_page
	 * @param $after
	 * @param $before
	 * @param $persisted_query
	 * @return array|void
	 */
	public function top_countries_orders_leaderboard( $leaderboards, $per_page, $after, $before, $persisted_query ){

		//$this->init();

		if( !class_exists( 'WC_Admin_Api_Init' ) ) {
			return;
		}

		if( !class_exists( 'WC_Data_Store' ) ) {
			return;
		}

		if( class_exists( 'WC_Admin_Reports_Countries_Data_Store' ) && class_exists( 'WC_Admin_Reports_Data_Store' ) ) {
			$countries = $this->get_countries_leaderboard( $per_page, $after, $before, $persisted_query );
			array_push( $leaderboards, $countries );
		}
		return $leaderboards;
	}

	protected function get_countries_leaderboard( $per_page, $after, $before, $persisted_query ){
		$products_data_store = new WC_Admin_Reports_Countries_Data_Store();
		$products_data = $per_page > 0 ? $products_data_store->get_data( array('orderby' => 'orders_count', 'order' => 'desc', 'after' => $after, 'before' => $before, 'per_page' => $per_page, 'extended_info' => false,) )->data : array();

		$rows = array();
		foreach ( $products_data as $product ) {
			$product_name = isset( $product['extended_info'] ) && isset( $product['extended_info']['name'] ) ? $product['extended_info']['name'] : '';
			$country_code = $product['order_country'];
			$rows[] = array(array('display' => WC()->countries->countries[$country_code], 'value' => $product['order_country'],), array('display' => wc_admin_number_format( $product['orders_count'] ), 'value' => $product['orders_count'],), array('display' => wc_price( $product['net_total'] ), 'value' => $product['net_total'],),);
		}

		return array('id' => 'countries', 'label' => __( 'Top Countries - Orders', 'woocommerce-admin' ), 'headers' => array(array('label' => __( 'Country', 'woocommerce-admin' ),), array('label' => __( 'Orders', 'woocommerce-admin' ),), array('label' => __( 'Net Revenue', 'woocommerce-admin' ),),), 'rows' => $rows,);

	}

	/*
	 * It will check that WooCommerce Admin plugin is installed activated or not before ativation of this plugin.
	 */
	public static function self_deactivate_notice(){
		if( !class_exists( 'WC_Admin_Api_Init' ) ) {
			wp_die( "It requires WooCommerce Admin to be installed and active." );
		}
	}


}

function woocommerce_admin_top_orders_countries_activate(){
	WooCommerce_Admin_Top_Orders_Countires::self_deactivate_notice();
	WC_Admin_Top_Orders_Countries_Install::create_tables();
	WC_Admin_Top_Orders_Countries_Install::orders_lookup_batch_init();

}

register_activation_hook( __FILE__, 'woocommerce_admin_top_orders_countries_activate' );

function woocommerce_admin_top_orders_countries_deactivate(){
	WC_Admin_Top_Orders_Countries_Install::remove_top_orders_countries_details();
}

register_deactivation_hook( __FILE__, 'woocommerce_admin_top_orders_countries_deactivate' );

new WooCommerce_Admin_Top_Orders_Countires();
