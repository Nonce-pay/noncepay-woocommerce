<?php
/**
 * REST routes: the signed payment callback and the customer status poll.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class NoncePay_REST {

	const NAMESPACE_V1 = 'noncepay/v1';

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
	 * Handles a NoncePay callback. Answers *ok* to every validly signed call so it is not retried.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function callback( WP_REST_Request $request ) {
		$params   = $request->get_query_params();
		$order_id = isset( $params['order'] ) ? absint( $params['order'] ) : 0;
		$token    = isset( $params['token'] ) && is_string( $params['token'] ) ? $params['token'] : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! self::signature_is_valid( (string) $request->get_header( 'x-ca-signature' ), NoncePay_Order::is_ours( $order ) ? $order : null ) ) {
			NoncePay_Logger::error( 'Callback rejected: invalid signature. If your site is behind a proxy, see the FAQ about noncepay_callback_base_url.' );
			return self::text( 'Bad signature', 401 );
		}

		NoncePay_Logger::debug( sprintf( 'Signed callback for order %d (pending=%s).', $order_id, isset( $params['pending'] ) ? sanitize_text_field( (string) $params['pending'] ) : '?' ) );

		if ( ! $order_id || '' === $token ) {
			return self::text( '*ok*' );
		}

		if ( ! self::lock( $order_id ) ) {
			return self::text( 'Busy, retry later', 503 );
		}

		try {
			$order = NoncePay_Order::reload( $order_id );
			if ( ! NoncePay_Order::is_ours( $order ) || ! NoncePay_Order::token_matches( $order, $token ) ) {
				NoncePay_Logger::debug( sprintf( 'Callback for order %d ignored: unknown order or token mismatch.', $order_id ) );
				return self::text( '*ok*' );
			}
			$gateway = noncepay_gateway();
			NoncePay_Order::apply_callback( $order, $params, $gateway ? $gateway->settings : array() );
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
		$response = rest_ensure_response( NoncePay_Order::state( $order ) );
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
		$gateway = noncepay_gateway();
		if ( ! $gateway || ! self::lock( $order->get_id() ) ) {
			return new WP_Error( 'noncepay_busy', __( 'Please try again in a moment.', 'noncepay-for-woocommerce' ), array( 'status' => 503 ) );
		}
		try {
			$order = NoncePay_Order::reload( $order->get_id() );
			$state = NoncePay_Order::state( $order );
			if ( 'expired' !== $state['state'] || ! $order->has_status( 'pending' ) ) {
				return new WP_Error( 'noncepay_not_expired', __( 'This quote cannot be refreshed.', 'noncepay-for-woocommerce' ), array( 'status' => 409 ) );
			}
			$result = NoncePay_Order::requote( $order, $gateway->quote_minutes() );
		} finally {
			self::unlock( $order->get_id() );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		$order = wc_get_order( $order->get_id() );
		return rest_ensure_response( NoncePay_Payment_Page::view_data( $order ) );
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
	 * Verifies x-ca-signature over the URL exactly as NoncePay called it.
	 *
	 * @param string        $signature Base64 signature.
	 * @param WC_Order|null $order     Order named by the callback, whose stored base URL is used.
	 * @return bool
	 */
	private static function signature_is_valid( $signature, $order ) {
		$raw = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the signature header is base64.
		if ( '' === $signature || false === $raw || ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$url = self::requested_url( $order );
		$key = NoncePay_API::public_key();
		if ( '' !== $key && 1 === openssl_verify( $url, $raw, $key, OPENSSL_ALGO_SHA256 ) ) {
			return true;
		}
		if ( NoncePay_API::public_key_is_pinned() ) {
			return false;
		}
		$fresh = NoncePay_API::public_key( true );
		return '' !== $fresh && $fresh !== $key && 1 === openssl_verify( $url, $raw, $fresh, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * The full URL NoncePay called: the scheme, host and port of the callback URL stored on the
	 * order (the site URL when there is none) plus the raw request URI.
	 *
	 * Behind a proxy that rewrites the path, set the public base with the noncepay_callback_base_url filter.
	 *
	 * @param WC_Order|null $order Order named by the callback.
	 * @return string
	 */
	private static function requested_url( $order ) {
		$base = $order ? (string) $order->get_meta( NoncePay_Order::BASE_URL ) : '';
		if ( '' === $base ) {
			$base = NoncePay_Order::url_base( home_url() );
		}
		$base = untrailingslashit( (string) apply_filters( 'noncepay_callback_base_url', $base, $order ) );
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
		if ( ! NoncePay_Order::is_ours( $order ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new WP_Error( 'noncepay_not_found', __( 'Order not found.', 'noncepay-for-woocommerce' ), array( 'status' => 404 ) );
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
	 * Takes the per-order lock that serializes callbacks and payment changes.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public static function lock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock cannot be cached or done through the WP API.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $order_id ), 15 ) );
	}

	/**
	 * Releases the per-order lock.
	 *
	 * @param int $order_id Order id.
	 */
	public static function unlock( $order_id ) {
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
		return 'noncepay_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 12 ) . '_' . $order_id;
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
