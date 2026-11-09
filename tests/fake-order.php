<?php
/**
 * Creates a NoncePay order without calling the API, for callback tests:
 * tests/wp.sh eval-file wp-content/plugins/noncepay-for-woocommerce/tests/fake-order.php <amount> <minimum> <expires_in_seconds>
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

list( $noncepay_amount, $noncepay_minimum, $noncepay_expires ) = $args + array( '27.9', '10', '1800' );

$noncepay_order = wc_create_order( array( 'created_via' => 'checkout' ) );
$noncepay_order->add_product( wc_get_product( 10 ), 1 );
$noncepay_order->set_payment_method( 'noncepay' );
$noncepay_order->calculate_totals();
$noncepay_order->update_meta_data( NoncePay_Order::TICKER, 'trc20/usdt' );
$noncepay_order->update_meta_data( NoncePay_Order::ADDRESS, 'TFake' . substr( md5( uniqid( '', true ) ), 0, 29 ) );
$noncepay_order->update_meta_data( NoncePay_Order::AMOUNT, $noncepay_amount );
$noncepay_order->update_meta_data( NoncePay_Order::MINIMUM, $noncepay_minimum );
$noncepay_order->update_meta_data( NoncePay_Order::TOKEN, bin2hex( random_bytes( 16 ) ) );
$noncepay_order->update_meta_data( NoncePay_Order::RECEIVED, '0' );
$noncepay_order->update_meta_data( NoncePay_Order::QUOTED_AT, time() );
$noncepay_order->update_meta_data( NoncePay_Order::EXPIRES_AT, time() + (int) $noncepay_expires );
$noncepay_order->update_meta_data( NoncePay_Order::LABEL, 'USDT (Tron TRC-20)' );
$noncepay_order->update_meta_data( NoncePay_Order::SYMBOL, 'USDT' );
$noncepay_order->update_meta_data( NoncePay_Order::NETWORK, 'Tron TRC-20' );
$noncepay_order->update_meta_data( NoncePay_Order::BASE_URL, NoncePay_Order::url_base( rest_url() ) );
$noncepay_order->save();
echo esc_html( $noncepay_order->get_id() . ' ' . $noncepay_order->get_order_key() ) . "\n";
