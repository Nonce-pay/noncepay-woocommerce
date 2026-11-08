<?php
/**
 * Checks TrezorPay_Decimal with bcmath and with the string fallback: php tests/decimal-test.php
 *
 * @package TrezorPay
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-trezorpay-decimal.php';

$failures = 0;
$check    = static function ( $label, $actual, $expected ) use ( &$failures ) {
	if ( $actual !== $expected ) {
		++$failures;
		echo "FAIL {$label}: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
	}
};

foreach ( array( true, false ) as $bcmath ) {
	TrezorPay_Decimal::$use_bcmath = $bcmath;
	$mode                          = $bcmath ? 'bcmath' : 'string';

	$check( "$mode parse", TrezorPay_Decimal::parse( '00012.3400' ), '12.34' );
	$check( "$mode parse zero", TrezorPay_Decimal::parse( '0.000' ), '0' );
	$check( "$mode parse float", TrezorPay_Decimal::parse( 1.5 ), null );
	$check( "$mode parse negative", TrezorPay_Decimal::parse( '-1' ), null );
	$check( "$mode parse exponent", TrezorPay_Decimal::parse( '1e5' ), null );
	$check( "$mode parse junk", TrezorPay_Decimal::parse( '1.2.3' ), null );
	$check( "$mode add", TrezorPay_Decimal::add( '0.1', '0.2' ), '0.3' );
	$check( "$mode add carry", TrezorPay_Decimal::add( '999.999999999999999999', '0.000000000000000001' ), '1000' );
	$check( "$mode add 18 decimals", TrezorPay_Decimal::add( '1.000000000000000001', '2' ), '3.000000000000000001' );
	$check( "$mode sub", TrezorPay_Decimal::sub_floor( '27.89', '10' ), '17.89' );
	$check( "$mode sub borrow", TrezorPay_Decimal::sub_floor( '1', '0.000000000000000001' ), '0.999999999999999999' );
	$check( "$mode sub floor", TrezorPay_Decimal::sub_floor( '1', '2' ), '0' );
	$check( "$mode mul", TrezorPay_Decimal::mul( '27.89', '99.5' ), '2775.055' );
	$check( "$mode mul zero", TrezorPay_Decimal::mul( '0', '99.5' ), '0' );
	$check( "$mode cmp lt", TrezorPay_Decimal::cmp( '0.00008', '0.0001' ), -1 );
	$check( "$mode cmp eq", TrezorPay_Decimal::cmp( '1.50', '1.5' ), 0 );
	$check( "$mode cmp gt", TrezorPay_Decimal::cmp( '10', '9.999999999999999999' ), 1 );
	$check( "$mode covers within tolerance", TrezorPay_Decimal::covers( '27.8', '27.89', '0.5' ), true );
	$check( "$mode covers exact edge", TrezorPay_Decimal::covers( '27.75055', '27.89', '0.5' ), true );
	$check( "$mode covers just below", TrezorPay_Decimal::covers( '27.75054', '27.89', '0.5' ), false );
	$check( "$mode covers no tolerance", TrezorPay_Decimal::covers( '27.88', '27.89', '0' ), false );
}

echo $failures ? "{$failures} failure(s)\n" : "All decimal checks passed (bcmath and string fallback).\n";
exit( $failures ? 1 : 0 );
