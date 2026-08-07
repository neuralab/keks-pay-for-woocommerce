=== KEKS Pay for WooCommerce ===
Contributors: erstebank
Tags: kekspay, woocommerce, gateway, payment
Requires at least: 6.3
Tested up to: 7
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPL v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

KEKS Pay for WooCommerce.

== Description ==

KEKS Pay for WooCommerce offers you a simple and seamless way of taking KEKS Pay payments. Your customers will have the best time saving user experience in the checkout process - especially while buying on smartphones. It will increase your conversion-rate and the likeliness to return to your shop. On the other hand, via KEKS Pay you will be able to accept VISA, Mastercard, Maestro or Diners Club cards and payments directly from your customer's bank account.

Currently KEKS Pay for WooCommerce is available for merchants only in CROATIA.

== Why choose KEKS Pay? ==

KEKS Pay has no setup fees, no monthly fees, no hidden costs - you only get charged when you take payments through KEKS Pay. Earnings are transferred to your bank account on a 1-day rolling basis.

Cancel whenever you want without additional costs.

== How to refund a KEKS Pay payment? ==

To do a refund, select the order and click the Refund button afterward. You will be able to type in the amount you would refund. The order status will change to “Refunded” once the order has been successfully refunded.

On a side note, this plugin is provided “as-is” and we don't currently provide support around installing and optimizing it for your needs.

== Installation ==

= MINIMUM REQUIREMENTS =

* WooCommerce 8.2 or greater.
* WordPress 6.3 or greater.
* PHP version 7.4 or greater.
* SSL must be installed on your site and active on your Checkout pages.

= INSTALL =

1. Contact ERSTE KEKS Pay team and set up your merchant account.
2. Visit Plugins > Add New.
3. Search for "KEKS Pay for WooCommerce".
4. Install and activate KEKS Pay for WooCommerce plugin.
5. Visit plugin settings and fill in merchant info you received from ERSTE KEKS Pay team.

For more installation options check the [official WordPress documentation](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation) about installing plugins.

== Changelog ==

= 2.1.1 =
* Fix multiple localization calls for block checkout.
* Bump tested up to.

= 2.1.0 =
* Add WooCommerce checkout blocks support.

= 2.0.0 =
* Drop PHP 7.2 support.

= 1.1.0 =
* Add assets build.
* Add AES cipher support.

= 1.0.17 =
* Changes to plugin support info.

= 1.0.16 =
* Update base url of kekspay API.

= 1.0.15 =
* Add error log for refund request timeout.
* Increase timeout for refund request.

= 1.0.14 =
* Changes to plugin support info.
* Fix typo in plugin description.

= 1.0.13 =
* Declare compatibility with HPOS.

= 1.0.12 =
* Raise QR Code Level 5 => 6.

= 1.0.11 =
* Change supported currency.

= 1.0.10 =
* Fixed displaying failed checks notice.
* Added currency change info.

= 1.0.9 =
* Fixed refund timeout issue.

= 1.0.8 =
* Remove store-msg field from the setting and from the QR code
* Lower QR Code Level 6 => 5
* Fixed network activation check.
* Changes to plugin support info.

= 1.0.7 =
* Changes to plugin support info.

= 1.0.6 =
* Token verification updates.
* Updated settings page copy.
* Added order id information to bill_id field.

= 1.0.5 =
* Added setting for payment complete order status.
* Fixed infinite redirect on checkout.
* Fixed loading settings in check functions.

= 1.0.4 =
* Fixed lazy loading breaking QR Code.
* Fixed recurring redirects.
* Fixed QR Code description style.
* Updated translations.

= 1.0.3 =
* Fixed QR Code use of GD library.
* Changed pay icon.

= 1.0.2 =
* Fixed QR Code transparency.

= 1.0.1 =
* Changes to plugin description and info.

= 1.0 =
* Initial stable release.

= 0.9 =
* Beta release.
