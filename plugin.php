<?php
/**
 * Plugin Name: CoinMarketCap Exchange Rates
 * Description: Provides exchange rates for Cryptocurrencies with CoinMarketCap API
 */

/**
 * Plugin initialization
 */

global $cc_rates;

// initializate functions
require_once( 'cc_rates.class.php');
require_once( 'functions.php');
wp_enqueue_script( 'jquery' ); // requre jquery

// initializate main class 
$cc_rates = new cc_rates( array(
	'api_key'	=> 'd2e5172a-fee8-4493-8259-492e792f5285',
	// uncomment for sandbox testing, no real data will be provided
//	'api_url'	=> 'https://sandbox-api.coinmarketcap.com/v1', 
));

/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, function(){
	global $cc_rates;
	$cc_rates->install_mysql();
	$cc_rates->update_list();
	$cc_rates->update_rates();
});

/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, function(){
	global $cc_rates;
	$cc_rates->uninstall_mysql();
});

/**
 * Plugin cron every 5 minutes
 * Get exchange rates from API and save to the local database
 */

// create new schedule time
add_filter('cron_schedules', function( $schedules ){
	$schedules['5min'] = array(
		'interval'	=> 5 * 60,
		'display'	=> __('Once every 5 minutes'),
	);
	return $schedules;
});

// schedule an action if it's not already scheduled
add_action( 'wp', function() {
	if ( !wp_next_scheduled( 'cc_rates_cron' ) ) 
		wp_schedule_event( time(), '5min', 'cc_rates_cron' );
});

// do plugin cron task
add_action( 'cc_rates_cron', function() {
    global $cc_rates;
//	$cc_rates->update_rates(); // !!! DISABLED DUE TO FREE API PLAN 333 CALL PER DAY ONLY
});
