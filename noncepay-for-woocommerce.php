<?php
/**
 * Plugin Name:       NoncePay for WooCommerce
 * Plugin URI:        https://github.com/Trezor-Pay/trezorpay-woocommerce
 * Description:       Accept crypto payments (USDT, USDC, BTC, ETH, SOL and more) sent straight to your own wallets. Non-custodial, no account, no API key.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            NoncePay
 * Author URI:        https://noncepay.app/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       noncepay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

define( 'NONCEPAY_VERSION', '1.1.0' );
define( 'NONCEPAY_FILE', __FILE__ );
define( 'NONCEPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'NONCEPAY_URL', plugin_dir_url( __FILE__ ) );

require_once NONCEPAY_DIR . 'includes/class-noncepay-decimal.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-logger.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-api.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-coins.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-order.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-rest.php';
require_once NONCEPAY_DIR . 'includes/class-noncepay-payment-page.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NONCEPAY_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NONCEPAY_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', 'noncepay_missing_woocommerce_notice' );
			return;
		}

		require_once NONCEPAY_DIR . 'includes/class-noncepay-gateway.php';

		add_filter( 'woocommerce_payment_gateways', 'noncepay_register_gateway' );
		NoncePay_REST::init();
		NoncePay_Payment_Page::init();
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once NONCEPAY_DIR . 'includes/class-noncepay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new NoncePay_Blocks() );
			}
		);
	}
);

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'noncepay_action_links' );

/**
 * Adds the gateway to WooCommerce.
 *
 * @param array $gateways Registered gateway classes.
 * @return array
 */
function noncepay_register_gateway( $gateways ) {
	$gateways[] = 'NoncePay_Gateway';
	return $gateways;
}

/**
 * Returns the gateway instance WooCommerce built, or null when payments are not loaded.
 *
 * @return NoncePay_Gateway|null
 */
function noncepay_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways['noncepay'] ) && $gateways['noncepay'] instanceof NoncePay_Gateway ? $gateways['noncepay'] : null;
}

/**
 * Adds a Settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function noncepay_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=noncepay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'noncepay-for-woocommerce' ) . '</a>' );
	return $links;
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function noncepay_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'NoncePay for WooCommerce requires WooCommerce to be installed and active.', 'noncepay-for-woocommerce' ) . '</p></div>';
}
