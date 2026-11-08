<?php
/**
 * Plugin Name:       TrezorPay for WooCommerce
 * Plugin URI:        https://trezorpay.com/
 * Description:       Accept crypto payments (USDT, USDC, BTC, ETH, SOL and more) sent straight to your own wallets. Non-custodial, no account, no API key.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            TrezorPay
 * Author URI:        https://trezorpay.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trezorpay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

define( 'TREZORPAY_VERSION', '1.0.0' );
define( 'TREZORPAY_FILE', __FILE__ );
define( 'TREZORPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'TREZORPAY_URL', plugin_dir_url( __FILE__ ) );

require_once TREZORPAY_DIR . 'includes/class-trezorpay-decimal.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-logger.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-api.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-coins.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-order.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-rest.php';
require_once TREZORPAY_DIR . 'includes/class-trezorpay-payment-page.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TREZORPAY_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TREZORPAY_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', 'trezorpay_missing_woocommerce_notice' );
			return;
		}

		require_once TREZORPAY_DIR . 'includes/class-trezorpay-gateway.php';

		add_filter( 'woocommerce_payment_gateways', 'trezorpay_register_gateway' );
		TrezorPay_REST::init();
		TrezorPay_Payment_Page::init();
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once TREZORPAY_DIR . 'includes/class-trezorpay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new TrezorPay_Blocks() );
			}
		);
	}
);

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'trezorpay_action_links' );

/**
 * Adds the gateway to WooCommerce.
 *
 * @param array $gateways Registered gateway classes.
 * @return array
 */
function trezorpay_register_gateway( $gateways ) {
	$gateways[] = 'TrezorPay_Gateway';
	return $gateways;
}

/**
 * Returns the gateway instance WooCommerce built, or null when payments are not loaded.
 *
 * @return TrezorPay_Gateway|null
 */
function trezorpay_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways['trezorpay'] ) && $gateways['trezorpay'] instanceof TrezorPay_Gateway ? $gateways['trezorpay'] : null;
}

/**
 * Adds a Settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function trezorpay_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=trezorpay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'trezorpay-for-woocommerce' ) . '</a>' );
	return $links;
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function trezorpay_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'TrezorPay for WooCommerce requires WooCommerce to be installed and active.', 'trezorpay-for-woocommerce' ) . '</p></div>';
}
