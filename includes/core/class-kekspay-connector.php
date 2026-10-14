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
	 * @return array    [ 'success' => bool, 'message' => string ]
	 */
	public static function refund( $order, $amount ) {
		if ( 'erste-kekspay-woocommerce' !== $order->get_payment_method() ) {
			return;
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
			return false;
		}

		$body = [
			'bill_id'   => Kekspay_Data::get_bill_id_by_order_id( $order->get_id() ),
			'tid'       => Kekspay_Data::get_order_settings( $order, 'webshop-tid' ),
			'cid'       => Kekspay_Data::get_order_settings( $order, 'webshop-cid' ),
			'amount'    => $refund_amount,
			'epochtime' => $timestamp,
			'hash'      => $hash,
			'algo'      => Kekspay_Data::get_algo(),
			'currency'  => $refund_currency,
		];

		$wc_price = wc_price( $amount, [ 'currency' => $currency ] );

		$response = wp_safe_remote_post( Kekspay_Data::get_kekspay_api_base( $order ) . 'keksrefund', self::get_default_args( $body ) );
		Kekspay_Logger::log( 'Request sent to refund order ' . $order->get_id() . ' (' . $amount . $order->get_currency() . ') via KEKS Pay.', 'info' );

		if ( is_wp_error( $response ) ) {
			Kekspay_Logger::log( $response->get_error_message(), 'error' );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code > 299 ) {
			Kekspay_Logger::log( 'Refund for order ' . $order->get_id() . ' (' . $amount . $order->get_currency() . ') via KEKS Pay failed, does not have a success status code.', 'error' );
			return false;
		}

		$refund = wp_remote_retrieve_body( $response );
		if ( ! $refund ) {
			Kekspay_Logger::log( 'Refund for order ' . $order->get_id() . ' (' . $amount . $order->get_currency() . ') via KEKS Pay failed, body corrupted or missing.', 'error' );
			return false;
		}

		// Log response from refund request.
		Kekspay_Logger::log( $refund, 'info' );

		$response_data = json_decode( $refund );

		if ( isset( $response_data->status ) && 0 === $response_data->status ) {
			/* translators: successful refund */
			$note = sprintf( __( 'Uspješno izvršen povrat %s via KEKS Pay.', 'kekspay' ), $wc_price );
			Kekspay_Logger::log( 'Successfully refunded order ' . $order->get_id() . ' (' . $amount . $order->get_currency() . ') via KEKS Pay. Setting status refunded.', 'info' );
			$order->add_order_note( $note );
			$order->update_meta_data( 'kekspay_status', (int) $order->get_remaining_refund_amount() ? 'refunded_partially' : 'refunded' );
			$order->save();

			return true;
		} else {
			/* translators: failed refund */
			$note    = sprintf( __( 'Dogodila se greška pri povratu %s via KEKS Pay.', 'kekspay' ), $wc_price );
			$message = isset( $response_data->message ) ? $response_data->message : '';
			Kekspay_Logger::log( 'Failed to refund order ' . $order->get_id() . ' (' . $amount . $order->get_currency() . ') via KEKS Pay. Message: ' . $message, 'error' );
			$order->add_order_note( $note );
			$order->save();

			return false;
		}
	}
}
