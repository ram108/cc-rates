<?php

/**
 * [exchange-rates] shortcode
 */
add_shortcode( 'exchange-rates', function(){

    global $cc_rates;

	// get "base" list
	// Limitation of this work: convert BTC only
	$base = array( (object) array( 'id' => 1, 'symbol' => 'BTC', 'name' => 'Bitcoin' ) );
	$base_html = '';
	foreach( $base as $item ) {
		$base_html .= '<option value="'. $item->id .'">'.$item->symbol.' - '.$item->name.'</option>';
	}

	// get "to" list
	$to = $cc_rates->get_list( 'BTC' );
	$to_html = '';
	foreach( $to as $item ) {
		$to_html .= '<option value="'. $item->id .'">'.$item->symbol.' - '.$item->name.'</option>';
	}
	
	// prepare output
	ob_start();
	?>

	<div class="exchange-rates">
		<form>
			<div>
				<input class="volume" type="text" name="volume" pattern="[0-9.]+">
				<select class="base"><?php echo $base_html; ?></select>
			</div>
			<div>
				<input class="price" type="text" name="price" readonly>
				<select class="to"><?php echo $to_html; ?></select>
			</div>
		</form>
	</div>

	<?php
	$output = ob_get_contents();
	ob_end_clean();

	// return output
	return $output;
});

/**
 * [exchange-history] shortcode
 */
add_shortcode( 'exchange-history', function(){

	// prepare output
	ob_start();
	?>

	<div class="exchange-history"></div>

	<?php
	$output = ob_get_contents();
	ob_end_clean();

	// return output
	return $output;
});

/**
 * Append CSS, JS to the footer
 * TODO: append only if shortcode is used and use external css, js file
 */
add_action('wp_footer', function(){
?>
	<style>
		.exchange-rates {
			display: block;
			max-width: 600px;
			width: 100%;
			padding: 25px;
			background-color: #f3f7fd;
			border: 2px solid #e8f1ff;
		}
		.exchange-rates div {
			margin: 10px 0;
		}
		.exchange-rates input, .exchange-rates select {
			max-width: 200px;
			width: 100%;
			font-size: 16px;
			line-height: 25px;
			height: 40px;
		}
		.exchange-rates input {
			font-weight: bold;
		}
	</style>

	<script>
	(function($){

		var rates = $('.exchange-rates');
		var history = $('.exchange-history');

		// update user exchange rate
		function update_rates(){
			if ( $('.volume', rates).val() == 0 ) $('.price', rates).val('');
			if ( input_changed() == false ) return;
			$.post({
				url: '<?php echo admin_url('admin-ajax.php'); ?>',
				data: { 
					action: 'cc_rates_get_rate',
					base: $('.base', rates).val(),
					to: $('.to', rates).val(),
					volume: $('.volume', rates).val(),
				},
				success: function( response ) {
					$('.price', rates).val( response );
					update_history();
				}
			});
		}

		// update user exchange history
		function update_history(){
			$.post({
				url: '<?php echo admin_url('admin-ajax.php'); ?>',
				data: { 
					action: 'cc_rates_get_history' 
				},
				success: function( response ) {
					$(history).html( response );
				}
			});
		}

		// input pattern filter
		$('input[type=text][pattern]').on('input', function () {
			if (!this.checkValidity()) this.value = this.value.slice(0, -1);
		});

		// check if user change input data
		var _base = $('.base', rates).val();
		var _to = $('.to', rates).val();
		var _volume = $('.volume', rates).val();
		function input_changed(){
			if ( $('.volume', rates).val() == 0 ) return false;
			if ( _base != $('.base', rates).val() || _to != $('.to', rates).val() || _volume != $('.volume', rates).val() ) {
				_base = $('.base', rates).val();
				_to = $('.to', rates).val();
				_volume = $('.volume', rates).val();
				return true;
			}
			return false;
		}

		// initializate app
		$('.volume, .base, .to', rates).on('input', function(){ update_rates(); });
		$('.volume', rates).val('1').trigger('input').focus();

	}(jQuery));
	</script>
<?php
});

/**
 * Exchange rates ajax endpoint: return data
 */
function cc_rates_get_rate() {

	global $cc_rates;
	// get data
	// TODO: sanitize and check data
	$data = $cc_rates->get_rate( (int)$_POST['base'], (int)$_POST['to'], (double)$_POST['volume'] );

	// send data
	wp_die( $data->price );
}

/**
 * Exchange history ajax endpoint: return data
 */
function cc_rates_get_history() {

	global $cc_rates;

	// get data
	$data = $cc_rates->get_history();

	// prepare output
	ob_start();
	?>

	<ul>
		<?php foreach( $data as $item ): ?>
			<li><?php echo $item->volume;?> <?php echo $item->base_symbol;?> to <?php echo $item->to_symbol;?> <?php echo human_time_diff( strtotime( $item->date ), current_time( 'timestamp' ) ) . ' ago'; ?></li>
		<?php endforeach; ?>
	</ul>

	<?php
	$output = ob_get_contents();
	ob_end_clean();

	// send data
	wp_die( $output );
}

// attach ajax actions only if needed
if ( wp_doing_ajax() ){

	// rates action
	add_action( 'wp_ajax_nopriv_cc_rates_get_rate', 'cc_rates_get_rate' );
	add_action( 'wp_ajax_cc_rates_get_rate', 'cc_rates_get_rate' );

	// history action
	add_action( 'wp_ajax_nopriv_cc_rates_get_history', 'cc_rates_get_history' );
	add_action( 'wp_ajax_cc_rates_get_history', 'cc_rates_get_history' );
}
