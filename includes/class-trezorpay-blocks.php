<?php
/**
 * Cart and Checkout blocks integration.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers TrezorPay with the block checkout.
 */
final class TrezorPay_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name, matching the gateway id.
	 *
	 * @var string
	 */
	protected $name = 'trezorpay';

	/**
	 * Loads the settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_trezorpay_settings', array() );
	}

	/**
	 * Whether the method is offered.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = trezorpay_gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'trezorpay-blocks',
			TREZORPAY_URL . 'assets/js/checkout-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			TREZORPAY_VERSION,
			true
		);
		wp_set_script_translations( 'trezorpay-blocks', 'trezorpay-for-woocommerce' );
		return array( 'trezorpay-blocks' );
	}

	/**
	 * Data exposed to the script as getSetting( 'trezorpay_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = trezorpay_gateway();
		$coins   = array();
		if ( $gateway ) {
			foreach ( $gateway->checkout_coins() as $ticker => $label ) {
				$coins[] = array(
					'value' => $ticker,
					'label' => $label,
				);
			}
		}
		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
			'coins'       => $coins,
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
