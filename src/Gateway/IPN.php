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
 * IPN class
 *
 * @since 0.1
 */
class IPN {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_' . Data::get_wc_endpoint(), [ $this, 'do_checkout_status' ] );
		add_action( 'rest_api_init', [ $this, 'register_status_route' ] );
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
	 * Die with given message, encoded as JSON, and set HTTP response status.
	 *
	 * @param  mixed   $message
	 * @param  integer $status_code Defaults to 200.
	 */
	private function respond( $message, $status_code = 200 ) {
		status_header( $status_code );
		header( 'content-type: application/json; charset=utf-8' );

		$encoded_message = wp_json_encode( $message );

		if ( ! $encoded_message ) {
			Logger::log( 'Failed to encode API response message.', 'error' );
			$encoded_message = -1;
		}

		die( $encoded_message ); //@codingStandardsIgnoreLine - escaping not needed
	}

	/**
	 * Die with error and given message, encoded as JSON, and set HTTP response status.
	 *
	 * @param string $message Message description about the failure of request.
	 */
	private function respond_error( $message ) {
		status_header( 400 );
		header( 'content-type: application/json; charset=utf-8' );

		$encoded_message = wp_json_encode(
			[
				'status'  => -1,
				'message' => $message,
			]
		);

		if ( ! $encoded_message ) {
			Logger::log( 'Failed to encode API response message.', 'error' );
			$encoded_message = -1;
		}

		die( $encoded_message ); //@codingStandardsIgnoreLine - escaping not needed
	}

	/**
	 * Return assoc array of parameters from either 'php://input' (POST request body), or $_REQUEST.
	 *
	 * @return array $params
	 */
	private function resolve_params() {
		$params = json_decode( file_get_contents( 'php://input' ), true );
		if ( empty( $params ) ) {
			// NOTE: external request, signature is checked in the calling method to determine validity.
		  // phpcs:disable WordPress.Security.NonceVerification.Recommended
			$params = $_REQUEST;
		  // phpcs:enable
		}

		if ( ! $params ) {
			return false;
		}

		return array_map(
			function( $item ) {
				return filter_var( $item, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			},
			$params
		);
	}

	/**
	 * Return KEKS Pay payment status of the order and URL to redirect to once payment is completed or failed.
	 *
	 * @param \WP_REST_Request $request Request with order_id and order_key params.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_order_status( \WP_REST_Request $request ) {
		$order_key = $request->get_param( 'order_key' );
		$order     = wc_get_order( $request->get_param( 'order_id' ) );

		if ( ! $order || Plugin::ID !== $order->get_payment_method() || ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return new \WP_Error(
				'kekspay_invalid_order',
				'Invalid order.',
				[ 'status' => 400 ]
			);
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

		return rest_ensure_response( $response );
	}

	/**
	 * Should be used as a callback URL for KEKS Pay API checkout request.
	 */
	public function do_checkout_status() {
		// Verify token authorization.
		if ( ! $this->verify_kekspay_token() ) {
			Logger::log( 'Failed to verify token.', 'error' );
			$this->respond_error( 'Webshop authentication failed, token mismatch.' );
		}

		$params = $this->resolve_params();
		if ( is_array( $params ) ) {
			unset( $params['token'] );
		}
		Logger::log( wp_json_encode( $params ), 'info' );
		// Check if any parametars are received.
		if ( ! $params ) {
			Logger::log( 'Missing parameters in the request for IPN.', 'error' );
			$this->respond_error( 'Missing parameters.' );
		}

		// Check if required params are recieved.
		foreach ( [ 'bill_id', 'status', 'keks_id', 'tid' ] as $required_param ) {
			if ( ! isset( $params[ $required_param ] ) ) {
				Logger::log( 'Missing ' . $required_param . ' parametar in the request for IPN.', 'error' );
				$this->respond_error( 'Missing or corrupt required parametars.' );
			}
		}

		// Check if status is a valid value.
		$status = filter_var( $params['status'], FILTER_VALIDATE_INT );
		if ( false === $status ) {
			Logger::log( 'Invalid status parametar in the request for IPN.', 'error' );
			$this->respond_error( 'Invalid payment status.' );
		}

		// Extract order id and check if order exists.
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
		if ( $params['tid'] !== Data::get_order_settings( $order, 'webshop-tid' ) || Data::get_bill_id_by_order_id( $order_id ) !== $params['bill_id'] ) {
			Logger::log( 'Recieved TID ' . $params['tid'] . ' or bill ID ' . $params['bill_id'] . ' does not match the order in the request for IPN.', 'error' );
			$this->respond_error( 'Webshop verification failed, mismatch for TID ' . $params['tid'] . '.' );
		}

		$message = isset( $params['message'] ) ? $params['message'] : '';

		// Ignore repeated or late notifications for orders that are already paid or refunded.
		if ( in_array( $order->get_meta( 'kekspay_status' ), [ 'success', 'refunded', 'refunded_partially' ], true ) ) {
			Logger::log( 'Order ' . $order_id . ' is already paid via KEKS Pay, ignoring IPN with status ' . $status . '.', 'notice' );
			$this->respond(
				[
					'status'  => 0,
					'message' => 'Accepted',
				]
			);
		}

		if ( 0 === $status ) {
			Logger::log( 'KEKS Pay successfully completed payment for order ' . $order_id . ', message: ' . $message, 'info' );

			$order->update_meta_data( 'kekspay_status', 'success' );
			$order->update_meta_data( 'kekspay_id', $params['keks_id'] );
			$order->add_order_note( __( 'Narudžba uspješno plaćena putem KEKS Pay aplikacije.', 'keks-pay-for-woocommerce' ) );

			$paid_status = Data::get_settings( 'payed-order-status' );
			$set_status  = function( $status, $id ) use ( $order_id, $paid_status ) {
				return (int) $id === (int) $order_id && $paid_status ? $paid_status : $status;
			};

			add_filter( 'woocommerce_payment_complete_order_status', $set_status, 10, 2 );
			$order->payment_complete( $params['keks_id'] );
			remove_filter( 'woocommerce_payment_complete_order_status', $set_status, 10 );

			$order->save();
		} else {
			Logger::log( 'KEKS Pay failed to complete payment for order ' . $order_id . ', message: ' . $message, 'error' );
			$order->update_meta_data( 'kekspay_status', 'failed' );
			$order->add_order_note( __( 'Dogodila se greška pri naplati putem KEKS Pay aplikacije.', 'keks-pay-for-woocommerce' ) );
			$order->save();
		}

		$this->respond(
			[
				'status'  => 0,
				'message' => 'Accepted',
			]
		);
	}

	/**
	 * Compares tokens to verify valid request.
	 *
	 * @return bool
	 */
	private function verify_kekspay_token() {
		$token_src = 'REQUEST';
		$token     = isset( $_REQUEST['token'] ) ? filter_var( wp_unslash( $_REQUEST['token'] ), FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : false; //@codingStandardsIgnoreLine - safe use of REQUEST.
		if ( ! $token ) { // Legacy check.
			$token_src = 'SERVER';
			$token     = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? filter_var( $_SERVER['HTTP_AUTHORIZATION'], FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : false; //@codingStandardsIgnoreLine - safe use of SERVER.
		}
		if ( ! $token ) {
			Logger::log( 'Failed to recieve authentication token.', 'error' );
			$this->respond_error( 'Authentication token missing, failed to verify.' );
		} else {
			Logger::log( 'Token src: ' . $token_src, 'info' );
		}

		$auth_token = (string) Data::get_settings( 'auth-token' );
		if ( ! $auth_token ) {
			Logger::log( 'Webshop authentication token is not generated yet, open gateway settings to generate it.', 'error' );
			return false;
		}

		return hash_equals( $auth_token, str_replace( 'Token ', '', $token ) );
	}
}
