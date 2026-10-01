<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name:       KEKS Pay for WooCommerce
 * Plugin URI:        https://www.kekspay.hr/
 * Description:       Incredibly fast and user friendly payment method created by Erste Bank Croatia.
 * Version:           2.1.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Erste bank Croatia
 * Author URI:        https://www.erstebank.hr/hr/gradjanstvo
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       kekspay
 * Domain Path:       /languages
 *
 * WC requires at least: 8.2
 * WC tested up to: 11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'kekspay_wc_active' ) ) {
	/**
	 * Return true if the WooCommerce plugin is active or false otherwise.
	 *
	 * @since 0.1
	 * @return boolean
	 */
	function kekspay_wc_active() {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}
}

if ( ! function_exists( 'kekspay_admin_notice_missing_woocommerce' ) ) {
	/**
	 * Echo admin notice HTML for missing WooCommerce plugin.
	 *
	 * @since 0.1
	 */
	function kekspay_admin_notice_missing_woocommerce() {
		/* translators: 1. URL link. */
		echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'KEKS Pay zahtijeva WooCommerce dodatak instaliran i aktivan. Možete ga skinuti ovdje %s.', 'kekspay' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
	}
}
if ( ! kekspay_wc_active() ) {
	add_action( 'admin_notices', 'kekspay_admin_notice_missing_woocommerce' );
	return;
}

/**
 * Declare Kekspay plugin compatibility for certain WooCommerce features:
 *        - HPOS
 *        - Checkout Blocks
 *
 * @return  void
 */
function kekspay_wc_features_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'kekspay_wc_features_compatibility' );

if ( ! class_exists( 'WC_Kekspay' ) ) {
	/**
	 * The main plugin class.
	 *
	 * @since 0.1
	 */
	class WC_Kekspay {
		/**
		 * Instance of the current class, null before first usage.
		 *
		 * @var WC_Kekspay
		 */
		protected static $instance = null;

		/**
		 * Class constructor, initialize constants and settings.
		 *
		 * @since 0.1
		 */
		protected function __construct() {
			self::register_constants();
			$this->load_textdomain();
			$this->check_requirements();

			// Require all necessary files.
			require_once 'includes/utilities/class-kekspay-data.php';
			require_once 'includes/utilities/class-kekspay-logger.php';

			require_once 'includes/core/class-kekspay-connector.php';
			require_once 'includes/core/class-kekspay-sell.php';
			require_once 'includes/core/class-kekspay-ipn.php';
			require_once 'includes/core/class-kekspay-order-admin.php';
			require_once 'includes/core/class-kekspay-payment-gateway.php';

			// Add hooks.
			add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
			add_filter( 'plugin_action_links', [ $this, 'add_settings_link' ], 10, 2 );
			add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_script' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'register_client_script' ] );
			add_action( 'admin_init', [ $this, 'check_settings' ], 20 );
			add_action( 'admin_init', [ $this, 'check_for_other_kekspay_gateways' ], 1 );
			add_action( 'activated_plugin', [ $this, 'set_kekspay_plugins_check_required' ] );
			add_action( 'woocommerce_admin_field_payment_gateways', [ $this, 'set_kekspay_plugins_check_required' ] );
			add_action( 'woocommerce_blocks_loaded', [ $this, 'register_checkout_block_gateway' ] );
		}

		/**
		 * Register plugin's constants.
		 */
		public static function register_constants() {
			if ( ! defined( 'KEKSPAY_PLUGIN_ID' ) ) {
				define( 'KEKSPAY_PLUGIN_ID', 'erste-kekspay-woocommerce' );
			}
			if ( ! defined( 'KEKSPAY_PLUGIN_VERSION' ) ) {
				define( 'KEKSPAY_PLUGIN_VERSION', '2.1.1' );
			}
			if ( ! defined( 'KEKSPAY_DIR_PATH' ) ) {
				define( 'KEKSPAY_DIR_PATH', plugin_dir_path( __FILE__ ) );
			}
			if ( ! defined( 'KEKSPAY_DIR_URL' ) ) {
				define( 'KEKSPAY_DIR_URL', plugin_dir_url( __FILE__ ) );
			}
			if ( ! defined( 'KEKSPAY_ADMIN_SETTINGS_URL' ) ) {
				define( 'KEKSPAY_ADMIN_SETTINGS_URL', get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=' . KEKSPAY_PLUGIN_ID ) );
			}
			if ( ! defined( 'KEKSPAY_REQUIRED_PHP_VERSION' ) ) {
				define( 'KEKSPAY_REQUIRED_PHP_VERSION', '7.4' );
			}
			if ( ! defined( 'KEKSPAY_REQUIRED_WC_VERSION' ) ) {
				define( 'KEKSPAY_REQUIRED_WC_VERSION', '8.2' );
			}
		}

		/**
		 * Load plugin's textdomain.
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'kekspay', false, basename( dirname( __FILE__ ) ) . '/languages' );
		}

		/**
		 * Check versions of requirements.
		 */
		public function check_requirements() {
			$requirements = [
				'php' => [
					'current_version' => phpversion(),
					'requred_version' => KEKSPAY_REQUIRED_PHP_VERSION,
					'name'            => 'PHP',
				],
				'wc'  => [
					'current_version' => WC_VERSION,
					'requred_version' => KEKSPAY_REQUIRED_WC_VERSION,
					'name'            => 'WooCommerce',
				],
			];

			$error_notices = [];

			foreach ( $requirements as $requirement ) {
				if ( version_compare( $requirement['current_version'], $requirement['requred_version'], '<' ) ) {

					$error_notices[] = sprintf(
						/* translators: minimum version dependancies */
						__( 'Minimalna verzija %1$s je %2$s. Verzija koju trenutno koristite je %3$s. Molimo instalirajte minimalnu verziju %1$s ako želite koristiti KEKS Pay.', 'kekspay' ),
						$requirement['name'],
						$requirement['requred_version'],
						$requirement['current_version']
					);
				}
			}

			if ( $error_notices ) {
				add_action( 'admin_init', [ $this, 'deactivate_self' ] );

				foreach ( $error_notices as $error_notice ) {
					self::admin_notice( $error_notice );
				}
			}
		}

		/**
		 * Add KEKS Pay payment method.
		 */
		public function add_gateway( $methods ) {
			$methods[] = 'Kekspay_Payment_Gateway';
			return $methods;
		}

		/**
		 * Check gateway settings and dispatch notice.
		 */
		public function check_settings() {
			// If payment gateway is not enabled bail.
			if ( ! Kekspay_Data::enabled() ) {
				return;
			}

			// Check if gateway is currently in test mode.
			if ( Kekspay_Data::test_mode() ) {
				self::admin_notice( __( 'KEKS Pay je trenutno u testom načinu rada, ne zaboravite ga ugasiti po završetku testiranja.', 'kekspay' ), 'warning' );
			}

			// Check if all setting keys required for gateway to work are set.
			if ( ! Kekspay_Data::required_keys_set() ) {
				self::admin_notice( __( 'KEKS Pay je trenutno onemogućen, molimo provjerite da su TID, CID i tajni ključ pravilno postavljeni.', 'kekspay' ), 'warning' );
			}

			// Check if correct currency is set in webshop.
			if ( ! Kekspay_Data::currency_supported() ) {
				self::admin_notice( __( 'KEKS Pay je trenutno onemogućen, valuta web trgovine nije podržana. Podržane valute: "EUR".', 'kekspay' ), 'warning' );
			}
		}

		/**
		 * Check if there are other KEKS Pay gateways.
		 */
		public static function check_for_other_kekspay_gateways() {
			if ( ! get_option( 'kekspay_plugins_check_required' ) ) {
				return;
			}

			delete_option( 'kekspay_plugins_check_required' );

			// Check if there already is payment method with id "nrlb-kekspay-woocommerce".
			$payment_gateways = WC_Payment_Gateways::instance()->payment_gateways();

			if ( isset( $payment_gateways[ KEKSPAY_PLUGIN_ID ] ) && ! $payment_gateways[ KEKSPAY_PLUGIN_ID ] instanceof Kekspay_Payment_Gateway ) {
				self::admin_notice( __( 'Možete imati samo jedan KEKS Pay način plaćanja aktivan u isto vrijeme. Dodatak "KEKS Pay for WooCommerce" je deaktiviran.', 'kekspay' ) );

				self::deactivate_self();
			}
		}

		/**
		 * Set check required.
		 */
		public static function set_kekspay_plugins_check_required() {
			update_option( 'kekspay_plugins_check_required', 'yes' );
		}

		/**
		 * Register Kekspay method for block checkout.
		 */
		public function register_checkout_block_gateway() {
			if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
				return;
			}

			require_once KEKSPAY_DIR_PATH . 'includes/core/class-kekspay-block-checkout.php';

			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new Kekspay_Block_Checkout() );
				}
			);
		}

		/**
		 * Deactivate plugin.
		 */
		public static function deactivate_self() {
			remove_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ self::get_instance(), 'add_settings_link' ] );
			remove_action( 'admin_init', [ self::get_instance(), 'check_settings' ], 20 );

			deactivate_plugins( plugin_basename( __FILE__ ) );
			unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Add admin notice.
		 *
		 * @param  string $notice Notice content.
		 * @param  string $type   Notice type.
		 */
		public static function admin_notice( $notice, $type = 'error' ) {
			add_action(
				'admin_notices',
				function() use ( $notice, $type ) {
					printf( '<div class="notice notice-%2$s"><p>%1$s</p></div>', wp_kses_post( $notice ), esc_html( $type ) );
				}
			);
		}

		/**
		 * Register plugin's admin JS script.
		 */
		public function register_admin_script() {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';

			if ( strpos( $screen_id, 'wc-settings' ) !== false ) {
				$section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

				if ( $section && KEKSPAY_PLUGIN_ID === $section ) {
					wp_enqueue_script( 'kekspay-admin-script', KEKSPAY_DIR_URL . '/assets/dist/js/kekspay-admin.js', [ 'jquery' ], KEKSPAY_PLUGIN_VERSION, true );
				}
			}
		}

		/**
		 * Register plugin's client JS script.
		 */
		public function register_client_script() {
			if ( is_checkout() ) {
				wp_enqueue_style( 'kekspay-client-style', KEKSPAY_DIR_URL . '/assets/dist/css/kekspay.css', [], KEKSPAY_PLUGIN_VERSION );

				// Add redirect js only on order-pay endpoint but not on wp-admin generated customer payment page.
				if ( is_wc_endpoint_url( 'order-pay' ) && ! filter_input( INPUT_GET, 'pay_for_order', FILTER_VALIDATE_BOOLEAN ) ) {
					$order_id = get_query_var( 'order-pay' );
					$order    = new WC_Order( $order_id );

					if ( 'erste-kekspay-woocommerce' === $order->get_payment_method() ) {
						wp_enqueue_script( 'kekspay-client-script', KEKSPAY_DIR_URL . '/assets/dist/js/kekspay.js', [ 'jquery' ], KEKSPAY_PLUGIN_VERSION, true );

						$localize_data = [
							'ajaxurl'     => admin_url( 'admin-ajax.php' ),
							'nonce'       => wp_create_nonce( 'kekspay_advice_status' ),
							'ipn_refresh' => apply_filters( 'kekspay_ipn_refresh_rate', 5000 ),
							'order_id'    => $order_id,
							'order_key'   => $order->get_order_key(),
						];

						wp_localize_script( 'kekspay-client-script', 'kekspayClientScript', $localize_data );
					}
				}
			}
		}

		/**
		 * Adds the link to the settings page on the plugins WP page.
		 *
		 * @param array   $links
		 * @return array
		 */
		public function add_settings_link( $links, $file ) {
			if ( $file === plugin_basename( __FILE__ ) ) {
				array_unshift( $links, '<a href="' . KEKSPAY_ADMIN_SETTINGS_URL . '">' . __( 'Postavke', 'kekspay' ) . '</a>' );
			}
			return $links;
		}

		/**
		 * Delete gateway settings. Return true if option is successfully deleted or
		 * false on failure or if option does not exist.
		 *
		 * @return bool
		 */
		public static function delete_settings() {
			return delete_option( 'woocommerce_' . KEKSPAY_PLUGIN_ID . '_settings' ) && delete_option( 'kekspay_plugins_check_required' );
		}

		/**
		 * Installation procedure.
		 *
		 * @static
		 */
		public static function install() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return false;
			}

			self::set_kekspay_plugins_check_required();
			self::register_constants();
		}

		/**
		 * Uninstallation procedure.
		 *
		 * @static
		 */
		public static function uninstall() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return false;
			}

			self::register_constants();
			self::delete_settings();

			wp_cache_flush();
		}

		/**
		 * Deactivation function.
		 *
		 * @static
		 */
		public static function deactivate() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return false;
			}

			self::register_constants();
		}

		/**
		 * Return class instance.
		 *
		 * @static
		 * @return WC_Kekspay
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 0.1
		 */
		public function __clone() {
			return wp_die( 'Cloning is forbidden!' );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 0.1
		 */
		public function __wakeup() {
			return wp_die( 'Unserializing instances is forbidden!' );
		}
	}
}

register_activation_hook( __FILE__, [ 'WC_Kekspay', 'install' ] );
register_uninstall_hook( __FILE__, [ 'WC_Kekspay', 'uninstall' ] );
register_deactivation_hook( __FILE__, [ 'WC_Kekspay', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'WC_Kekspay', 'get_instance' ], 0 );
