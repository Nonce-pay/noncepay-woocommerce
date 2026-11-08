<?php
/**
 * Payment instructions on the order-received and view-order pages, and order details in the admin.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment page.
 */
final class TrezorPay_Payment_Page {

	/**
	 * Hooks the page.
	 */
	public static function init() {
		add_action( 'woocommerce_thankyou_trezorpay', array( __CLASS__, 'render' ), 1 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'render' ), 5 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_details' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_while_quoted' ), 10, 2 );
	}

	/**
	 * Data the payment page script needs.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function view_data( $order ) {
		$ticker = (string) $order->get_meta( TrezorPay_Order::TICKER );
		$coin   = TrezorPay_Coins::get( $ticker );
		$qr     = (string) $order->get_meta( TrezorPay_Order::QR );
		return array_merge(
			TrezorPay_Order::state( $order ),
			array(
				'address' => (string) $order->get_meta( TrezorPay_Order::ADDRESS ),
				'symbol'  => $coin ? $coin['symbol'] : strtoupper( $ticker ),
				'qr'      => '' === $qr ? '' : 'data:image/png;base64,' . $qr,
			)
		);
	}

	/**
	 * Renders the payment box.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! TrezorPay_Order::is_ours( $order ) ) {
			return;
		}

		$data   = self::view_data( $order );
		$ticker = (string) $order->get_meta( TrezorPay_Order::TICKER );
		$coin   = TrezorPay_Coins::get( $ticker );
		$symbol = $data['symbol'];
		$net    = $coin ? $coin['network_label'] : strtoupper( $ticker );

		wp_enqueue_style( 'trezorpay-payment', TREZORPAY_URL . 'assets/css/payment.css', array(), TREZORPAY_VERSION );
		wp_enqueue_script( 'trezorpay-payment', TREZORPAY_URL . 'assets/js/payment.js', array( 'wp-i18n' ), TREZORPAY_VERSION, true );
		wp_set_script_translations( 'trezorpay-payment', 'trezorpay-for-woocommerce' );

		$config = array(
			'statusUrl'  => rest_url( 'trezorpay/v1/status' ),
			'requoteUrl' => rest_url( 'trezorpay/v1/requote' ),
			'orderId'    => $order->get_id(),
			'key'        => $order->get_order_key(),
			'now'        => time(),
			'data'       => $data,
		);
		?>
		<section class="trezorpay" id="trezorpay-payment" data-state="<?php echo esc_attr( $data['state'] ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<h2 class="trezorpay__title"><?php esc_html_e( 'Complete your crypto payment', 'trezorpay-for-woocommerce' ); ?></h2>

			<div class="trezorpay__status" role="status" aria-live="polite">
				<span class="trezorpay__dot" aria-hidden="true"></span>
				<span class="trezorpay__status-text" data-trezorpay="status"><?php echo esc_html( self::status_text( $data['state'] ) ); ?></span>
			</div>

			<div class="trezorpay__body">
				<div class="trezorpay__qr">
					<?php if ( '' !== $data['qr'] ) : ?>
						<img data-trezorpay="qr" src="<?php echo esc_attr( $data['qr'] ); ?>" width="220" height="220" alt="<?php esc_attr_e( 'Payment QR code', 'trezorpay-for-woocommerce' ); ?>" />
					<?php endif; ?>
				</div>
				<div class="trezorpay__fields">
					<div class="trezorpay__field">
						<span class="trezorpay__label" data-trezorpay="amount-label"><?php echo esc_html( 'partial' === $data['state'] ? __( 'Amount still to send', 'trezorpay-for-woocommerce' ) : __( 'Amount to send', 'trezorpay-for-woocommerce' ) ); ?></span>
						<div class="trezorpay__copy">
							<code data-trezorpay="amount"><?php echo esc_html( 'partial' === $data['state'] ? $data['remaining'] : $data['amount'] ); ?></code>
							<span class="trezorpay__symbol"><?php echo esc_html( $symbol ); ?></span>
							<button type="button" class="trezorpay__button" data-copy="amount"><?php esc_html_e( 'Copy', 'trezorpay-for-woocommerce' ); ?></button>
						</div>
					</div>
					<div class="trezorpay__field">
						<span class="trezorpay__label"><?php esc_html_e( 'Payment address', 'trezorpay-for-woocommerce' ); ?></span>
						<div class="trezorpay__copy">
							<code data-trezorpay="address" class="trezorpay__address"><?php echo esc_html( $data['address'] ); ?></code>
							<button type="button" class="trezorpay__button" data-copy="address"><?php esc_html_e( 'Copy', 'trezorpay-for-woocommerce' ); ?></button>
						</div>
					</div>
					<p class="trezorpay__warning">
						<?php
						/* translators: 1: coin symbol, 2: network name */
						echo esc_html( sprintf( __( 'Send only %1$s on the %2$s network to this address. Any other coin or network will be lost.', 'trezorpay-for-woocommerce' ), $symbol, $net ) );
						?>
					</p>
					<p class="trezorpay__timer" data-trezorpay="timer"></p>
					<button type="button" class="trezorpay__button trezorpay__button--primary" data-trezorpay="requote" hidden><?php esc_html_e( 'Get a new quote', 'trezorpay-for-woocommerce' ); ?></button>
				</div>
			</div>

			<p class="trezorpay__note"><?php esc_html_e( 'Send the exact amount, network fees on top. This page updates by itself once the payment is seen on the blockchain.', 'trezorpay-for-woocommerce' ); ?></p>
		</section>
		<?php
	}

	/**
	 * TrezorPay details on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_details( $order ) {
		if ( ! TrezorPay_Order::is_ours( $order ) ) {
			return;
		}
		$state = TrezorPay_Order::state( $order );
		$rows  = array(
			__( 'Coin', 'trezorpay-for-woocommerce' )            => TrezorPay_Order::coin_label( (string) $order->get_meta( TrezorPay_Order::TICKER ) ),
			__( 'Payment address', 'trezorpay-for-woocommerce' ) => (string) $order->get_meta( TrezorPay_Order::ADDRESS ),
			__( 'Quoted amount', 'trezorpay-for-woocommerce' )   => $state['amount'],
			__( 'Received', 'trezorpay-for-woocommerce' )        => $state['received'],
			__( 'Quote valid until', 'trezorpay-for-woocommerce' ) => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['expires_at'] ),
		);
		echo '<h3>' . esc_html__( 'TrezorPay', 'trezorpay-for-woocommerce' ) . '</h3><p>';
		foreach ( $rows as $label => $value ) {
			echo '<strong>' . esc_html( $label ) . ':</strong> <span style="word-break:break-all">' . esc_html( $value ) . '</span><br />';
		}
		echo '</p>';
	}

	/**
	 * Keeps WooCommerce from cancelling an unpaid order while its quote is still valid.
	 *
	 * @param bool     $cancel Whether to cancel.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function keep_while_quoted( $cancel, $order ) {
		if ( $cancel && TrezorPay_Order::is_ours( $order ) && time() < (int) $order->get_meta( TrezorPay_Order::EXPIRES_AT ) ) {
			return false;
		}
		return $cancel;
	}

	/**
	 * Server-rendered status line; the script keeps it current.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function status_text( $state ) {
		$texts = array(
			'waiting'   => __( 'Waiting for your payment', 'trezorpay-for-woocommerce' ),
			'seen'      => __( 'Payment seen, waiting for confirmations', 'trezorpay-for-woocommerce' ),
			'partial'   => __( 'Partial payment received', 'trezorpay-for-woocommerce' ),
			'paid'      => __( 'Payment confirmed. Thank you!', 'trezorpay-for-woocommerce' ),
			'expired'   => __( 'This quote has expired', 'trezorpay-for-woocommerce' ),
			'cancelled' => __( 'This order is cancelled', 'trezorpay-for-woocommerce' ),
		);
		return isset( $texts[ $state ] ) ? $texts[ $state ] : '';
	}
}
