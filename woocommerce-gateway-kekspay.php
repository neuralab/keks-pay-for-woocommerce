<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name:       KEKS Pay for WooCommerce
 * Plugin URI:        https://www.kekspay.hr/
 * Description:       Incredibly fast and user friendly payment method created by Erste Bank Croatia.
 * Version:           2.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Erste bank Croatia
 * Author URI:        https://www.erstebank.hr/hr/gradjanstvo
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       keks-pay-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * WC requires at least: 8.2
 * WC tested up to: 11.1
 *
 * @package Kekspay
 */

use Kekspay\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function() {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'KEKS Pay nedostaju Composer paketi. Pokrenite "composer install" u direktoriju dodatka.', 'keks-pay-for-woocommerce' ) . '</strong></p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Deprecated, kept for custom code using them, use Kekspay\Plugin constants and methods instead.
defined( 'KEKSPAY_PLUGIN_ID' ) || define( 'KEKSPAY_PLUGIN_ID', Plugin::ID );
defined( 'KEKSPAY_PLUGIN_VERSION' ) || define( 'KEKSPAY_PLUGIN_VERSION', Plugin::VERSION );
defined( 'KEKSPAY_DIR_PATH' ) || define( 'KEKSPAY_DIR_PATH', Plugin::path() );
defined( 'KEKSPAY_DIR_URL' ) || define( 'KEKSPAY_DIR_URL', Plugin::url() );
defined( 'KEKSPAY_ADMIN_SETTINGS_URL' ) || define( 'KEKSPAY_ADMIN_SETTINGS_URL', Plugin::settings_url() );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );

add_action( 'before_woocommerce_init', [ Plugin::class, 'declare_compatibility' ] );
add_action( 'plugins_loaded', [ Plugin::class, 'init' ], 0 );
