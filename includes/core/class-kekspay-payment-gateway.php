<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

if ( ! class_exists( 'Kekspay_Payment_Gateway' ) ) {
	/**
	 * Kekspay_Payment_Gateway class
	 */
	class Kekspay_Payment_Gateway extends WC_Payment_Gateway {
		/**
		 * Instance of the current class, null before first usage.
		 *
		 * @var Kekspay_Payment_Gateway
		 */
		protected static $instance = null;

		/**
		 * App data handler.
		 *
		 * @var Kekspay_Sell
		 */
		private $sell;

		/**
		 * Class constructor with basic gateway's setup.
		 */
		public function __construct() {
			$this->id                 = KEKSPAY_PLUGIN_ID;
			$this->method_title       = __( 'KEKS Pay', 'keks-pay-for-woocommerce' );
			$this->method_description = __( 'Najbrže i bez naknada putem KEKS Pay aplikacije!', 'keks-pay-for-woocommerce' );
			$this->has_fields         = true;

			$this->init_form_fields();
			$this->init_settings();

			$this->supports = [ 'products', 'refunds' ];

			$this->sell = new Kekspay_Sell();

			$this->title = esc_attr( Kekspay_Data::get_settings( 'title' ) );

			$this->add_hooks();

			self::$instance = $this;
		}

		/**
		 * Register different hooks.
		 */
		private function add_hooks() {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

			if ( ! self::$instance ) {
				add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'do_receipt_page' ] );
			}
		}

		/**
		 * Check if we need to make gateways available.
		 *
		 * @override
		 */
		public function is_available() {
			if ( ! Kekspay_Data::required_keys_set() || ! Kekspay_Data::currency_supported() ) {
				return false;
			}

			return parent::is_available();
		}

		/**
		 * Add kekspay payment method icon.
		 *
		 * @override
		 */
		public function get_icon() {
			return apply_filters( 'woocommerce_gateway_icon', Kekspay_Data::get_svg( 'keks-logo' ), $this->id );
		}

		/**
		 * Echoes gateway's options (Checkout tab under WooCommerce's settings).
		 *
		 * @override
		 */
		public function admin_options() {
			?>
			<h2><?php esc_html_e( 'KEKS Pay', 'keks-pay-for-woocommerce' ); ?></h2>

			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table>
			<?php
		}

		/**
		 * Define gateway's fields visible at WooCommerce's Settings page and
		 * Checkout tab.
		 *
		 * @override
		 */
		public function init_form_fields() {
			$this->form_fields = include KEKSPAY_DIR_PATH . 'includes/settings/kekspay-settings.php';
		}

		/**
		 * Display description of the gateway on the checkout page.
		 *
		 * @override
		 */
		public function payment_fields() {
			echo wp_kses_post( '<p>' . __( 'Najbrže i bez naknada putem KEKS Pay aplikacije!', 'keks-pay-for-woocommerce' ) . '</p>' );

			if ( Kekspay_Data::test_mode() ) {
				echo wp_kses_post(
					apply_filters(
						'kekspay_payment_description_test_mode_notice',
						'<p><b>' . __( 'KEKS Pay je trenutno u testom načinu rada, ne zaboravite ga ugasiti po završetku testiranja.', 'keks-pay-for-woocommerce' ) . '</b></p>'
					)
				);
			}
		}

		/**
		 * Trigger actions for 'receipt' page.
		 *
		 * @param int $order_id
		 */
		public function do_receipt_page( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				Kekspay_Logger::log( 'Failed to find order ' . $order_id . ' while trying to show receipt page.', 'warning' );
				return false;
			}

			if ( ! $order->get_meta( 'kekspay_status' ) ) {
				Kekspay_Logger::log( 'Order ' . $order_id . ' created for payment via KEKS Pay, status set to pending.', 'info' );
				$order->add_meta_data( 'kekspay_status', 'pending', true );
				$order->save();
			}

			// Keep order's test mode flag in sync with the mode used for the payment, refunds and IPN rely on it.
			if ( Kekspay_Data::test_mode() && ! Kekspay_Data::order_test_mode( $order ) ) {
				$order->add_order_note( __( 'Narudžba napravljena u testnom načinu rada!', 'keks-pay-for-woocommerce' ) );
				$order->update_meta_data( 'kekspay_test_mode', 'yes' );
				$order->save();
			} elseif ( ! Kekspay_Data::test_mode() && Kekspay_Data::order_test_mode( $order ) ) {
				$order->delete_meta_data( 'kekspay_test_mode' );
				$order->save();
			}

			do_action( 'kekspay_receipt_before_payment_data', $order, Kekspay_Data::get_settings() );
			?>

			<?php echo Kekspay_Data::get_svg( 'keks-logo' ); //@codingStandardsIgnoreLine - safe output ?>

			<div class="kekspay">
				<div class="kekspay-url__wrap">
					<div class="kekspay-url">
						<?php echo wp_kses_post( $this->sell->display_sell_url( $order ) ); ?>
					</div>
				</div>
				<div class="kekspay-qr">
					<div class="kekspay-qr__instructions">
						<ol>
							<li><?php esc_html_e( 'Otvori KEKS Pay', 'keks-pay-for-woocommerce' ); ?></li>
							<?php /* translators: kekspay in-app icon */ ?>
							<li><?php printf( __( 'Pritisni %s ikonicu', 'keks-pay-for-woocommerce' ), Kekspay_Data::get_svg( 'icon-pay' ) ?: esc_html__( 'keks logo', 'keks-pay-for-woocommerce' ) ); ?></li>
							<li><?php esc_html_e( 'Odaberi "Skeniraj QR kȏd"', 'keks-pay-for-woocommerce' ); ?></li>
							<li><?php esc_html_e( 'Skeniraj QR kȏd', 'keks-pay-for-woocommerce' ); ?></li>
						</ol>
					</div>
					<?php echo $this->sell->display_sell_qr( $order ); //@codingStandardsIgnoreLine - safe output ?>
				</div>
			</div>
			<a class="kekspay-cancel" href="<?php echo esc_attr( $order->get_cancel_order_url_raw() ); ?>"><?php esc_html_e( 'Otkaži', 'keks-pay-for-woocommerce' ); ?></a>
			<?php

			do_action( 'kekspay_receipt_after_payment_data', $order, Kekspay_Data::get_settings() );
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @override
		 * @param string $order_id
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				Kekspay_Logger::log( 'Failed to find order ' . $order_id . ' while trying to process payment.', 'critical' );
				return;
			}

			WC()->cart->empty_cart();

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			];
		}

		/**
		 * Process refund via KEKS Pay.
		 *
		 * @override
		 * @param  int    $order_id
		 * @param  float  $amount   Defaults to null.
		 * @param  string $reason   Defaults to empty string.
		 *
		 * @return true|WP_Error     True on success, WP_Error with failure reason otherwise.
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Kekspay_Logger::log( 'Failed to find order ' . $order_id . ' while processing refund.', 'warning' );
				return new WP_Error( 'kekspay_refund_error', __( 'Narudžba nije pronađena.', 'keks-pay-for-woocommerce' ) );
			}

			return Kekspay_Connector::refund( $order, $amount );
		}

		/**
		 * Return class instance.
		 *
		 * @static
		 * @return Kekspay_Payment_Gateway
		 */
		public static function get_instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
	}
}
