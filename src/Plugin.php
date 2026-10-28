<?php
/**
 * Plugin class.
 *
 * @package Kekspay
 */

namespace Kekspay;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Kekspay\Admin\Order_Admin;
use Kekspay\Gateway\Block_Checkout;
use Kekspay\Gateway\IPN;
use Kekspay\Gateway\Payment_Gateway;
use Kekspay\Utilities\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The main plugin class.
 *
 * @since 0.1
 */
final class Plugin {
	/**
	 * Payment gateway ID, also used as a prefix of gateway's options, do not change.
	 *
	 * @var string
	 */
	const ID = 'erste-kekspay-woocommerce';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '3.0.1';

	/**
	 * Minimum required PHP version.
	 *
	 * @var string
	 */
	const REQUIRED_PHP_VERSION = '7.4';

	/**
	 * Minimum PHP version that will be required starting with the next major plugin version.
	 *
	 * @var string
	 */
	const UPCOMING_REQUIRED_PHP_VERSION = '8.1';

	/**
	 * Minimum required WooCommerce version.
	 *
	 * @var string
	 */
	const REQUIRED_WC_VERSION = '8.2';

	/**
	 * Instance of the current class, null before first usage.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Initialize the plugin once all plugins are loaded and WooCommerce is available.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ self::class, 'admin_notice_missing_woocommerce' ] );
			return;
		}

		Legacy_Aliases::register();

		self::get_instance();
	}

	/**
	 * Return class instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor, register hooks.
	 */
	private function __construct() {
		new IPN();
		new Order_Admin();

		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'check_requirements' ] );
		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( self::file() ), [ $this, 'add_settings_link' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_script' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_client_script' ] );
		add_action( 'current_screen', [ $this, 'check_settings' ] );
		add_action( 'current_screen', [ $this, 'check_upcoming_php_requirement' ] );
		add_action( 'admin_init', [ $this, 'check_for_other_kekspay_gateways' ], 1 );
		add_action( 'admin_init', [ Data::class, 'maybe_generate_auth_token' ] );
		add_action( 'activated_plugin', [ self::class, 'set_kekspay_plugins_check_required' ] );
		add_action( 'woocommerce_admin_field_payment_gateways', [ self::class, 'set_kekspay_plugins_check_required' ] );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_checkout_block_gateway' ] );
	}

	/**
	 * Return absolute path to the main plugin file.
	 *
	 * @return string
	 */
	public static function file() {
		return dirname( __DIR__ ) . '/woocommerce-gateway-kekspay.php';
	}

	/**
	 * Return absolute path to the plugin directory or a file within it.
	 *
	 * @param string $path Path relative to the plugin directory.
	 *
	 * @return string
	 */
	public static function path( $path = '' ) {
		return plugin_dir_path( self::file() ) . ltrim( $path, '/' );
	}

	/**
	 * Return URL of the plugin directory or a file within it.
	 *
	 * @param string $path Path relative to the plugin directory.
	 *
	 * @return string
	 */
	public static function url( $path = '' ) {
		return plugin_dir_url( self::file() ) . ltrim( $path, '/' );
	}

	/**
	 * Return URL of the gateway settings page.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::ID );
	}

	/**
	 * Declare plugin compatibility with WooCommerce HPOS and Checkout Blocks features.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', self::file(), true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', self::file(), true );
		}
	}

	/**
	 * Activation procedure.
	 *
	 * @return void
	 */
	public static function activate() {
		self::set_kekspay_plugins_check_required();
		Data::maybe_generate_auth_token();
	}

	/**
	 * Load plugin's textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'keks-pay-for-woocommerce', false, dirname( plugin_basename( self::file() ) ) . '/languages' );
	}

	/**
	 * Check versions of requirements and deactivate the plugin if they are not met.
	 *
	 * @return void
	 */
	public function check_requirements() {
		$requirements = [
			[
				'name'             => 'PHP',
				'current_version'  => phpversion(),
				'required_version' => self::REQUIRED_PHP_VERSION,
			],
			[
				'name'             => 'WooCommerce',
				'current_version'  => WC_VERSION,
				'required_version' => self::REQUIRED_WC_VERSION,
			],
		];

		$error_notices = [];

		foreach ( $requirements as $requirement ) {
			if ( version_compare( $requirement['current_version'], $requirement['required_version'], '<' ) ) {
				$error_notices[] = sprintf(
					/* translators: minimum version dependancies */
					__( 'Minimalna verzija %1$s je %2$s. Verzija koju trenutno koristite je %3$s. Molimo instalirajte minimalnu verziju %1$s ako želite koristiti KEKS Pay.', 'keks-pay-for-woocommerce' ),
					$requirement['name'],
					$requirement['required_version'],
					$requirement['current_version']
				);
			}
		}

		if ( $error_notices ) {
			add_action( 'admin_init', [ self::class, 'deactivate_self' ] );

			foreach ( $error_notices as $error_notice ) {
				self::admin_notice( $error_notice );
			}
		}
	}

	/**
	 * Add KEKS Pay payment method.
	 *
	 * @param array $methods Payment gateway classes.
	 *
	 * @return array
	 */
	public function add_gateway( $methods ) {
		$methods[] = Payment_Gateway::class;
		return $methods;
	}

	/**
	 * Check gateway settings and dispatch notice.
	 *
	 * @return void
	 */
	public function check_settings() {
		// If payment gateway is not enabled bail.
		if ( ! Data::enabled() ) {
			return;
		}

		// Show notices only on plugins and WooCommerce screens.
		$screen     = get_current_screen();
		$screen_ids = function_exists( 'wc_get_screen_ids' ) ? wc_get_screen_ids() : [];
		if ( ! $screen || ! in_array( $screen->id, array_merge( $screen_ids, [ 'plugins', 'dashboard' ] ), true ) ) {
			return;
		}

		// Check if gateway is currently in test mode.
		if ( Data::test_mode() ) {
			self::admin_notice( __( 'KEKS Pay je trenutno u testom načinu rada, ne zaboravite ga ugasiti po završetku testiranja.', 'keks-pay-for-woocommerce' ), 'warning' );
		}

		// Check if all setting keys required for gateway to work are set.
		if ( ! Data::required_keys_set() ) {
			self::admin_notice( __( 'KEKS Pay je trenutno onemogućen, molimo provjerite da su TID, CID i tajni ključ pravilno postavljeni.', 'keks-pay-for-woocommerce' ), 'warning' );
		}

		// Check if correct currency is set in webshop.
		if ( ! Data::currency_supported() ) {
			self::admin_notice( __( 'KEKS Pay je trenutno onemogućen, valuta web trgovine nije podržana. Podržane valute: "EUR".', 'keks-pay-for-woocommerce' ), 'warning' );
		}
	}

	/**
	 * Warn if the server's PHP version won't be supported by the next major plugin version.
	 *
	 * @return void
	 */
	public function check_upcoming_php_requirement() {
		if ( version_compare( PHP_VERSION, self::UPCOMING_REQUIRED_PHP_VERSION, '>=' ) ) {
			return;
		}

		// Show notice only on plugins and WooCommerce screens.
		$screen     = get_current_screen();
		$screen_ids = function_exists( 'wc_get_screen_ids' ) ? wc_get_screen_ids() : [];
		if ( ! $screen || ! in_array( $screen->id, array_merge( $screen_ids, [ 'plugins', 'dashboard', 'update-core' ] ), true ) ) {
			return;
		}

		self::admin_notice(
			sprintf(
				'<strong>%1$s</strong><br>%2$s',
				esc_html__( 'KEKS Pay for WooCommerce uskoro će zahtijevati PHP 8.1+', 'keks-pay-for-woocommerce' ),
				sprintf(
					/* translators: %s: current PHP version. */
					esc_html__( 'Vaša web trgovina trenutno koristi PHP %s. Počevši od verzije KEKS Pay 4.0.0, planirane za veljaču 2027., minimalna potrebna verzija bit će PHP 8.1. Kako biste nastavili primati buduća ažuriranja, molimo dogovorite se s pružateljem usluge hostinga oko nadogradnje PHP verzije na vašem poslužitelju.', 'keks-pay-for-woocommerce' ),
					esc_html( PHP_VERSION )
				)
			),
			'warning',
			true
		);
	}

	/**
	 * Check if there are other KEKS Pay gateways using the same gateway ID and deactivate this plugin if so.
	 *
	 * @return void
	 */
	public function check_for_other_kekspay_gateways() {
		if ( ! get_option( 'kekspay_plugins_check_required' ) ) {
			return;
		}

		delete_option( 'kekspay_plugins_check_required' );

		$payment_gateways = \WC_Payment_Gateways::instance()->payment_gateways();

		if ( isset( $payment_gateways[ self::ID ] ) && ! $payment_gateways[ self::ID ] instanceof Payment_Gateway ) {
			self::admin_notice( __( 'Možete imati samo jedan KEKS Pay način plaćanja aktivan u isto vrijeme. Dodatak "KEKS Pay for WooCommerce" je deaktiviran.', 'keks-pay-for-woocommerce' ) );

			self::deactivate_self();
		}
	}

	/**
	 * Set check for other KEKS Pay gateways as required.
	 *
	 * @return void
	 */
	public static function set_kekspay_plugins_check_required() {
		update_option( 'kekspay_plugins_check_required', 'yes' );
	}

	/**
	 * Register KEKS Pay method for block checkout.
	 *
	 * @return void
	 */
	public function register_checkout_block_gateway() {
		if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Block_Checkout() );
			}
		);
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate_self() {
		remove_filter( 'plugin_action_links_' . plugin_basename( self::file() ), [ self::get_instance(), 'add_settings_link' ] );
		remove_action( 'current_screen', [ self::get_instance(), 'check_settings' ] );

		deactivate_plugins( plugin_basename( self::file() ) );
		unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Add admin notice.
	 *
	 * @param string $notice      Notice content.
	 * @param string $type        Notice type.
	 * @param bool   $dismissible Whether the notice can be closed, it shows again on the next page load.
	 *
	 * @return void
	 */
	public static function admin_notice( $notice, $type = 'error', $dismissible = false ) {
		add_action(
			'admin_notices',
			function() use ( $notice, $type, $dismissible ) {
				printf( '<div class="notice notice-%2$s%3$s"><p>%1$s</p></div>', wp_kses_post( $notice ), esc_attr( $type ), $dismissible ? ' is-dismissible' : '' );
			}
		);
	}

	/**
	 * Echo admin notice for missing WooCommerce plugin.
	 *
	 * @return void
	 */
	public static function admin_notice_missing_woocommerce() {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p></div>',
			sprintf(
				/* translators: 1. URL link. */
				esc_html__( 'KEKS Pay zahtijeva WooCommerce dodatak instaliran i aktivan. Možete ga skinuti ovdje %s.', 'keks-pay-for-woocommerce' ),
				'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
			)
		);
	}

	/**
	 * Register plugin's admin JS script.
	 *
	 * @return void
	 */
	public function register_admin_script() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( strpos( $screen_id, 'wc-settings' ) !== false ) {
			$section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( $section && self::ID === $section ) {
				wp_enqueue_script(
					'kekspay-admin-script',
					self::url( 'assets/dist/js/kekspay-admin.js' ),
					[],
					self::VERSION,
					[
						'in_footer' => true,
						'strategy'  => 'defer',
					]
				);
			}
		}
	}

	/**
	 * Register plugin's client JS script.
	 *
	 * @return void
	 */
	public function register_client_script() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'kekspay-client-style', self::url( 'assets/dist/css/kekspay.css' ), [], self::VERSION );

		// Add redirect js only on order-pay endpoint but not on wp-admin generated customer payment page.
		if ( ! is_wc_endpoint_url( 'order-pay' ) || filter_input( INPUT_GET, 'pay_for_order', FILTER_VALIDATE_BOOLEAN ) ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || self::ID !== $order->get_payment_method() ) {
			return;
		}

		wp_enqueue_script(
			'kekspay-client-script',
			self::url( 'assets/dist/js/kekspay.js' ),
			[],
			self::VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_localize_script(
			'kekspay-client-script',
			'kekspayClientScript',
			[
				'endpoint'    => IPN::get_status_endpoint(),
				'fallback'    => admin_url( 'admin-ajax.php' ),
				'ipn_refresh' => apply_filters( 'kekspay_ipn_refresh_rate', 5000 ),
				'order_id'    => $order_id,
				'order_key'   => $order->get_order_key(),
			]
		);
	}

	/**
	 * Adds the link to the settings page on the plugins WP page.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array
	 */
	public function add_settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Postavke', 'keks-pay-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Cloning is forbidden.
	 */
	private function __clone() {}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @throws \LogicException Always.
	 */
	public function __wakeup() {
		throw new \LogicException( 'Unserializing instances is forbidden!' );
	}
}
