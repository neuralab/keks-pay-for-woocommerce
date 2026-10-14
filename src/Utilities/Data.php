<?php
/**
 * Data class.
 *
 * @package Kekspay
 */

namespace Kekspay\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Data class
 *
 * @since 0.1
 */
class Data {
	/**
	 * Gateway settings loaded from the database.
	 *
	 * @var array
	 */
	private static $settings = [];

	/**
	 * Set endpoint for webshop api.
	 *
	 * @var string
	 */
	private static $endpoint = 'wc-kekspay';

	/**
	 * Set base url of kekspay pay.
	 *
	 * @var string
	 */
	private static $kekspay_pay = 'https://kekspay.hr/';

	/**
	 * Set base url of kekspay API.
	 *
	 * @var string
	 */
	private static $kekspay_api = 'https://ewa.erstebank.hr/eretailer/';

	/**
	 * Set base test url of kekspay API.
	 *
	 * @var string
	 */
	private static $test_kekspay_api = 'https://kekspayuat.erstebank.hr/eretailer/';

	/**
	 * Init data class.
	 */
	public function __construct() {
		self::load_settings();
	}

	/**
	 * Load gateway settings from the database.
	 *
	 * @return void
	 */
	public static function load_settings() {
		self::$settings = get_option( 'woocommerce_' . KEKSPAY_PLUGIN_ID . '_settings', [] );
	}

	/**
	 * Save gateway settings to the database.
	 *
	 * @param array $settings Settings to merge with the existing ones.
	 *
	 * @return void
	 */
	public static function set_settings( $settings ) {
		update_option( 'woocommerce_' . KEKSPAY_PLUGIN_ID . '_settings', array_merge( self::get_settings(), $settings ) );
	}

	/**
	 * Returns if payment gateway is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		return isset( self::$settings['enabled'] ) && 'yes' === self::$settings['enabled'];
	}

	/**
	 * Returns true if test mode is turned on, false otherwise.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		return isset( self::$settings['in-test-mode'] ) && 'yes' === self::$settings['in-test-mode'];
	}

	/**
	 * Returns true if order was created in test mode, false otherwise.
	 *
	 * @return bool
	 */
	public static function order_test_mode( $order ) {
		return 'yes' === $order->get_meta( 'kekspay_test_mode' );
	}

	/**
	 * Returns true if required keys in gateways settings are set, false otherwise.
	 *
	 * @return bool
	 */
	public static function required_keys_set() {
		if ( ! self::get_settings( 'webshop-cid', true ) || ! self::get_settings( 'webshop-tid', true ) || ! self::get_settings( 'webshop-secret-key', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns true if currency is EUR.
	 *
	 * @return bool
	 */
	public static function currency_supported() {
		return 'EUR' === get_woocommerce_currency();
	}

	/**
	 * Fetch settings for use.
	 *
	 * @param string $name       Name of specific setting to fetch.
	 * @param bool   $test_check Whether to check if test mode is on to fetch test version of the setting.
	 *
	 * @return array|string|null
	 */
	public static function get_settings( $name = false, $test_check = false ) {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		if ( $name ) {
			if ( $test_check ) {
				$name = self::test_mode() ? 'test-' . $name : $name;
			}

			return isset( self::$settings[ $name ] ) ? self::$settings[ $name ] : null;
		}

		return self::$settings;
	}

	/**
	 * Fetch setting using the mode (test or live) in which the order was created.
	 *
	 * @param WC_Order $order Order for which to fetch the setting.
	 * @param string   $name  Name of specific setting to fetch.
	 *
	 * @return string|null
	 */
	public static function get_order_settings( $order, $name ) {
		return self::get_settings( self::order_test_mode( $order ) ? 'test-' . $name : $name );
	}

	/**
	 * Return auth token or generate if there is none.
	 *
	 * @return string
	 */
	public static function get_auth_token() {
		$token = self::get_settings( 'auth-token' );
		if ( ! $token ) {
			$token = hash_hmac( 'sha256', bin2hex( openssl_random_pseudo_bytes( 64 ) ), site_url() );

			self::set_settings( [ 'auth-token' => $token ] );
		}

		return $token;
	}

	/**
	 * Return contents of the SVG file from the plugin's images folder.
	 *
	 * @param string $svg SVG file name without extension.
	 *
	 * @return string|false SVG markup, false if file does not exist.
	 */
	public static function get_svg( $svg ) {
		if ( ! $svg || ! file_exists( KEKSPAY_DIR_PATH . 'assets/dist/img/' . $svg . '.svg' ) ) {
			return false;
		}

		return file_get_contents( KEKSPAY_DIR_PATH . 'assets/dist/img/' . $svg . '.svg' ); // @codingStandardsIgnoreLine - safe use of file_get_contents
	}

	/**
	 * Return gateway endpoint on wc api.
	 *
	 * @param bool $absolute Wheter to fetch full url with endpoint or only the endpoint.
	 *
	 * @return string
	 */
	public static function get_wc_endpoint( $absolute = false ) {
		return $absolute ? untrailingslashit( WC()->api_request_url( self::$endpoint ) ) : self::$endpoint;
	}

	/**
	 * Return gateway url for KEKS Pay servers.
	 *
	 * @return string
	 */
	public static function get_kekspay_pay_base( $trailingslash = false ) {
		$endpoint = self::$kekspay_pay . ( self::test_mode() ? 'galebpay' : 'pay' );
		return $trailingslash ? trailingslashit( $endpoint ) : $endpoint;
	}

	/**
	 * Return gateway url for KEKS Pay servers.
	 *
	 * @return string
	 */
	public static function get_kekspay_api_base( $order = null ) {
		$test_mode = $order ? self::order_test_mode( $order ) : self::test_mode();
		return $test_mode ? self::$test_kekspay_api : self::$kekspay_api;
	}

	/**
	 * Creates endpoint message for settings.
	 *
	 * @return string
	 */
	public static function get_settings_token_field() {
		return sprintf(
			/* translators: token info. */
			__( '%1$s <br><br>Nakon pohrane unutar KEKS Pay sustava omogućava ovoj trgovini primanje obavijesti o stanju naplate. %2$s Kontakt %3$s', 'keks-pay-for-woocommerce' ),
			'<b><code class="kekspay-webhook">' . add_query_arg( 'token', self::get_auth_token(), self::get_wc_endpoint( true ) ) . '</code></b>',
			'<a href="mailto:kekspay@erstebank.hr">',
			'</a>'
		);
	}

	/**
	 * Creates unique bill id using webshop cid and order id.
	 *
	 * @return string
	 */
	public static function get_bill_id_by_order_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( 'Could not fetch order with ID ' . $order_id . ' while generating bill_id.', 'error' );
			return false;
		}
		return self::get_order_settings( $order, 'webshop-tid' ) . '-' . $order->get_id();
	}

	/**
	 * Extract order id from kekspay bill id formatted as "{tid}-{order_id}".
	 *
	 * @return int Order id, 0 if bill id is malformed.
	 */
	public static function get_order_id_by_bill_id( $bill_id ) {
		$separator = strrpos( (string) $bill_id, '-' );
		if ( false === $separator ) {
			return 0;
		}

		$order_id = substr( $bill_id, $separator + 1 );

		return ctype_digit( $order_id ) ? (int) $order_id : 0;
	}

	/**
	 * Return "algo" parameter for the cipher used with order's secret key, 0 for 3DES and 1 for AES.
	 *
	 * @param  WC_Order $order Order for which to fetch the secret key.
	 *
	 * @return int
	 */
	public static function get_algo( $order ) {
		return ctype_xdigit( (string) self::get_order_settings( $order, 'webshop-secret-key' ) ) ? 0 : 1;
	}

	/**
	 * Gathers all data needed for payment and formats it as array.
	 *
	 * @param  object $order     Order from which to extract data.
	 * @param  bool   $callbacks Whether to include callback urls or not.
	 *
	 * @return array/bool    Extracted data as array, false on failure.
	 */
	public static function get_sell_data( $order, $callbacks = false ) {
		if ( ! self::required_keys_set() ) {
			Logger::log( 'Payment gateway setup incomplete, please enter all requested data to gateway settings.', 'error' );
			return false;
		}

		$sell = [
			'qr_type'  => 1,
			'cid'      => self::get_settings( 'webshop-cid', true ),
			'tid'      => self::get_settings( 'webshop-tid', true ),
			'bill_id'  => self::get_bill_id_by_order_id( $order->get_id() ),
			'amount'   => $order->get_total(),
			'currency' => $order->get_currency(),
		];

		if ( $callbacks ) {
			$sell['success_url'] = $order->get_checkout_order_received_url();
			$sell['fail_url']    = $order->get_cancel_order_url_raw();
		}

		return $sell;
	}

	/**
	 * Detects the cipher to use based on the key stored.
	 * Currently supporting: - 3DES
	 *                       - AES
	 *
	 * @param  string   $key  Secret key used for hashing.
	 *
	 * @throws \Exception     Issues with key length.
	 *
	 * @return string
	 */
	public static function get_cipher( $key ) {
		$key_size = strlen( $key );

		if ( ctype_xdigit( $key ) ) {
			if ( $key_size === 24 ) {
				return 'des-ede3-cbc';
			} else {
				throw new \Exception( 'Secret key must be 24 bytes.' );
			}
		} else {
			if ( in_array( $key_size, [ 16, 24, 32 ], true ) ) {
				return 'aes-' . ( $key_size * 8 ) . '-cbc';
			} else {
				throw new \Exception( 'Secret key must be 16, 24 or 32 bytes.' );
			}
		}
	}

	/**
	 * Return hash created from the provided data and secret.
	 *
	 * @param  object $order     Order from which to extract data for hash.
	 * @param  string $amount    Amount used for creating hash.
	 * @param  string $timestamp Timestamp for creating hash.
	 *
	 * @return string|false Hash, false on failure.
	 */
	public static function get_hash( $order, $amount, $timestamp ) {
		try {
			// Get hashing key from the plugins settings.
			$key = (string) self::get_order_settings( $order, 'webshop-secret-key' );
			// Define the cipher used for hashing.
			$cipher = self::get_cipher( $key );
			// Concat epochtime + webshop tid + order amount + bill_id for payload.
			$payload = $timestamp . self::get_order_settings( $order, 'webshop-tid' ) . $amount . self::get_bill_id_by_order_id( $order->get_id() );
			// Extract bytes from md5 hex hash.
			$payload_checksum = pack( 'H*', md5( $payload ) );
			// Create 8 or 16 (depending on cipher) byte binary initialization vector.
			$iv = str_repeat( pack( 'c', 0 ), false !== strpos( $cipher, 'aes' ) ? 16 : 8 );
			// Encrypt data using 3DES or AES cipher and convert it to hex.
			$hash = bin2hex( openssl_encrypt( $payload_checksum, $cipher, $key, OPENSSL_RAW_DATA, $iv ) );

			return strtoupper( $hash );
		} catch ( \Exception $e ) {
			Logger::log( 'Error while generating hash:' . $e->getMessage(), 'error' );
		}

		return false;
	}

}
