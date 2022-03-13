<?php

/**
 * CoinMarketCap exchange rates class for 
 * cc_rates Worpesss plugin
 */

class cc_rates {

	private $api_key;
	private $api_url;

	const DB_PREFIX	= 'cc_';

	/**
	 * Initialization function
	 */
	function __construct( $params ){

		$this->api_key = @$params['api_key'];
		$this->api_url = @$params['api_url'] ? $params['api_url'] : 'https://pro-api.coinmarketcap.com/v1';
	}

	/**
	 * Create mysql database tables
	 */
	function install_mysql() {

		global $wpdb;

		// TODO: Optimize PRIMARY KEY list according to the tasks

		$sql = array();
		$sql []= "CREATE TABLE IF NOT EXISTS `DB_PREFIX_list` (
			`id` int(11) NOT NULL,
			`name` varchar(128) NOT NULL,
			`symbol` varchar(32) NOT NULL,
			`slug` varchar(32) NOT NULL,
			PRIMARY KEY (`id`)
		);";
		$sql []= "CREATE TABLE IF NOT EXISTS `DB_PREFIX_rates` (
			`id` int(11) NOT NULL auto_increment,
			`base_id` int(11) NOT NULL,
			`to_id` int(11) NOT NULL,
			`rate` float NOT NULL,
			`updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`)
		);";
		$sql []= "CREATE TABLE IF NOT EXISTS `DB_PREFIX_history` (
			`id` int(11) NOT NULL auto_increment,
			`base_id` int(11) NOT NULL,
			`to_id` int(11) NOT NULL,
			`volume` float NOT NULL,
			`rate` float NOT NULL,
			`date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`)
		);";

		foreach( $sql as $query ) {
			$wpdb->query( $this->prepare_query( $query ) );
		}
	}

	/**
	 * Remove mysql database tables
	 */
	function uninstall_mysql() {

		global $wpdb;

		$sql = array();
		$sql []= "DROP TABLE `DB_PREFIX_list`;";
		$sql []= "DROP TABLE `DB_PREFIX_rates`;";
		$sql []= "DROP TABLE `DB_PREFIX_history`;";

		foreach( $sql as $query ) {
			$wpdb->query( $this->prepare_query( $query ) );
		}
	}

	/**
	 * Make request to CoinMarketCap API
	 */
	function data_request( $endpoint, $parameters ) {

		$request = $this->api_url.$endpoint.'?'.http_build_query($parameters);

		$headers = [
			'Accepts: application/json',
			'X-CMC_PRO_API_KEY: '.$this->api_key
		];

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $request,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => 1
		));

		$response = curl_exec($curl);
		curl_close($curl);

		return json_decode($response);
	}

	/**
	 * Recive currencies list from API and save to the local database
	 * It will truncate existing data first. Run only once on plugin activation or when you need to update the list. 
	 *
	 * !!! LIMITATION OF THIS WORK: 
	 *     Gets only first 100 coins which can be converted to BTC
	 */
	function update_list(){

		global $wpdb;

		// reset data
		$wpdb->query( $this->prepare_query( "TRUNCATE `DB_PREFIX_list`;" ) );

		// get data from API
		$result = $this->data_request( '/cryptocurrency/listings/latest', array(
			'start'					=> '1',
			'limit'					=> '100',
			'cryptocurrency_type'	=> 'coins',
			'sort'					=> 'volume_30d', // can be any sort order
			'convert'				=> 'BTC', // "BTC" for demo purpose only
		));

		// TODO: check request status
		if ( $result->status->error_code != 0 ) return;

		// save data to the local database
		$sql = "INSERT INTO `DB_PREFIX_list` (`id`,`name`,`symbol`,`slug`) \nVALUES \n";
		$row = array();
		foreach( $result->data as $data ) {
			// DOTO: check and escape data
			$row []= "({$data->id},\"{$data->name}\",\"{$data->symbol}\",\"{$data->slug}\")";
		}
		$sql .= implode(", \n", $row ) . ";";
		$wpdb->query( $this->prepare_query( $sql ) );
	}

	/**
	 * Recive rates from API and save to the local database
	 *
	 * @base specify the base symbol rates to be updated: can be id or symbol name
	 */
	function update_rates( $base = 'BTC' ){

		global $wpdb;

		// BASE: can be symbol or id
		$base_id = is_int( $base ) ? $base : $this->get_symbol_id( $base );		
		$base_symbol = $this->get_symbol_name( $base_id );

		// get data from API
		$result = $this->data_request( '/cryptocurrency/listings/latest', array(
			'start'					=> '1',
			'limit'					=> '100',
			'cryptocurrency_type'	=> 'coins',
			'sort'					=> 'volume_30d',
			'convert'				=> $base_symbol,
		));

		// TODO: check request status
		if ( $result->status->error_code != 0 ) return;

		// save data to the local database
		$sql = "INSERT INTO `DB_PREFIX_rates` (`base_id`,`to_id`,`rate`) \nVALUES \n";
		$row = array();
		foreach( $result->data as $data ) {
			// skip same currency
			if ( $base_id == $data->id ) continue;
			// сalculate BTC reverse rate
			$rate = 1 / $data->quote->$base_symbol->price;
			// DOTO: check and escape data
			$row []= "({$base_id},{$data->id},{$rate})";
		}
		$sql .= implode(", \n", $row ) . ";";
		$wpdb->query( $this->prepare_query( $sql ) );
	}

	/**
	 * Return currencies list from the local database
	 *
	 * @base can be "symbol" string or "id" integer
	 */
	function get_list( $base = 'BTC' ) {

		global $wpdb;

		// BASE, TO: can be symbol or id
		$base_id = is_int( $base ) ? $base : $this->get_symbol_id( $base );

		// get currencies list only for existed rates in local database for base symbol
		$query = "
            SELECT list.* FROM `DB_PREFIX_list` as list, `DB_PREFIX_rates` as rates
            WHERE list.id!={$base_id} AND rates.base_id={$base_id} AND rates.to_id=list.id
            GROUP BY list.id ORDER BY list.id;
		";
		$results = $wpdb->get_results( $this->prepare_query( $query ) );

		return $results; 
	}

	/**
	 * Return latest exchange rate from "base" symbol "to" symbol
	 *
	 * @base, @to can be "symbol" string or "id" integer
	 * @volume of the base currency
	 * @save_history if we need to keep query history in database
	 * 
	 * EXAMPLE: 
	 *    $data = get_rate( 'BTC', 'XRP' ); 
	 *    $data = get_rate( 1, 'XRP', 15 ); 
	 *
	 * TODO: query historical data
	 */
	function get_rate( $base, $to, $volume = 1, $save_history = true ) {

		global $wpdb;

		// BASE, TO: can be symbol or id
		$base_id = is_int( $base ) ? $base : $this->get_symbol_id( $base );		
		$to_id = is_int( $to ) ? $to : $this->get_symbol_id( $to );		

		// get rates and calculate price according to the volume
		$query = "
			SELECT base_id, to_id, {$volume} as `volume`, {$volume}*`rate` as `price`, `rate`, `updated` 
			FROM `DB_PREFIX_rates`
			WHERE base_id={$base_id} AND to_id={$to_id}
			ORDER BY `updated` DESC LIMIT 1;
		";
		$results = $wpdb->get_results( $this->prepare_query( $query ) );

		// get data
		// TODO: check errors
		$data = @$results[0];

		// keep rates query history in local database
		if ( $data && $save_history ) $this->save_history( $data );

		return $data; 
	}

	/**
	 * Keep rates query history in local database
	 */
	function save_history( $data ) {

		global $wpdb;

		$query = "
			INSERT INTO `DB_PREFIX_history` (`base_id`,`to_id`,`volume`,`rate`)
			VALUES ({$data->base_id},{$data->to_id},{$data->volume},{$data->rate});
		";
		$wpdb->query( $this->prepare_query( $query ) );
	}

	/**
	 * Return latest rates query history
	 */
	function get_history( $limit = 10 ) {

		global $wpdb;
		$query = "
			SELECT history.*, list1.symbol as base_symbol, list2.symbol as to_symbol
			FROM `DB_PREFIX_history` as history 
				LEFT JOIN `DB_PREFIX_list` as list1 ON history.base_id=list1.id
				LEFT JOIN `DB_PREFIX_list` as list2 ON history.to_id=list2.id
			ORDER BY history.id DESC LIMIT {$limit};
		";
		$results = $wpdb->get_results( $this->prepare_query( $query ) );

		return $results;
	}

	/** 
	 * ======================================
	 * HELPER FUNCTIONS
	 * ======================================
	 */

	/**
	 * Return symbol name by id from local database
	 */
	function get_symbol_id( $symbol ){
		global $wpdb;
		$query = "
			SELECT id FROM `DB_PREFIX_list`
			WHERE symbol=\"{$symbol}\"
			LIMIT 1;
		";
		$results = $wpdb->get_results( $this->prepare_query( $query ) );
		return @$results[0]->id; 
	}

	/**
	 * Return symbol id by name from local database
	 */
	function get_symbol_name( $id ){
		global $wpdb;
		$query = "
			SELECT symbol FROM `DB_PREFIX_list`
			WHERE id=\"{$id}\"
			LIMIT 1;
		";
		$results = $wpdb->get_results( $this->prepare_query( $query ) );
		return @$results[0]->symbol; 
	}

	/**
	 * Prepare mysql query: append tablename prefix
	 */
	function prepare_query( $query ){
		global $wpdb;
		return str_replace( 'DB_PREFIX_', $wpdb->prefix . SELF::DB_PREFIX, $query );
	}

} // class
