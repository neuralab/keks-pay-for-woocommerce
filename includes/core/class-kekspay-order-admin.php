<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Kekspay_Order_Admin' ) ) {
	/**
	 * Kekspay_Order_Admin class
	 *
	 * @since 0.1
	 */
	class Kekspay_Order_Admin {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			add_action( 'admin_notices', [ $this, 'display_test_order_notice' ], 100 );
		}

		/**
		 * Display WordPress warning notice if current order (admin view) is processed in sandbox/test mode.
		 *
		 * @return void
		 */
		public function display_test_order_notice() {
			if ( ! is_admin() ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return;
			}

			$order_id = 0;
			if ( 'post' === $screen->base && 'shop_order' === $screen->id ) {
				$order_id = get_the_ID();
			} elseif ( 'woocommerce_page_wc-orders' === $screen->id && isset( $_GET['id'] ) ) {
				$order_id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! is_a( $order, 'WC_Order' ) ) {
					return;
				}

				if ( Kekspay_Data::order_test_mode( $order ) ) {
					$class   = 'notice notice-warning';
					$message = __( 'Narudžba kreirana koristeći KEKS Pay u testnom načinu rada.', 'kekspay' );

					printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
				}

				if ( 'HRK' === $order->get_currency() ) {
					$class   = 'notice notice-warning';
					$message = __( 'KEKS Pay - Narudžba naplaćena u valuti HRK koja više nije podržana od strane KEKS Pay sustava. Ako želite napraviti povrat novca kroz KEKS Pay sustav, iznos za povrat prije povrata preračunati će se u EUR prema tečaju 7.5345 te biti vraćen u valuti EUR.', 'kekspay' );

					printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
				}
			}

		}

	}
}

new Kekspay_Order_Admin();
