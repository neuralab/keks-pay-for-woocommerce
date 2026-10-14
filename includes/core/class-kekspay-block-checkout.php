<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Kekspay_Block_Checkout extends AbstractPaymentMethodType {
	private $gateway;

	protected $name = KEKSPAY_PLUGIN_ID;

	public function initialize() {
		$this->settings = Kekspay_Data::get_settings();
		$this->gateway  = Kekspay_Payment_Gateway::get_instance();
	}

	public function is_active() {
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		static $localized = false;

		wp_register_script(
			'kekspay-blocks-script',
			KEKSPAY_DIR_URL . 'assets/dist/js/kekspay-blocks.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			KEKSPAY_PLUGIN_VERSION,
			true
		);

		if ( ! $localized ) {
			wp_localize_script(
				'kekspay-blocks-script',
				'kekspayBlocksData',
				[
					'kekspayID' => KEKSPAY_PLUGIN_ID,
				]
			);

			$localized = true;
		}

		return [ 'kekspay-blocks-script' ];
	}

	public function get_payment_method_data() {
		return [
			'title'          => $this->gateway->title,
			'description'    => $this->gateway->method_description,
			'icon'           => KEKSPAY_DIR_URL . 'assets/dist/img/keks-logo.svg',
			'testModeNotice' => Kekspay_Data::test_mode() ? __( 'KEKS Pay je trenutno u testom načinu rada, ne zaboravite ga ugasiti po završetku testiranja.', 'kekspay' ) : '',
			'supports'       => array_values( array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ) ),
		];
	}
}
