<?php
/**
 * Payment Gateway class.
 *
 * @package Kekspay
 */

namespace Kekspay\Gateway;

use Kekspay\Api\Connector;
use Kekspay\Plugin;
use Kekspay\Utilities\Data;
use Kekspay\Utilities\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Payment_Gateway class
 */
class Payment_Gateway extends \WC_Payment_Gateway {
	/**
	 * App data handler.
	 *
	 * @var Sell
	 */
	private $sell;

	/**
	 * Class constructor with basic gateway's setup.
	 */
	public function __construct() {
		$this->id                 = Plugin::ID;
		$this->method_title       = __( 'KEKS Pay', 'keks-pay-for-woocommerce' );
		$this->method_description = __( 'Najbrže i bez naknada putem KEKS Pay aplikacije!', 'keks-pay-for-woocommerce' );
		$this->has_fields         = true;
		// Square logo shown in WooCommerce admin payments list, checkout shows the full logo using get_icon().
		$this->icon = Plugin::url( 'assets/dist/img/keks-logo-square.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$this->supports = [ 'products', 'refunds' ];

		$this->sell = new Sell();

		$this->title = $this->get_option( 'title' );

		$this->add_hooks();
	}

	/**
	 * Register different hooks.
	 */
	private function add_hooks() {
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'do_receipt_page' ] );
	}

	/**
	 * Check if we need to make gateways available.
	 *
	 * @override
	 */
	public function is_available() {
		if ( ! Data::required_keys_set() || ! Data::currency_supported() ) {
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
		return apply_filters( 'woocommerce_gateway_icon', Data::get_svg( 'keks-logo' ), $this->id );
	}

	/**
	 * Echoes gateway's options (Checkout tab under WooCommerce's settings).
	 *
	 * @override
	 */
	public function admin_options() {
		$this->display_errors();
		?>
		<h2><?php esc_html_e( 'KEKS Pay', 'keks-pay-for-woocommerce' ); ?></h2>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Validate password fields, secret keys must be usable for hashing requests to KEKS Pay.
	 *
	 * Invalid secret key is not saved, previous value is kept and error is shown.
	 *
	 * @override
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 *
	 * @throws \Exception Secret key length is not valid.
	 *
	 * @return string
	 */
	public function validate_password_field( $key, $value ) {
		$value = parent::validate_password_field( $key, $value );

		if ( '' === $value || ! in_array( $key, [ 'webshop-secret-key', 'test-webshop-secret-key' ], true ) ) {
			return $value;
		}

		try {
			Data::get_cipher( $value );
		} catch ( \Exception $e ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: secret key field title */
					esc_html__( '%s nije spremljen: tajni ključ mora imati 16, 24 ili 32 znaka, a ključ od samo heksadecimalnih znakova (0-9, a-f) mora imati 24 znaka.', 'keks-pay-for-woocommerce' ),
					esc_html( $this->form_fields[ $key ]['title'] )
				)
			);
		}

		return $value;
	}

	/**
	 * Define gateway's fields visible at WooCommerce's Settings page and
	 * Checkout tab.
	 *
	 * @override
	 */
	public function init_form_fields() {
		$this->form_fields = Settings::get_form_fields();
	}

	/**
	 * Display description of the gateway on the checkout page.
	 *
	 * @override
	 */
	public function payment_fields() {
		echo wp_kses_post( '<p>' . __( 'Najbrže i bez naknada putem KEKS Pay aplikacije!', 'keks-pay-for-woocommerce' ) . '</p>' );

		if ( Data::test_mode() ) {
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
			Logger::log( 'Failed to find order ' . $order_id . ' while trying to show receipt page.', 'warning' );
			return false;
		}

		if ( ! $order->get_meta( 'kekspay_status' ) ) {
			Logger::log( 'Order ' . $order_id . ' created for payment via KEKS Pay, status set to pending.', 'info' );
			$order->add_meta_data( 'kekspay_status', 'pending', true );
			$order->save();
		}

		// Keep order's test mode flag in sync with the mode used for the payment, refunds and IPN rely on it.
		if ( Data::test_mode() && ! Data::order_test_mode( $order ) ) {
			$order->add_order_note( __( 'Narudžba napravljena u testnom načinu rada!', 'keks-pay-for-woocommerce' ) );
			$order->update_meta_data( 'kekspay_test_mode', 'yes' );
			$order->save();
		} elseif ( ! Data::test_mode() && Data::order_test_mode( $order ) ) {
			$order->delete_meta_data( 'kekspay_test_mode' );
			$order->save();
		}

		do_action( 'kekspay_receipt_before_payment_data', $order, Data::get_settings() );
		?>

		<?php echo Data::get_svg( 'keks-logo' ); //@codingStandardsIgnoreLine - safe output ?>

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
						<li><?php printf( __( 'Pritisni %s ikonicu', 'keks-pay-for-woocommerce' ), Data::get_svg( 'icon-pay' ) ?: esc_html__( 'keks logo', 'keks-pay-for-woocommerce' ) ); ?></li>
						<li><?php esc_html_e( 'Odaberi "Skeniraj QR kȏd"', 'keks-pay-for-woocommerce' ); ?></li>
						<li><?php esc_html_e( 'Skeniraj QR kȏd', 'keks-pay-for-woocommerce' ); ?></li>
					</ol>
				</div>
				<?php echo $this->sell->display_sell_qr( $order ); //@codingStandardsIgnoreLine - safe output ?>
			</div>
		</div>
		<a class="kekspay-cancel" href="<?php echo esc_attr( $order->get_cancel_order_url_raw() ); ?>"><?php esc_html_e( 'Otkaži', 'keks-pay-for-woocommerce' ); ?></a>
		<?php

		do_action( 'kekspay_receipt_after_payment_data', $order, Data::get_settings() );
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
			Logger::log( 'Failed to find order ' . $order_id . ' while trying to process payment.', 'critical' );
			wc_add_notice( __( 'Narudžba nije pronađena.', 'keks-pay-for-woocommerce' ), 'error' );

			return [ 'result' => 'failure' ];
		}

		// Cart is not emptied here so it's kept if customer cancels or payment fails, WooCommerce empties it on order received page.
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
			Logger::log( 'Failed to find order ' . $order_id . ' while processing refund.', 'warning' );
			return new \WP_Error( 'kekspay_refund_error', __( 'Narudžba nije pronađena.', 'keks-pay-for-woocommerce' ) );
		}

		return Connector::refund( $order, $amount );
	}

	/**
	 * Return gateway instance created by WooCommerce.
	 *
	 * @return Payment_Gateway|null Null if WooCommerce has no KEKS Pay gateway or it's registered by another plugin.
	 */
	public static function get_instance() {
		$gateways = WC()->payment_gateways()->payment_gateways();

		return isset( $gateways[ Plugin::ID ] ) && $gateways[ Plugin::ID ] instanceof self ? $gateways[ Plugin::ID ] : null;
	}
}
