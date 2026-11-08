<?php
/**
 * Creates a TrezorPay order without calling the API, for callback tests:
 * tests/wp.sh eval-file wp-content/plugins/trezorpay-for-woocommerce/tests/fake-order.php <amount> <minimum> <expires_in_seconds>
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

list( $trezorpay_amount, $trezorpay_minimum, $trezorpay_expires ) = $args + array( '27.9', '10', '1800' );

$trezorpay_order = wc_create_order( array( 'created_via' => 'checkout' ) );
$trezorpay_order->add_product( wc_get_product( 10 ), 1 );
$trezorpay_order->set_payment_method( 'trezorpay' );
$trezorpay_order->calculate_totals();
$trezorpay_order->update_meta_data( TrezorPay_Order::TICKER, 'trc20/usdt' );
$trezorpay_order->update_meta_data( TrezorPay_Order::ADDRESS, 'TFake' . substr( md5( uniqid( '', true ) ), 0, 29 ) );
$trezorpay_order->update_meta_data( TrezorPay_Order::AMOUNT, $trezorpay_amount );
$trezorpay_order->update_meta_data( TrezorPay_Order::MINIMUM, $trezorpay_minimum );
$trezorpay_order->update_meta_data( TrezorPay_Order::TOKEN, bin2hex( random_bytes( 16 ) ) );
$trezorpay_order->update_meta_data( TrezorPay_Order::RECEIVED, '0' );
$trezorpay_order->update_meta_data( TrezorPay_Order::QUOTED_AT, time() );
$trezorpay_order->update_meta_data( TrezorPay_Order::EXPIRES_AT, time() + (int) $trezorpay_expires );
$trezorpay_order->update_meta_data( TrezorPay_Order::LABEL, 'USDT (Tron TRC-20)' );
$trezorpay_order->update_meta_data( TrezorPay_Order::SYMBOL, 'USDT' );
$trezorpay_order->update_meta_data( TrezorPay_Order::NETWORK, 'Tron TRC-20' );
$trezorpay_order->update_meta_data( TrezorPay_Order::BASE_URL, TrezorPay_Order::url_base( rest_url() ) );
$trezorpay_order->save();
echo esc_html( $trezorpay_order->get_id() . ' ' . $trezorpay_order->get_order_key() ) . "\n";
