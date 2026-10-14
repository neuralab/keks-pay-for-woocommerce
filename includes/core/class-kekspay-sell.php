<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use chillerlan\QRCode\{ QRCode, QROptions };

if ( ! class_exists( 'Kekspay_Sell' ) ) {
	/**
	 * Kekspay_Sell class
	 *
	 * @since 0.1
	 */
	class Kekspay_Sell {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			require_once KEKSPAY_DIR_PATH . 'vendor/autoload.php';
		}

		/**
		 * Create url for mobile app.
		 *
		 * @param  object $order Order for which to create url.
		 *
		 * @return string        Url for mobile app.
		 */
		public function get_sell_url( $order ) {
			$sell = Kekspay_Data::get_sell_data( $order, true );

			if ( ! $sell ) {
				return false;
			}

			return add_query_arg( $sell, Kekspay_Data::get_kekspay_pay_base() );
		}

		/**
		 * Create QR code for mobile app.
		 *
		 * @param  object $order Order for which to create QR code.
		 *
		 * @return string        base64 encoded png file.
		 */
		public function get_sell_qr( $order ) {
			$data = Kekspay_Data::get_sell_data( $order );

			try {
				$options = new QROptions(
					[
						'version'          => 6,
						'quietzoneSize'    => 4,
						'eccLevel'         => QRCode::ECC_L,
						'imageTransparent' => false,
					]
				);

				$qrcode = new QRCode( $options );

				return $qrcode->render( wp_json_encode( $data ) );
			} catch ( \Exception $e ) {
				Kekspay_Logger::log( 'Failed to create QR Code. Exception message: ' . $e->getMessage(), 'error' );
			}

			return false;
		}

		/**
		 * Format QR Code with html for display.
		 *
		 * @param  object $order Order for which to fetch QR code.
		 *
		 * @return string        Path to or base64 encoded QR code for mobile app wrapped in img tags.
		 */
		public function display_sell_qr( $order ) {
			$qrcode = $this->get_sell_qr( $order );

			if ( ! $qrcode ) {
				return esc_html__( 'Dogodila se greška prilikom kreiranja QR kȏda za ovu narudžbu. Molimo rekreirajte narudžbu ili kontaktirajte vlasnika web stranice.', 'keks-pay-for-woocommerce' );
			}

			return apply_filters( 'kekspay_sell_qr_code', '<img class="kekspay-qr-code" src="' . esc_attr( $qrcode ) . '" width="245" height="245" alt="' . esc_attr__( 'QR kȏd', 'keks-pay-for-woocommerce' ) . '">', $qrcode );
		}

		/**
		 * Format Keks pay url with html for display
		 *
		 * @param  object $order Order for which to get the pay url.
		 *
		 * @return string        Link for payment.
		 */
		public function display_sell_url( $order ) {
			$sell_url = $this->get_sell_url( $order );

			if ( ! $sell_url ) {
				return esc_html__( 'Dogodila se greška prilikom kreiranja poveznice za plaćanje putem KEKS Pay mobilne aplikacije za ovu narudžbu. Molimo rekreirajte narudžbu ili kontaktirajte vlasnika web stranice.', 'keks-pay-for-woocommerce' );
			}

			$attrs = apply_filters(
				'kekspay_sell_link_attributes',
				[
					'id'     => 'kekspay-pay-url',
					'class'  => 'button kekspay-sell-button',
					'target' => '_blank',
					'label'  => __( 'Otvori KEKS Pay', 'keks-pay-for-woocommerce' ),
				]
			);

			return apply_filters( 'kekspay_sell_link', '<a id="' . esc_attr( $attrs['id'] ) . '" href="' . esc_url( $sell_url ) . '" class="' . esc_attr( $attrs['class'] ) . '" target="' . esc_attr( $attrs['target'] ) . '">' . esc_html( $attrs['label'] ) . '</a>' );
		}

	}
}
