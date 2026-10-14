<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use chillerlan\QRCode\{ QRCode, QROptions };
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\{ QRGdImagePNG, QROutputInterface };

if ( ! class_exists( 'Kekspay_Sell' ) ) {
	/**
	 * Kekspay_Sell class
	 *
	 * @since 0.1
	 */
	class Kekspay_Sell {

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
				$qrcode = new QRCode( new QROptions( self::get_qr_options() ) );

				return $qrcode->render( wp_json_encode( $data ) );
			} catch ( \Throwable $e ) {
				Kekspay_Logger::log( 'Failed to create QR Code using chillerlan/php-qrcode v' . self::get_qr_library_version() . '. Exception message: ' . $e->getMessage(), 'error' );
			}

			return false;
		}

		/**
		 * Detect major version of the loaded chillerlan/php-qrcode library.
		 *
		 * Another plugin may bundle the same library and its copy can be loaded instead of ours, so the version is
		 * detected by the API that is available instead of relying on the bundled version.
		 *
		 * @return int Major version, 4, 5 or 6.
		 */
		public static function get_qr_library_version() {
			if ( ! class_exists( QRGdImagePNG::class ) ) {
				return 4;
			}

			return defined( QROutputInterface::class . '::GDIMAGE_PNG' ) ? 5 : 6;
		}

		/**
		 * Return QR code options for the loaded chillerlan/php-qrcode library version.
		 *
		 * Renders base64 encoded 245x245px PNG data URI (version 6 QR code, scale 5, quiet zone 4) in all versions.
		 *
		 * @return array
		 */
		public static function get_qr_options() {
			$options = [
				'version'          => 6,
				'quietzoneSize'    => 4,
				'scale'            => 5,
				'imageTransparent' => false,
			];

			switch ( self::get_qr_library_version() ) {
				case 4:
					$options['eccLevel']    = QRCode::ECC_L;
					$options['outputType']  = QRCode::OUTPUT_IMAGE_PNG;
					$options['imageBase64'] = true;
					break;
				case 5:
					$options['eccLevel']     = EccLevel::L;
					$options['outputType']   = QROutputInterface::GDIMAGE_PNG;
					$options['outputBase64'] = true;
					break;
				default:
					$options['eccLevel']        = EccLevel::L;
					$options['outputInterface'] = QRGdImagePNG::class;
					$options['outputBase64']    = true;
			}

			return $options;
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

			return apply_filters( 'kekspay_sell_qr_code', '<div class="kekspay-qr-code" role="img" aria-label="' . esc_attr__( 'QR kȏd', 'keks-pay-for-woocommerce' ) . '" style="background-image: url(' . esc_attr( $qrcode ) . ');"></div>', $qrcode );
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
