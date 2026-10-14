<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kekspay_Connector class
 *
 * @since 0.1
 */
class Kekspay_Connector {

	/**
	 * Return an array for default args or false if failed to JSON encode.
	 *
	 * @param  array  $body
	 * @return array|false
	 */
	private static function get_default_args( $body ) {
		$encoded_body = wp_json_encode( $body );

		if ( ! $encoded_body ) {
			return false;
		}

		// Log body data for refund request.
		Kekspay_Logger::log( $encoded_body, 'info' );

		return [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'method'  => 'POST',
			'timeout' => 55,
			'body'    => $encoded_body,
			'cookies' => [],
		];
	}

	/**
	 * Trigger refund for given order and amount.
	 *
	 * @param  WC_Order $order
	 * @param  float    $amount
	 *
	 * @return true|WP_Error True on success, WP_Error with failure reason otherwise.
	 */
	public static function refund( $order, $amount ) {
		if ( KEKSPAY_PLUGIN_ID !== $order->get_payment_method() ) {
			return new WP_Error( 'kekspay_refund_error', __( 'Narudžba nije plaćena putem KEKS Pay servisa.', 'keks-pay-for-woocommerce' ) );
		}

		$currency = $order->get_currency();

		$refund_amount   = $amount;
		$refund_currency = $currency;

		if ( 'HRK' === $currency ) {
			$refund_amount   = round( $amount / 7.5345, 2 );
			$refund_currency = 'EUR';
		}

		$timestamp = time();

		$hash = Kekspay_Data::get_hash( $order, $refund_amount, $timestamp );
		if ( ! $hash ) {
			return new WP_Error( 'kekspay_refund_error', __( 'Neuspješno kreiranje sigurnosnog potpisa za povrat, provjerite tajni ključ u postavkama KEKS Pay servisa.', 'keks-pay-for-woocommerce' ) );
		}

		$body = [
			'bill_id'   => Kekspay_Data::get_bill_id_by_order_id( $order->get_id() ),
			'tid'       => Kekspay_Data::get_order_settings( $order, 'webshop-tid' ),
			'cid'       => Kekspay_Data::get_order_settings( $order, 'webshop-cid' ),
			'amount'    => $refund_amount,
			'epochtime' => $timestamp,
			'hash'      => $hash,
			'algo'      => Kekspay_Data::get_algo( $order ),
			'currency'  => $refund_currency,
		];

		$args = self::get_default_args( $body );
		if ( ! $args ) {
			Kekspay_Logger::log( 'Failed to encode refund request for order ' . $order->get_id() . '.', 'error' );
			return new WP_Error( 'kekspay_refund_error', __( 'Neuspješno kreiranje zahtjeva za povrat putem KEKS Pay servisa.', 'keks-pay-for-woocommerce' ) );
		}

		$wc_price  = wc_price( $amount, [ 'currency' => $currency ] );
		$log_label = 'order ' . $order->get_id() . ' (' . $amount . $currency . ') via KEKS Pay';

		$response = wp_safe_remote_post( Kekspay_Data::get_kekspay_api_base( $order ) . 'keksrefund', $args );
		Kekspay_Logger::log( 'Request sent to refund ' . $log_label . '.', 'info' );

		if ( is_wp_error( $response ) ) {
			Kekspay_Logger::log( $response->get_error_message(), 'error' );
			/* translators: connection error message */
			return new WP_Error( 'kekspay_refund_error', sprintf( __( 'Neuspješno spajanje na KEKS Pay servis: %s', 'keks-pay-for-woocommerce' ), $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code > 299 ) {
			Kekspay_Logger::log( 'Refund for ' . $log_label . ' failed, does not have a success status code.', 'error' );
			/* translators: HTTP status code */
			return new WP_Error( 'kekspay_refund_error', sprintf( __( 'KEKS Pay servis je odbio zahtjev za povrat (HTTP %d).', 'keks-pay-for-woocommerce' ), $status_code ) );
		}

		$refund = wp_remote_retrieve_body( $response );
		if ( ! $refund ) {
			Kekspay_Logger::log( 'Refund for ' . $log_label . ' failed, body corrupted or missing.', 'error' );
			return new WP_Error( 'kekspay_refund_error', __( 'KEKS Pay servis je vratio prazan odgovor na zahtjev za povrat.', 'keks-pay-for-woocommerce' ) );
		}

		// Log response from refund request.
		Kekspay_Logger::log( $refund, 'info' );

		$response_data = json_decode( $refund );

		if ( isset( $response_data->status ) && 0 === $response_data->status ) {
			/* translators: successful refund */
			$note = sprintf( __( 'Uspješno izvršen povrat %s via KEKS Pay.', 'keks-pay-for-woocommerce' ), $wc_price );
			Kekspay_Logger::log( 'Successfully refunded ' . $log_label . '.', 'info' );
			$order->add_order_note( $note );
			$order->update_meta_data( 'kekspay_status', 0 < (float) $order->get_remaining_refund_amount() ? 'refunded_partially' : 'refunded' );
			$order->save();

			return true;
		}

		/* translators: failed refund */
		$note    = sprintf( __( 'Dogodila se greška pri povratu %s via KEKS Pay.', 'keks-pay-for-woocommerce' ), $wc_price );
		$message = isset( $response_data->message ) ? sanitize_text_field( $response_data->message ) : '';
		Kekspay_Logger::log( 'Failed to refund ' . $log_label . '. Message: ' . $message, 'error' );
		$order->add_order_note( $note );
		$order->save();

		return new WP_Error( 'kekspay_refund_error', trim( wp_strip_all_tags( $note ) . ' ' . $message ) );
	}
}
