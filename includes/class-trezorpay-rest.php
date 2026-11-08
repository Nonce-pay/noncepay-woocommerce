<?php
/**
 * REST routes: the signed payment callback and the customer status poll.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class TrezorPay_REST {

	const NAMESPACE_V1 = 'trezorpay/v1';

	/**
	 * Hooks the routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_plain_text' ), 10, 4 );
	}

	/**
	 * Registers the routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'callback' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
				'args'                => self::order_args(),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/requote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'requote' ),
				'permission_callback' => '__return_true',
				'args'                => self::order_args(),
			)
		);
	}

	/**
	 * Handles a TrezorPay callback. Answers *ok* to every validly signed call so it is not retried.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function callback( WP_REST_Request $request ) {
		if ( ! self::signature_is_valid( (string) $request->get_header( 'x-ca-signature' ) ) ) {
			TrezorPay_Logger::error( 'Callback rejected: invalid signature. If your site is behind a proxy, see the FAQ about trezorpay_callback_base_url.' );
			return self::text( 'Bad signature', 401 );
		}

		$params   = $request->get_query_params();
		$order_id = isset( $params['order'] ) ? absint( $params['order'] ) : 0;
		$token    = isset( $params['token'] ) && is_string( $params['token'] ) ? $params['token'] : '';
		TrezorPay_Logger::debug( sprintf( 'Signed callback for order %d (pending=%s).', $order_id, isset( $params['pending'] ) ? sanitize_text_field( (string) $params['pending'] ) : '?' ) );

		if ( ! $order_id || '' === $token ) {
			return self::text( '*ok*' );
		}

		if ( ! self::lock( $order_id ) ) {
			return self::text( 'Busy, retry later', 503 );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! TrezorPay_Order::is_ours( $order ) || ! hash_equals( (string) $order->get_meta( TrezorPay_Order::TOKEN ), $token ) ) {
				TrezorPay_Logger::debug( sprintf( 'Callback for order %d ignored: unknown order or token mismatch.', $order_id ) );
				return self::text( '*ok*' );
			}
			$gateway = trezorpay_gateway();
			TrezorPay_Order::apply_callback( $order, $params, $gateway ? $gateway->settings : array() );
		} finally {
			self::unlock( $order_id );
		}

		return self::text( '*ok*' );
	}

	/**
	 * Payment state for the payment page poll.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function status( WP_REST_Request $request ) {
		$order = self::customer_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$response = rest_ensure_response( TrezorPay_Order::state( $order ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Refreshes an expired quote for an order that has received nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function requote( WP_REST_Request $request ) {
		$order = self::customer_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$gateway = trezorpay_gateway();
		if ( ! $gateway || ! self::lock( $order->get_id() ) ) {
			return new WP_Error( 'trezorpay_busy', __( 'Please try again in a moment.', 'trezorpay-for-woocommerce' ), array( 'status' => 503 ) );
		}
		try {
			$order = wc_get_order( $order->get_id() );
			$state = TrezorPay_Order::state( $order );
			if ( 'expired' !== $state['state'] || ! $order->has_status( 'pending' ) ) {
				return new WP_Error( 'trezorpay_not_expired', __( 'This quote cannot be refreshed.', 'trezorpay-for-woocommerce' ), array( 'status' => 409 ) );
			}
			$result = TrezorPay_Order::requote( $order, $gateway->quote_minutes() );
		} finally {
			self::unlock( $order->get_id() );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		$order = wc_get_order( $order->get_id() );
		return rest_ensure_response( TrezorPay_Payment_Page::view_data( $order ) );
	}

	/**
	 * Serves our plain-text callback answers without JSON encoding.
	 *
	 * @param bool             $served  Whether the request was served.
	 * @param WP_HTTP_Response $result  Result.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function serve_plain_text( $served, $result, $request, $server ) {
		if ( $served || '/' . self::NAMESPACE_V1 . '/callback' !== $request->get_route() || ! $result instanceof WP_HTTP_Response || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		$server->send_header( 'Content-Type', 'text/plain; charset=utf-8' );
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plain-text answer.
		return true;
	}

	/**
	 * Verifies x-ca-signature over the URL exactly as TrezorPay called it.
	 *
	 * @param string $signature Base64 signature.
	 * @return bool
	 */
	private static function signature_is_valid( $signature ) {
		$raw = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the signature header is base64.
		if ( '' === $signature || false === $raw || ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$url = self::requested_url();
		$key = TrezorPay_API::public_key();
		if ( '' !== $key && 1 === openssl_verify( $url, $raw, $key, OPENSSL_ALGO_SHA256 ) ) {
			return true;
		}
		if ( TrezorPay_API::public_key_is_pinned() ) {
			return false;
		}
		$fresh = TrezorPay_API::public_key( true );
		return '' !== $fresh && $fresh !== $key && 1 === openssl_verify( $url, $raw, $fresh, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * The full URL of the current request: site scheme and host plus the raw request URI.
	 *
	 * Behind a proxy that rewrites the host or path, set the public base with the
	 * trezorpay_callback_base_url filter.
	 *
	 * @return string
	 */
	private static function requested_url() {
		$home = wp_parse_url( home_url() );
		$base = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$base = untrailingslashit( (string) apply_filters( 'trezorpay_callback_base_url', $base ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- signed bytes must stay exactly as received; only compared, never output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return $base . $uri;
	}

	/**
	 * Loads the order a customer request names, checking its order key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WC_Order|WP_Error
	 */
	private static function customer_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
		$key   = (string) $request->get_param( 'key' );
		if ( ! TrezorPay_Order::is_ours( $order ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new WP_Error( 'trezorpay_not_found', __( 'Order not found.', 'trezorpay-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return $order;
	}

	/**
	 * Arguments of the customer routes.
	 *
	 * @return array
	 */
	private static function order_args() {
		return array(
			'order_id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'key'      => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Takes the per-order lock that serializes concurrent callbacks.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function lock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock cannot be cached or done through the WP API.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $order_id ), 15 ) );
	}

	/**
	 * Releases the per-order lock.
	 *
	 * @param int $order_id Order id.
	 */
	private static function unlock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock cannot be cached or done through the WP API.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $order_id ) ) );
	}

	/**
	 * Lock name, unique per database and table prefix.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function lock_name( $order_id ) {
		global $wpdb;
		return 'trezorpay_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 12 ) . '_' . $order_id;
	}

	/**
	 * Plain-text response.
	 *
	 * @param string $body   Body.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function text( $body, $status = 200 ) {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
