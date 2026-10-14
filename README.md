
# KEKS Pay for WooCommerce.

KEKS Pay for WooCommerce offers you a simple and seamless way of taking KEKS Pay payments. Your customers will have the best time saving user experience in the checkout process - especially while buying on smartphones. It will increase your conversion-rate and the likeliness to return to your shop. On the other hand, via KEKS Pay you will be able to accept VISA, Mastercard, Maestro or Diners Club cards and payments directly from your customer's bank account.

Currently KEKS Pay for WooCommerce is available for merchants only in CROATIA.

## Why choose KEKS Pay?

KEKS Pay has no setup fees, no monthly fees, no hidden costs - you only get charged when you take payments through KEKS Pay. Earnings are transferred to your bank account on a 1-day rolling basis.

Cancel whenever you want without additional costs.

## How to refund a KEKS Pay payment?

To do a refund, select the order and click the Refund button afterward. You will be able to type in the amount you would refund. The order status will change to “Refunded” once the order has been successfully refunded.

On a side note, this plugin is provided “as-is” and we don't currently provide support around installing and optimizing it for your needs.

## Installation
#### MINIMUM REQUIREMENTS

* WooCommerce 8.2 or greater.
* WordPress 6.3 or greater.
* PHP version 7.4 or greater.
* SSL must be installed on your site and active on your Checkout pages.

#### INSTALL

1. Contact ERSTE KEKS Pay team and set up your merchant account.
2. Visit Plugins > Add New.
3. Search for "KEKS Pay for WooCommerce".
4. Install and activate KEKS Pay for WooCommerce plugin.
5. Visit plugin settings and fill in merchant info you received from ERSTE KEKS Pay team.

For more installation options check the [official WordPress documentation](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation) about installing plugins.

## Development

PHP dependencies are not committed. Run `composer install` to install them and generate the autoloader. Plugin classes live in `src/` under the `Kekspay` namespace and are autoloaded via PSR-4, so the file path must match the class name (`Kekspay\Gateway\Payment_Gateway` is `src/Gateway/Payment_Gateway.php`). Run `npm install && npx mix` to build assets.

`woocommerce-gateway-kekspay.php` only bootstraps the plugin, `Kekspay\Plugin` registers hooks and provides the gateway ID, version and paths (`Plugin::ID`, `Plugin::VERSION`, `Plugin::path()`, `Plugin::url()`).

Global class names and constants used before namespaces (`WC_Kekspay`, `Kekspay_Data`, `KEKSPAY_PLUGIN_ID`, ...) still work through deprecated aliases in `Kekspay\Legacy_Aliases` and the main plugin file, remove them in the next major version.

Other plugins may bundle `chillerlan/php-qrcode` and their copy can be loaded instead of ours, so `Kekspay\Gateway\Sell` detects the loaded version (v4, v5 or v6) and builds QR code options accordingly. When a new major version is released, add support for it there and widen the version constraint in `composer.json`.

The deploy workflow runs both before publishing a release to WordPress.org.


