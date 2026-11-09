<?php
/**
 * Logging through the WooCommerce logger.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger. Debug lines are written only when the debug setting is on; errors always are.
 */
final class NoncePay_Logger {

	const SOURCE = 'noncepay-for-woocommerce';

	/**
	 * Logs a debug message when debug logging is enabled.
	 *
	 * @param string $message Message, never containing tokens or keys.
	 */
	public static function debug( $message ) {
		$settings = get_option( 'woocommerce_noncepay_settings', array() );
		if ( isset( $settings['debug'] ) && 'yes' === $settings['debug'] ) {
			self::write( 'debug', $message );
		}
	}

	/**
	 * Logs an error.
	 *
	 * @param string $message Message, never containing tokens or keys.
	 */
	public static function error( $message ) {
		self::write( 'error', $message );
	}

	/**
	 * Removes the per-order token from a callback URL before it is logged.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function redact( $url ) {
		return (string) preg_replace( '/([?&]token=)[^&]*/', '$1***', $url );
	}

	/**
	 * Writes a log line.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 */
	private static function write( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
		}
	}
}
