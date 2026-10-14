<?php
/**
 * Legacy Aliases class.
 *
 * @package Kekspay
 */

namespace Kekspay;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps global class names used before namespaces were introduced working for custom code that references them.
 *
 * Aliases are created lazily, only when an old class name is used, and are deprecated, they will be removed in the
 * next major version.
 */
class Legacy_Aliases {
	/**
	 * Map of old global class names to new namespaced class names.
	 *
	 * @var array
	 */
	const ALIASES = [
		'WC_Kekspay'              => Plugin::class,
		'Kekspay_Payment_Gateway' => Gateway\Payment_Gateway::class,
		'Kekspay_Block_Checkout'  => Gateway\Block_Checkout::class,
		'Kekspay_IPN'             => Gateway\IPN::class,
		'Kekspay_Sell'            => Gateway\Sell::class,
		'Kekspay_Connector'       => Api\Connector::class,
		'Kekspay_Order_Admin'     => Admin\Order_Admin::class,
		'Kekspay_Data'            => Utilities\Data::class,
		'Kekspay_Logger'          => Utilities\Logger::class,
	];

	/**
	 * Register autoloader for old class names.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( [ self::class, 'autoload' ] );

		// "instanceof" doesn't trigger autoloading, alias the gateway class right away since it's the one usually checked.
		if ( ! class_exists( 'Kekspay_Payment_Gateway', false ) ) {
			class_alias( Gateway\Payment_Gateway::class, 'Kekspay_Payment_Gateway' );
		}
	}

	/**
	 * Alias old class name to the new namespaced class when the old one is used.
	 *
	 * @param string $class_name Requested class name.
	 *
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( ! isset( self::ALIASES[ $class_name ] ) ) {
			return;
		}

		if ( function_exists( '_deprecated_class' ) ) {
			_deprecated_class( $class_name, '2.3.0', self::ALIASES[ $class_name ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		class_alias( self::ALIASES[ $class_name ], $class_name );
	}
}
