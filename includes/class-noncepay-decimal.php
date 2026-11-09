<?php
/**
 * Exact arithmetic on non-negative decimal strings.
 *
 * Crypto amounts carry up to 18 decimals: floats would round them, so every
 * amount stays a string. Uses bcmath when it is loaded, plain string math otherwise.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decimal helper.
 */
final class NoncePay_Decimal {

	/**
	 * Whether to use bcmath. Null means auto-detect.
	 *
	 * @var bool|null
	 */
	public static $use_bcmath = null;

	/**
	 * Returns a normalized decimal string, or null when the input is not a plain non-negative decimal.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function parse( $value ) {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( strlen( $value ) > 80 || ! preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return null;
		}
		return self::normalize( $value );
	}

	/**
	 * Adds two decimals.
	 *
	 * @param string $a Decimal.
	 * @param string $b Decimal.
	 * @return string
	 */
	public static function add( $a, $b ) {
		$scale = max( self::scale( $a ), self::scale( $b ) );
		if ( self::bcmath() ) {
			return self::normalize( bcadd( $a, $b, $scale ) );
		}
		return self::from_int( self::int_add( self::to_int( $a, $scale ), self::to_int( $b, $scale ) ), $scale );
	}

	/**
	 * Subtracts $b from $a, floored at zero.
	 *
	 * @param string $a Decimal.
	 * @param string $b Decimal.
	 * @return string
	 */
	public static function sub_floor( $a, $b ) {
		if ( self::cmp( $a, $b ) <= 0 ) {
			return '0';
		}
		$scale = max( self::scale( $a ), self::scale( $b ) );
		if ( self::bcmath() ) {
			return self::normalize( bcsub( $a, $b, $scale ) );
		}
		return self::from_int( self::int_sub( self::to_int( $a, $scale ), self::to_int( $b, $scale ) ), $scale );
	}

	/**
	 * Multiplies two decimals exactly.
	 *
	 * @param string $a Decimal.
	 * @param string $b Decimal.
	 * @return string
	 */
	public static function mul( $a, $b ) {
		$scale = self::scale( $a ) + self::scale( $b );
		if ( self::bcmath() ) {
			return self::normalize( bcmul( $a, $b, $scale ) );
		}
		return self::from_int( self::int_mul( self::to_int( $a, self::scale( $a ) ), self::to_int( $b, self::scale( $b ) ) ), $scale );
	}

	/**
	 * Compares two decimals: -1, 0 or 1.
	 *
	 * @param string $a Decimal.
	 * @param string $b Decimal.
	 * @return int
	 */
	public static function cmp( $a, $b ) {
		$scale = max( self::scale( $a ), self::scale( $b ) );
		if ( self::bcmath() ) {
			return bccomp( $a, $b, $scale );
		}
		return self::int_cmp( self::to_int( $a, $scale ), self::to_int( $b, $scale ) );
	}

	/**
	 * Whether $received covers $expected minus a tolerance in percent.
	 *
	 * @param string $received  Decimal amount received.
	 * @param string $expected  Decimal amount expected.
	 * @param string $tolerance Decimal percent between 0 and 100.
	 * @return bool
	 */
	public static function covers( $received, $expected, $tolerance ) {
		$share = self::sub_floor( '100', $tolerance );
		return self::cmp( self::mul( $received, '100' ), self::mul( $expected, $share ) ) >= 0;
	}

	/**
	 * Strips leading and trailing zeros.
	 *
	 * @param string $value Decimal.
	 * @return string
	 */
	private static function normalize( $value ) {
		$parts = explode( '.', $value, 2 );
		$int   = ltrim( $parts[0], '0' );
		$frac  = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		$int   = '' === $int ? '0' : $int;
		return '' === $frac ? $int : $int . '.' . $frac;
	}

	/**
	 * Number of fractional digits.
	 *
	 * @param string $value Decimal.
	 * @return int
	 */
	private static function scale( $value ) {
		$pos = strpos( $value, '.' );
		return false === $pos ? 0 : strlen( $value ) - $pos - 1;
	}

	/**
	 * Whether bcmath is used.
	 *
	 * @return bool
	 */
	private static function bcmath() {
		return null === self::$use_bcmath ? function_exists( 'bcadd' ) : ( self::$use_bcmath && function_exists( 'bcadd' ) );
	}

	/**
	 * Decimal to an integer string scaled by 10^scale.
	 *
	 * @param string $value Decimal.
	 * @param int    $scale Scale.
	 * @return string
	 */
	private static function to_int( $value, $scale ) {
		$parts = explode( '.', $value, 2 );
		$frac  = isset( $parts[1] ) ? $parts[1] : '';
		$int   = ltrim( $parts[0] . str_pad( $frac, $scale, '0' ), '0' );
		return '' === $int ? '0' : $int;
	}

	/**
	 * Integer string scaled by 10^scale back to a decimal.
	 *
	 * @param string $int   Integer string.
	 * @param int    $scale Scale.
	 * @return string
	 */
	private static function from_int( $int, $scale ) {
		if ( 0 === $scale ) {
			return self::normalize( $int );
		}
		$int = str_pad( $int, $scale + 1, '0', STR_PAD_LEFT );
		return self::normalize( substr( $int, 0, -$scale ) . '.' . substr( $int, -$scale ) );
	}

	/**
	 * Compares two integer strings.
	 *
	 * @param string $a Integer.
	 * @param string $b Integer.
	 * @return int
	 */
	private static function int_cmp( $a, $b ) {
		$a = ltrim( $a, '0' );
		$b = ltrim( $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b ) ? -1 : 1;
		}
		$cmp = strcmp( $a, $b );
		return $cmp < 0 ? -1 : ( $cmp > 0 ? 1 : 0 );
	}

	/**
	 * Adds two integer strings.
	 *
	 * @param string $a Integer.
	 * @param string $b Integer.
	 * @return string
	 */
	private static function int_add( $a, $b ) {
		$len    = max( strlen( $a ), strlen( $b ) );
		$a      = str_pad( $a, $len, '0', STR_PAD_LEFT );
		$b      = str_pad( $b, $len, '0', STR_PAD_LEFT );
		$carry  = 0;
		$result = '';
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$sum    = (int) $a[ $i ] + (int) $b[ $i ] + $carry;
			$result = ( $sum % 10 ) . $result;
			$carry  = intdiv( $sum, 10 );
		}
		return $carry ? $carry . $result : $result;
	}

	/**
	 * Subtracts integer string $b from a larger or equal $a.
	 *
	 * @param string $a Integer.
	 * @param string $b Integer.
	 * @return string
	 */
	private static function int_sub( $a, $b ) {
		$len    = strlen( $a );
		$b      = str_pad( $b, $len, '0', STR_PAD_LEFT );
		$borrow = 0;
		$result = '';
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$diff = (int) $a[ $i ] - (int) $b[ $i ] - $borrow;
			if ( $diff < 0 ) {
				$diff  += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$result = $diff . $result;
		}
		$result = ltrim( $result, '0' );
		return '' === $result ? '0' : $result;
	}

	/**
	 * Multiplies two integer strings.
	 *
	 * @param string $a Integer.
	 * @param string $b Integer.
	 * @return string
	 */
	private static function int_mul( $a, $b ) {
		$la     = strlen( $a );
		$lb     = strlen( $b );
		$digits = array_fill( 0, $la + $lb, 0 );
		for ( $i = $la - 1; $i >= 0; $i-- ) {
			for ( $j = $lb - 1; $j >= 0; $j-- ) {
				$sum                  = (int) $a[ $i ] * (int) $b[ $j ] + $digits[ $i + $j + 1 ];
				$digits[ $i + $j + 1 ] = $sum % 10;
				$digits[ $i + $j ]    += intdiv( $sum, 10 );
			}
		}
		$result = ltrim( implode( '', $digits ), '0' );
		return '' === $result ? '0' : $result;
	}
}
