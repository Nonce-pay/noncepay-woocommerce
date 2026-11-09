<?php
/**
 * Cart and Checkout blocks integration.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers NoncePay with the block checkout.
 */
final class NoncePay_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name, matching the gateway id.
	 *
	 * @var string
	 */
	protected $name = 'noncepay';

	/**
	 * Loads the settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_noncepay_settings', array() );
	}

	/**
	 * Whether the method is offered.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = noncepay_gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'noncepay-blocks',
			NONCEPAY_URL . 'assets/js/checkout-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			NONCEPAY_VERSION,
			true
		);
		wp_set_script_translations( 'noncepay-blocks', 'noncepay-for-woocommerce', NONCEPAY_DIR . 'languages' );
		return array( 'noncepay-blocks' );
	}

	/**
	 * Data exposed to the script as getSetting( 'noncepay_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = noncepay_gateway();
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
