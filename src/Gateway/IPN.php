<?php
/**
 * IPN class.
 *
 * @package Kekspay
 */

namespace Kekspay\Gateway;

use Kekspay\Plugin;
use Kekspay\Utilities\Data;
use Kekspay\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * IPN class, handles payment notifications from KEKS Pay and payment status checks from order pay page.
 *
 * @since 0.1
 */
class IPN {
	/**
	 * Params required in payment notification.
	 *
	 * @var array
	 */
	const REQUIRED_PARAMS = [ 'bill_id', 'status', 'keks_id', 'tid' ];

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_' . Data::get_wc_endpoint(), [ $this, 'do_checkout_status' ] );
		add_action( 'rest_api_init', [ $this, 'register_status_route' ] );
		add_action( 'wp_ajax_kekspay_status_check', [ $this, 'ajax_order_status' ] );
		add_action( 'wp_ajax_nopriv_kekspay_status_check', [ $this, 'ajax_order_status' ] );
	}

	/**
	 * Return URL of the REST endpoint used by order pay page to check payment status.
	 *
	 * @return string
	 */
	public static function get_status_endpoint() {
		return rest_url( 'kekspay/v1/order-status' );
	}

	/**
	 * Register REST route used by order pay page to check payment status.
	 *
	 * Uses POST so responses are never cached by page caches. Access is checked using order key in the callback, nonce
	 * is not used since it is the same for all visitors that are not logged in and goes stale on cached pages.
	 *
	 * @return void
	 */
	public function register_status_route() {
		register_rest_route(
			'kekspay/v1',
			'/order-status',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'get_order_status' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'order_id'  => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'order_key' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * REST endpoint returning KEKS Pay payment status of the order.
	 *
	 * @param \WP_REST_Request $request Request with order_id and order_key params.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_order_status( \WP_REST_Request $request ) {
		$response = $this->get_status_response( $request->get_param( 'order_id' ), $request->get_param( 'order_key' ) );

		if ( ! $response ) {
			return new \WP_Error(
				'kekspay_invalid_order',
				'Invalid order.',
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Admin-ajax fallback returning KEKS Pay payment status of the order, used when REST API is blocked, e.g. by
	 * security plugins. Also answers order pay pages opened before updating from 2.x which use this action.
	 *
	 * Access is checked using order key, nonce sent by pages from 2.x is not required.
	 *
	 * @return void
	 */
	public function ajax_order_status() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Access is checked using order key.
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		// phpcs:enable

		$response = $this->get_status_response( $order_id, $order_key );

		if ( ! $response ) {
			$this->respond_error( 'Invalid order.' );
		}

		wp_send_json( $response );
	}

	/**
	 * Return KEKS Pay payment status of the order and URL to redirect to once payment is completed or failed.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $order_key Order key proving access to the order.
	 *
	 * @return array|false Status and redirect URL, false if order is not found or key doesn't match.
	 */
	private function get_status_response( $order_id, $order_key ) {
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || Plugin::ID !== $order->get_payment_method() || ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return false;
		}

		$status = $order->get_meta( 'kekspay_status' );

		$response = [
			'status'   => $status,
			'redirect' => null,
		];

		if ( 'failed' === $status ) {
			// WooCommerce doesn't start session for REST requests, it's needed to show the notice after redirect.
			if ( ! WC()->session ) {
				WC()->initialize_session();
			}

			/* translators: payment failed on checkout QR scanning */
			wc_add_notice( sprintf( __( 'Nešto je pošlo po zlu pri pokušaju naplate vaše narudžbe (#%d) putem KEKS Pay servisa, molimo pokušajte ponoviti narudžbu ili kontaktirajte administratora web trgovine za više informacija.', 'keks-pay-for-woocommerce' ), $order->get_id() ), 'error' );
			$response['redirect'] = $order->get_cancel_order_url_raw();
		} elseif ( 'success' === $status ) {
			$response['redirect'] = $order->get_checkout_order_received_url();
		}

		return $response;
	}

	/**
	 * Handle payment notification sent by KEKS Pay to the webhook URL shown in gateway settings.
	 *
	 * KEKS Pay sends JSON body with string values and the token in the URL.
	 *
	 * @return void
	 */
	public function do_checkout_status() {
		if ( ! $this->verify_kekspay_token() ) {
			Logger::log( 'Failed to verify token.', 'error' );
			$this->respond_error( 'Webshop authentication failed, token mismatch.' );
		}

		$params = $this->get_request_params();
		Logger::log( wp_json_encode( $params ), 'info' );

		$this->validate_params( $params );

		$order = $this->get_verified_order( $params );

		// Ignore repeated or late notifications for orders that are already paid or refunded.
		if ( in_array( $order->get_meta( 'kekspay_status' ), [ 'success', 'refunded', 'refunded_partially' ], true ) ) {
			Logger::log( 'Order ' . $order->get_id() . ' is already paid via KEKS Pay, ignoring IPN with status ' . $params['status'] . '.', 'notice' );
			$this->respond_accepted();
		}

		if ( 0 === (int) $params['status'] ) {
			if ( $this->amount_matches( $order, $params ) ) {
				$this->complete_payment( $order, $params );
			} else {
				$this->hold_payment( $order, $params );
			}
		} else {
			$this->fail_payment( $order, $params );
		}

		$this->respond_accepted();
	}

	/**
	 * Return params from JSON request body, or from POST and query params for requests not sending JSON.
	 *
	 * Only scalar values are kept, all as sanitized strings since values are compared to stored strings.
	 *
	 * @return array
	 */
	private function get_request_params() {
		$params = json_decode( (string) file_get_contents( 'php://input' ), true );

		if ( ! is_array( $params ) ) {
			// External request, authenticated by the token instead of nonce. POST params override query params like in $_REQUEST.
			$params = array_merge( wp_unslash( $_GET ), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		unset( $params['token'] );

		return array_map(
			function( $value ) {
				return sanitize_text_field( (string) $value );
			},
			array_filter( $params, 'is_scalar' )
		);
	}

	/**
	 * Respond with error if required params are missing or status is not an integer.
	 *
	 * @param array $params Request params.
	 *
	 * @return void
	 */
	private function validate_params( $params ) {
		if ( ! $params ) {
			Logger::log( 'Missing parameters in the request for IPN.', 'error' );
			$this->respond_error( 'Missing parameters.' );
		}

		foreach ( self::REQUIRED_PARAMS as $required_param ) {
			if ( ! isset( $params[ $required_param ] ) || '' === $params[ $required_param ] ) {
				Logger::log( 'Missing ' . $required_param . ' parametar in the request for IPN.', 'error' );
				$this->respond_error( 'Missing or corrupt required parametars.' );
			}
		}

		if ( false === filter_var( $params['status'], FILTER_VALIDATE_INT ) ) {
			Logger::log( 'Invalid status parametar in the request for IPN.', 'error' );
			$this->respond_error( 'Invalid payment status.' );
		}
	}

	/**
	 * Return order from the notification, respond with error if it doesn't exist or doesn't match the notification.
	 *
	 * @param array $params Request params.
	 *
	 * @return \WC_Order
	 */
	private function get_verified_order( $params ) {
		$order_id = Data::get_order_id_by_bill_id( $params['bill_id'] );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			Logger::log( 'Failed to find order ' . $params['bill_id'] . ' from the request for IPN.', 'error' );
			$this->respond_error( 'Couldn\'t find corresponding order ' . $params['bill_id'] . '.' );
		}

		if ( Plugin::ID !== $order->get_payment_method() ) {
			Logger::log( 'Order ' . $order_id . ' from the request for IPN was not placed using KEKS Pay.', 'error' );
			$this->respond_error( 'Order ' . $params['bill_id'] . ' was not placed using KEKS Pay.' );
		}

		// Check if recieved TID and bill ID match the ones used for the order.
		if ( Data::get_order_settings( $order, 'webshop-tid' ) !== $params['tid'] || Data::get_bill_id_by_order_id( $order_id ) !== $params['bill_id'] ) {
			Logger::log( 'Recieved TID ' . $params['tid'] . ' or bill ID ' . $params['bill_id'] . ' does not match the order in the request for IPN.', 'error' );
			$this->respond_error( 'Webshop verification failed, mismatch for TID ' . $params['tid'] . '.' );
		}

		return $order;
	}

	/**
	 * Return false if paid amount or currency from the notification doesn't match the order.
	 *
	 * Amount is sent in currency units (e.g. "225" for 225.00 EUR), it is checked only when sent.
	 *
	 * @param \WC_Order $order  Order to check.
	 * @param array     $params Request params.
	 *
	 * @return bool
	 */
	private function amount_matches( $order, $params ) {
		if ( ! isset( $params['amount'] ) || '' === $params['amount'] ) {
			Logger::log( 'Amount is missing in the request for IPN, skipping amount check for order ' . $order->get_id() . '.', 'notice' );
			return true;
		}

		if ( isset( $params['currency'] ) && '' !== $params['currency'] && strtoupper( $params['currency'] ) !== $order->get_currency() ) {
			return false;
		}

		$amount = str_replace( ',', '.', $params['amount'] );

		return is_numeric( $amount ) && (int) round( (float) $amount * 100 ) === (int) round( (float) $order->get_total() * 100 );
	}

	/**
	 * Put order on hold when paid amount doesn't match the order total, so the payment is checked manually.
	 *
	 * KEKS Pay status is set to success since the customer paid, so order pay page redirects to order received page.
	 *
	 * @param \WC_Order $order  Order which was paid.
	 * @param array     $params Request params.
	 *
	 * @return void
	 */
	private function hold_payment( $order, $params ) {
		$currency = isset( $params['currency'] ) && '' !== $params['currency'] ? strtoupper( $params['currency'] ) : $order->get_currency();

		Logger::log( 'KEKS Pay paid amount ' . $params['amount'] . ' ' . $currency . ' does not match total ' . $order->get_total() . ' ' . $order->get_currency() . ' of order ' . $order->get_id() . ', putting order on hold.', 'error' );

		$order->update_meta_data( 'kekspay_status', 'success' );
		$order->update_meta_data( 'kekspay_id', $params['keks_id'] );
		$order->set_transaction_id( $params['keks_id'] );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: paid amount, 2: order total */
				__( 'KEKS Pay je naplatio %1$s, a iznos narudžbe je %2$s. Provjerite uplatu prije obrade narudžbe.', 'keks-pay-for-woocommerce' ),
				$params['amount'] . ' ' . $currency,
				$order->get_total() . ' ' . $order->get_currency()
			)
		);
	}

	/**
	 * Mark order as paid using order status set in gateway settings.
	 *
	 * @param \WC_Order $order  Order to complete.
	 * @param array     $params Request params.
	 *
	 * @return void
	 */
	private function complete_payment( $order, $params ) {
		Logger::log( 'KEKS Pay successfully completed payment for order ' . $order->get_id() . ', message: ' . ( $params['message'] ?? '' ), 'info' );

		$order->update_meta_data( 'kekspay_status', 'success' );
		$order->update_meta_data( 'kekspay_id', $params['keks_id'] );
		$order->add_order_note( __( 'Narudžba uspješno plaćena putem KEKS Pay aplikacije.', 'keks-pay-for-woocommerce' ) );

		$order_id    = $order->get_id();
		$paid_status = Data::get_settings( 'payed-order-status' );

		// Ignore statuses that make no sense after payment or are no longer registered, e.g. custom status plugin was removed.
		if ( ! isset( Settings::get_payment_complete_statuses()[ $paid_status ] ) ) {
			$paid_status = '';
		}

		$set_status = function( $status, $id ) use ( $order_id, $paid_status ) {
			return (int) $id === $order_id && $paid_status ? $paid_status : $status;
		};

		add_filter( 'woocommerce_payment_complete_order_status', $set_status, 10, 2 );
		$order->payment_complete( $params['keks_id'] );
		remove_filter( 'woocommerce_payment_complete_order_status', $set_status, 10 );

		$order->save();
	}

	/**
	 * Mark KEKS Pay payment of the order as failed.
	 *
	 * @param \WC_Order $order  Order which payment failed.
	 * @param array     $params Request params.
	 *
	 * @return void
	 */
	private function fail_payment( $order, $params ) {
		Logger::log( 'KEKS Pay failed to complete payment for order ' . $order->get_id() . ', message: ' . ( $params['message'] ?? '' ), 'error' );

		$order->update_meta_data( 'kekspay_status', 'failed' );
		$order->add_order_note( __( 'Dogodila se greška pri naplati putem KEKS Pay aplikacije.', 'keks-pay-for-woocommerce' ) );
		$order->save();
	}

	/**
	 * Compare token from the request with the webshop token.
	 *
	 * Token is sent in the webhook URL, Authorization header ("Token <token>") is supported for older integrations.
	 *
	 * @return bool
	 */
	private function verify_kekspay_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- External request authenticated by the token.
		$token     = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		$token_src = 'REQUEST';
		// phpcs:enable

		if ( ! $token ) {
			$header    = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$token     = sanitize_text_field( preg_replace( '/^Token\s+/i', '', wp_unslash( $header ) ) );
			$token_src = 'SERVER';
		}

		if ( ! $token ) {
			Logger::log( 'Failed to recieve authentication token.', 'error' );
			$this->respond_error( 'Authentication token missing, failed to verify.' );
		}

		Logger::log( 'Token src: ' . $token_src, 'info' );

		$auth_token = (string) Data::get_settings( 'auth-token' );
		if ( ! $auth_token ) {
			Logger::log( 'Webshop authentication token is not generated yet, open gateway settings to generate it.', 'error' );
			return false;
		}

		return hash_equals( $auth_token, $token );
	}

	/**
	 * Respond to KEKS Pay that the notification was accepted.
	 *
	 * @return void
	 */
	private function respond_accepted() {
		wp_send_json(
			[
				'status'  => 0,
				'message' => 'Accepted',
			]
		);
	}

	/**
	 * Respond to KEKS Pay with error.
	 *
	 * @param string $message Message describing why the notification was rejected.
	 *
	 * @return void
	 */
	private function respond_error( $message ) {
		wp_send_json(
			[
				'status'  => -1,
				'message' => $message,
			],
			400
		);
	}
}
