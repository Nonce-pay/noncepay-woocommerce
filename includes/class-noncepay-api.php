<?php
/**
 * Client for the NoncePay HTTP API.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * API client. Every method returns the decoded response array or a WP_Error.
 */
final class NoncePay_API {

	const BASE_URL = 'https://api.noncepay.app';

	const PUBKEY_OPTION = 'noncepay_pubkey';

	const PUBKEY_RETRY = 'noncepay_pubkey_retry';

	/**
	 * Creates (or returns the existing) payment address for a callback URL.
	 *
	 * @param string $ticker   Ticker route, such as trc20/usdt.
	 * @param string $callback Callback URL.
	 * @param string $address  Merchant wallet.
	 * @return array|WP_Error
	 */
	public static function create( $ticker, $callback, $address ) {
		return self::get(
			'/' . $ticker . '/create/',
			array(
				'callback' => $callback,
				'address'  => $address,
				'pending'  => 1,
			)
		);
	}

	/**
	 * Converts a fiat amount into the coin.
	 *
	 * @param string $ticker   Ticker route.
	 * @param string $value    Decimal amount.
	 * @param string $currency Fiat code.
	 * @return array|WP_Error
	 */
	public static function convert( $ticker, $value, $currency ) {
		return self::get(
			'/' . $ticker . '/convert/',
			array(
				'value' => $value,
				'from'  => strtolower( $currency ),
			)
		);
	}

	/**
	 * Returns a base64 PNG QR code for an address and amount.
	 *
	 * @param string $ticker  Ticker route.
	 * @param string $address Payment address.
	 * @param string $value   Coin amount.
	 * @param int    $size    Pixels.
	 * @return array|WP_Error
	 */
	public static function qrcode( $ticker, $address, $value, $size = 300 ) {
		return self::get(
			'/' . $ticker . '/qrcode/',
			array(
				'address' => $address,
				'value'   => $value,
				'size'    => $size,
			)
		);
	}

	/**
	 * Returns every supported coin.
	 *
	 * @return array|WP_Error
	 */
	public static function info() {
		return self::get( '/info/', array( 'prices' => 0 ) );
	}

	/**
	 * Returns the PEM public key that signs callbacks.
	 *
	 * Sites may pin the key by defining NONCEPAY_PUBLIC_KEY_PEM in wp-config.php.
	 *
	 * @param bool $refresh Fetch it again even when cached.
	 * @return string Empty when unavailable.
	 */
	public static function public_key( $refresh = false ) {
		if ( defined( 'NONCEPAY_PUBLIC_KEY_PEM' ) && is_string( NONCEPAY_PUBLIC_KEY_PEM ) && '' !== NONCEPAY_PUBLIC_KEY_PEM ) {
			return NONCEPAY_PUBLIC_KEY_PEM;
		}
		$cached = get_option( self::PUBKEY_OPTION );
		if ( is_array( $cached ) && ! empty( $cached['pem'] ) && ( ! $refresh || time() - (int) $cached['fetched_at'] < HOUR_IN_SECONDS ) ) {
			return (string) $cached['pem'];
		}
		$fallback = is_array( $cached ) && ! empty( $cached['pem'] ) ? (string) $cached['pem'] : '';
		if ( get_transient( self::PUBKEY_RETRY ) ) {
			return $fallback;
		}
		$response = self::get( '/pubkey/' );
		if ( is_wp_error( $response ) || empty( $response['pubkey'] ) || ! is_string( $response['pubkey'] ) ) {
			NoncePay_Logger::error( 'Could not fetch the NoncePay public key.' );
			set_transient( self::PUBKEY_RETRY, 1, 15 * MINUTE_IN_SECONDS );
			return $fallback;
		}
		update_option(
			self::PUBKEY_OPTION,
			array(
				'pem'        => $response['pubkey'],
				'fetched_at' => time(),
			),
			false
		);
		return $response['pubkey'];
	}

	/**
	 * Whether the public key is pinned in wp-config.php.
	 *
	 * @return bool
	 */
	public static function public_key_is_pinned() {
		return defined( 'NONCEPAY_PUBLIC_KEY_PEM' ) && is_string( NONCEPAY_PUBLIC_KEY_PEM ) && '' !== NONCEPAY_PUBLIC_KEY_PEM;
	}

	/**
	 * Performs a GET request.
	 *
	 * @param string $path  Path starting and ending with a slash.
	 * @param array  $query Query parameters.
	 * @return array|WP_Error
	 */
	private static function get( $path, $query = array() ) {
		$base = untrailingslashit( (string) apply_filters( 'noncepay_api_base_url', self::BASE_URL ) );
		$url  = $base . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'NoncePay-for-WooCommerce/' . NONCEPAY_VERSION,
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			NoncePay_Logger::error( sprintf( 'NoncePay API %s unreachable: %s', $path, $response->get_error_message() ) );
			return new WP_Error( 'noncepay_unreachable', __( 'The crypto payment service is unreachable. Please try again in a moment.', 'noncepay-for-woocommerce' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			NoncePay_Logger::error( sprintf( 'NoncePay API %s answered HTTP %d with a non-JSON body.', $path, $code ) );
			return new WP_Error( 'noncepay_bad_response', __( 'The crypto payment service returned an unexpected response. Please try again in a moment.', 'noncepay-for-woocommerce' ) );
		}

		if ( 200 !== $code || ( isset( $body['status'] ) && 'error' === $body['status'] ) ) {
			$message = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : 'HTTP ' . $code;
			NoncePay_Logger::error( sprintf( 'NoncePay API %s error (HTTP %d): %s', $path, $code, $message ) );
			return new WP_Error( 'noncepay_api_error', $message, array( 'status' => $code ) );
		}

		NoncePay_Logger::debug( sprintf( 'NoncePay API %s OK.', $path ) );
		return $body;
	}
}
