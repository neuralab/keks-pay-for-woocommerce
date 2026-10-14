<?php
/**
 * Uninstall procedure, delete plugin options.
 *
 * @package Kekspay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_erste-kekspay-woocommerce_settings' );
delete_option( 'kekspay_plugins_check_required' );
