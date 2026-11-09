<?php
/**
 * Payment instructions on the order-received and view-order pages, and order details in the admin.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment page.
 */
final class NoncePay_Payment_Page {

	/**
	 * Hooks the page.
	 */
	public static function init() {
		add_action( 'woocommerce_thankyou_noncepay', array( __CLASS__, 'render' ), 1 );
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
		$symbol = (string) $order->get_meta( NoncePay_Order::SYMBOL );
		$qr     = (string) $order->get_meta( NoncePay_Order::QR );
		return array_merge(
			NoncePay_Order::state( $order ),
			array(
				'address' => (string) $order->get_meta( NoncePay_Order::ADDRESS ),
				'symbol'  => '' !== $symbol ? $symbol : strtoupper( (string) $order->get_meta( NoncePay_Order::TICKER ) ),
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
		if ( ! NoncePay_Order::is_ours( $order ) ) {
			return;
		}

		$data   = self::view_data( $order );
		$symbol = $data['symbol'];
		$net    = (string) $order->get_meta( NoncePay_Order::NETWORK );

		wp_enqueue_style( 'noncepay-payment', NONCEPAY_URL . 'assets/css/payment.css', array(), NONCEPAY_VERSION );
		wp_enqueue_script( 'noncepay-payment', NONCEPAY_URL . 'assets/js/payment.js', array( 'wp-i18n' ), NONCEPAY_VERSION, true );
		wp_set_script_translations( 'noncepay-payment', 'noncepay-for-woocommerce', NONCEPAY_DIR . 'languages' );

		$config = array(
			'statusUrl'  => rest_url( 'noncepay/v1/status' ),
			'requoteUrl' => rest_url( 'noncepay/v1/requote' ),
			'orderId'    => $order->get_id(),
			'key'        => $order->get_order_key(),
			'now'        => time(),
			'data'       => $data,
		);
		?>
		<section class="noncepay" id="noncepay-payment" data-state="<?php echo esc_attr( $data['state'] ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<h2 class="noncepay__title"><?php esc_html_e( 'Complete your crypto payment', 'noncepay-for-woocommerce' ); ?></h2>

			<div class="noncepay__status" role="status" aria-live="polite">
				<span class="noncepay__dot" aria-hidden="true"></span>
				<span class="noncepay__status-text" data-noncepay="status"><?php echo esc_html( self::status_text( $data['state'] ) ); ?></span>
			</div>

			<div class="noncepay__body">
				<div class="noncepay__qr">
					<?php if ( '' !== $data['qr'] ) : ?>
						<img data-noncepay="qr" src="<?php echo esc_attr( $data['qr'] ); ?>" width="220" height="220" alt="<?php esc_attr_e( 'Payment QR code', 'noncepay-for-woocommerce' ); ?>" />
					<?php endif; ?>
				</div>
				<div class="noncepay__fields">
					<div class="noncepay__field">
						<span class="noncepay__label" data-noncepay="amount-label"><?php echo esc_html( 'partial' === $data['state'] ? __( 'Amount still to send', 'noncepay-for-woocommerce' ) : __( 'Amount to send', 'noncepay-for-woocommerce' ) ); ?></span>
						<div class="noncepay__copy">
							<code data-noncepay="amount"><?php echo esc_html( $data['to_send'] ); ?></code>
							<span class="noncepay__symbol"><?php echo esc_html( $symbol ); ?></span>
							<button type="button" class="noncepay__button" data-copy="amount"><?php esc_html_e( 'Copy', 'noncepay-for-woocommerce' ); ?></button>
						</div>
					</div>
					<div class="noncepay__field">
						<span class="noncepay__label"><?php esc_html_e( 'Payment address', 'noncepay-for-woocommerce' ); ?></span>
						<div class="noncepay__copy">
							<code data-noncepay="address" class="noncepay__address"><?php echo esc_html( $data['address'] ); ?></code>
							<button type="button" class="noncepay__button" data-copy="address"><?php esc_html_e( 'Copy', 'noncepay-for-woocommerce' ); ?></button>
						</div>
					</div>
					<p class="noncepay__minimum" data-noncepay="minimum-note" <?php echo $data['below_minimum'] ? '' : 'hidden'; ?>>
						<?php
						/* translators: 1: amount still missing, 2: minimum payment, 3: coin symbol */
						echo esc_html( sprintf( __( 'Only %1$s %3$s is missing, but payments below %2$s %3$s cannot be processed. Send at least %2$s %3$s: the extra is noted on your order.', 'noncepay-for-woocommerce' ), $data['remaining'], $data['minimum'], $symbol ) );
						?>
					</p>
					<p class="noncepay__warning">
						<?php
						/* translators: 1: coin symbol, 2: network name */
						echo esc_html( sprintf( __( 'Send only %1$s on the %2$s network to this address. Any other coin or network will be lost.', 'noncepay-for-woocommerce' ), $symbol, $net ) );
						?>
					</p>
					<p class="noncepay__timer" data-noncepay="timer"></p>
					<button type="button" class="noncepay__button noncepay__button--primary" data-noncepay="requote" hidden><?php esc_html_e( 'Get a new quote', 'noncepay-for-woocommerce' ); ?></button>
				</div>
			</div>

			<p class="noncepay__note"><?php esc_html_e( 'Send the exact amount, network fees on top. This page updates by itself once the payment is seen on the blockchain.', 'noncepay-for-woocommerce' ); ?></p>
		</section>
		<?php
	}

	/**
	 * NoncePay details on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_details( $order ) {
		if ( ! NoncePay_Order::is_ours( $order ) ) {
			return;
		}
		$state = NoncePay_Order::state( $order );
		$rows  = array(
			__( 'Coin', 'noncepay-for-woocommerce' )            => NoncePay_Order::label( $order ),
			__( 'Payment address', 'noncepay-for-woocommerce' ) => (string) $order->get_meta( NoncePay_Order::ADDRESS ),
			__( 'Quoted amount', 'noncepay-for-woocommerce' )   => $state['amount'],
			__( 'Received', 'noncepay-for-woocommerce' )        => $state['received'],
			__( 'Quote valid until', 'noncepay-for-woocommerce' ) => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['expires_at'] ),
		);
		echo '<h3>' . esc_html__( 'NoncePay', 'noncepay-for-woocommerce' ) . '</h3><p>';
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
		if ( $cancel && NoncePay_Order::is_ours( $order ) && time() < (int) $order->get_meta( NoncePay_Order::EXPIRES_AT ) ) {
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
			'waiting'   => __( 'Waiting for your payment', 'noncepay-for-woocommerce' ),
			'seen'      => __( 'Payment seen, waiting for confirmations', 'noncepay-for-woocommerce' ),
			'partial'   => __( 'Partial payment received', 'noncepay-for-woocommerce' ),
			'paid'      => __( 'Payment confirmed. Thank you!', 'noncepay-for-woocommerce' ),
			'expired'   => __( 'This quote has expired', 'noncepay-for-woocommerce' ),
			'cancelled' => __( 'This order is cancelled', 'noncepay-for-woocommerce' ),
		);
		return isset( $texts[ $state ] ) ? $texts[ $state ] : '';
	}
}
