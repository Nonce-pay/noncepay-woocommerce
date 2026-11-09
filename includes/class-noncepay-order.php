<?php
/**
 * NoncePay data stored on a WooCommerce order, quoting and callback bookkeeping.
 *
 * @package NoncePay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order helper.
 */
final class NoncePay_Order {

	const TICKER        = '_noncepay_ticker';
	const ADDRESS       = '_noncepay_address';
	const AMOUNT        = '_noncepay_amount';
	const MINIMUM       = '_noncepay_minimum';
	const QUOTED_AT     = '_noncepay_quoted_at';
	const EXPIRES_AT    = '_noncepay_expires_at';
	const TOKEN         = '_noncepay_token';
	const RECEIVED      = '_noncepay_received';
	const CONFIRMED     = '_noncepay_confirmed_uuids';
	const SEEN          = '_noncepay_seen_uuids';
	const SEEN_AT       = '_noncepay_seen_at';
	const PREVIOUS      = '_noncepay_previous_addresses';
	const OLD_TOKENS    = '_noncepay_old_tokens';
	const QR            = '_noncepay_qr';
	const LABEL         = '_noncepay_label';
	const SYMBOL        = '_noncepay_symbol';
	const NETWORK       = '_noncepay_network_label';
	const BASE_URL      = '_noncepay_callback_base';
	const CANCELLED     = '_noncepay_was_cancelled';

	/**
	 * Whether the order is paid with NoncePay and has a payment address.
	 *
	 * @param WC_Order|false|null $order Order.
	 * @return bool
	 */
	public static function is_ours( $order ) {
		return $order instanceof WC_Order && 'noncepay' === $order->get_payment_method() && '' !== (string) $order->get_meta( self::ADDRESS );
	}

	/**
	 * Loads the order again, bypassing the copies cached earlier in this request. Call it after taking the order lock.
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|false
	 */
	public static function reload( $order_id ) {
		clean_post_cache( $order_id );
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		return wc_get_order( $order_id );
	}

	/**
	 * Whether a payment was already seen or credited on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function has_payments( $order ) {
		return (bool) self::list_meta( $order, self::SEEN ) || (bool) self::list_meta( $order, self::CONFIRMED );
	}

	/**
	 * Quotes the order total in a coin and creates (or reuses) its payment address.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $ticker  Ticker route.
	 * @param string   $wallet  Merchant wallet for the coin.
	 * @param int      $minutes Quote validity.
	 * @return true|WP_Error Error messages are safe to show the customer.
	 */
	public static function start( $order, $ticker, $wallet, $minutes ) {
		$coin = NoncePay_Coins::get( $ticker );
		if ( ! $coin ) {
			return new WP_Error( 'noncepay_coin', __( 'Please choose a supported cryptocurrency.', 'noncepay-for-woocommerce' ) );
		}

		$amount = self::convert( $order, $coin );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$token      = (string) $order->get_meta( self::TOKEN );
		$old_tokens = self::list_meta( $order, self::OLD_TOKENS );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 16 ) );
		}

		$callback = self::callback_url( $order, $ticker, $token );
		$created  = self::create_address( $ticker, $callback, $wallet );
		if ( is_wp_error( $created ) && 'noncepay_wallet_changed' === $created->get_error_code() ) {
			// The API keeps the first destination of a callback URL forever: a new wallet needs a new callback URL.
			$old_tokens[] = $token;
			$token        = bin2hex( random_bytes( 16 ) );
			$callback     = self::callback_url( $order, $ticker, $token );
			$created      = self::create_address( $ticker, $callback, $wallet );
			if ( is_wp_error( $created ) && 'noncepay_wallet_changed' === $created->get_error_code() ) {
				NoncePay_Logger::error( sprintf( 'NoncePay forwards %s to another wallet than the configured one, even under a new callback URL: payment refused.', $ticker ) );
			}
		}
		if ( is_wp_error( $created ) ) {
			return 'noncepay_wallet' === $created->get_error_code() ? $created : self::generic_error( $created );
		}
		$address = $created['address_in'];

		$minimum = NoncePay_Decimal::parse( isset( $created['minimum_transaction_coin'] ) ? $created['minimum_transaction_coin'] : null );
		if ( null === $minimum && '' !== $coin['minimum'] ) {
			$minimum = $coin['minimum'];
		}
		if ( null === $minimum ) {
			NoncePay_Logger::error( sprintf( 'Unknown minimum for %s: payment refused.', $ticker ) );
			return self::generic_error();
		}
		if ( NoncePay_Decimal::cmp( $amount, $minimum ) < 0 ) {
			return self::below_minimum_error( $coin, $minimum );
		}

		$previous_address = (string) $order->get_meta( self::ADDRESS );
		if ( '' !== $previous_address && $previous_address !== $address ) {
			$previous                      = self::list_meta( $order, self::PREVIOUS );
			$previous[ $previous_address ] = self::label( $order );
			$order->update_meta_data( self::PREVIOUS, $previous );
		}

		$order->update_meta_data( self::TICKER, $ticker );
		$order->update_meta_data( self::ADDRESS, $address );
		$order->update_meta_data( self::MINIMUM, $minimum );
		$order->update_meta_data( self::TOKEN, $token );
		$order->update_meta_data( self::OLD_TOKENS, array_values( array_unique( $old_tokens ) ) );
		$order->update_meta_data( self::LABEL, NoncePay_Coins::label( $coin ) );
		$order->update_meta_data( self::SYMBOL, $coin['symbol'] );
		$order->update_meta_data( self::NETWORK, $coin['network_label'] );
		$order->update_meta_data( self::BASE_URL, self::url_base( $callback ) );
		if ( '' === (string) $order->get_meta( self::RECEIVED ) ) {
			$order->update_meta_data( self::RECEIVED, '0' );
		}
		self::store_quote( $order, $ticker, $address, $amount, $minutes );

		/* translators: 1: amount, 2: coin label, 3: payment address */
		$order->add_order_note( sprintf( __( 'NoncePay: awaiting %1$s %2$s on %3$s.', 'noncepay-for-woocommerce' ), $amount, NoncePay_Coins::label( $coin ), $address ) );
		return true;
	}

	/**
	 * Refreshes an expired quote on an order that has received nothing yet. The address stays the same.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $minutes Quote validity.
	 * @return true|WP_Error
	 */
	public static function requote( $order, $minutes ) {
		$ticker = (string) $order->get_meta( self::TICKER );
		$coin   = NoncePay_Coins::get( $ticker );
		if ( ! $coin ) {
			return self::generic_error();
		}
		$amount = self::convert( $order, $coin );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}
		$minimum = (string) $order->get_meta( self::MINIMUM );
		if ( '' !== $minimum && NoncePay_Decimal::cmp( $amount, $minimum ) < 0 ) {
			return self::below_minimum_error( $coin, $minimum );
		}
		self::store_quote( $order, $ticker, (string) $order->get_meta( self::ADDRESS ), $amount, $minutes );
		/* translators: 1: amount, 2: coin label */
		$order->add_order_note( sprintf( __( 'NoncePay: quote refreshed to %1$s %2$s.', 'noncepay-for-woocommerce' ), $amount, self::label( $order ) ) );
		return true;
	}

	/**
	 * Applies a verified callback to the order. The caller holds the order lock.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $params   Callback query parameters.
	 * @param array    $settings Gateway settings: tolerance.
	 */
	public static function apply_callback( $order, $params, $settings ) {
		$field   = static function ( $name, $pattern ) use ( $params ) {
			$value = isset( $params[ $name ] ) && is_string( $params[ $name ] ) ? $params[ $name ] : '';
			return preg_match( $pattern, $value ) ? $value : '';
		};
		$uuid    = $field( 'uuid', '/^[A-Za-z0-9-]{1,100}$/' );
		$address = $field( 'address_in', '/^[A-Za-z0-9:]{1,128}$/' );
		$txid_in = $field( 'txid_in', '/^[A-Za-z0-9]{1,128}$/' );
		$pending = $field( 'pending', '/^[01]$/' );
		$coin    = $field( 'coin', '/^[a-z0-9_.-]{1,40}$/' );

		if ( '' === $uuid || '' === $pending ) {
			NoncePay_Logger::error( sprintf( 'Order %d: callback without uuid or pending flag ignored.', $order->get_id() ) );
			return;
		}

		$ticker   = (string) $order->get_meta( self::TICKER );
		$previous = self::list_meta( $order, self::PREVIOUS );

		if ( $address !== (string) $order->get_meta( self::ADDRESS ) ) {
			if ( isset( $previous[ $address ] ) ) {
				self::apply_previous_address_payment( $order, $uuid, $address, $params, $pending );
			} else {
				NoncePay_Logger::debug( sprintf( 'Order %d: callback for an unknown address ignored.', $order->get_id() ) );
			}
			return;
		}

		if ( NoncePay_Coins::callback_coin( $ticker ) !== $coin ) {
			NoncePay_Logger::error( sprintf( 'Order %d: callback for coin %s on a %s address ignored.', $order->get_id(), $coin, $ticker ) );
			return;
		}

		$value   = NoncePay_Decimal::parse( isset( $params['value_coin'] ) ? $params['value_coin'] : null );
		$label   = self::label( $order );
		$seen    = self::list_meta( $order, self::SEEN );
		$done    = self::list_meta( $order, self::CONFIRMED );
		$seen_at = self::list_meta( $order, self::SEEN_AT );

		if ( ! isset( $seen_at[ $uuid ] ) && ! in_array( $uuid, $done, true ) ) {
			$seen_at[ $uuid ] = time();
			$order->update_meta_data( self::SEEN_AT, $seen_at );
		}
		if ( $order->has_status( 'cancelled' ) ) {
			$order->update_meta_data( self::CANCELLED, 'yes' );
		}

		if ( '1' === $pending ) {
			if ( in_array( $uuid, $seen, true ) || in_array( $uuid, $done, true ) ) {
				return;
			}
			$seen[] = $uuid;
			$order->update_meta_data( self::SEEN, $seen );
			$order->save();
			$note = sprintf(
				/* translators: 1: amount, 2: coin label, 3: transaction id */
				__( 'NoncePay: payment of %1$s %2$s seen on chain (txid_in %3$s), waiting for confirmations.', 'noncepay-for-woocommerce' ),
				null === $value ? '?' : $value,
				$label,
				$txid_in
			);
			if ( ! $order->is_paid() && $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
				$order->update_status( 'on-hold', $note );
			} else {
				$order->add_order_note( $note );
			}
			return;
		}

		if ( in_array( $uuid, $done, true ) ) {
			NoncePay_Logger::debug( sprintf( 'Order %d: duplicate confirmed callback ignored.', $order->get_id() ) );
			return;
		}

		if ( null === $value ) {
			NoncePay_Logger::error( sprintf( 'Order %d: confirmed callback with an unreadable amount, not credited.', $order->get_id() ) );
			$order->add_order_note( __( 'NoncePay: a confirmed payment arrived with an unreadable amount and was not credited. Check your wallet and review the order manually.', 'noncepay-for-woocommerce' ) );
			return;
		}

		$txid_out  = $field( 'txid_out', '/^[A-Za-z0-9]{1,128}$/' );
		$forwarded = NoncePay_Decimal::parse( isset( $params['value_forwarded_coin'] ) ? $params['value_forwarded_coin'] : null );
		if ( '' === $txid_out || null === $forwarded ) {
			NoncePay_Logger::error( sprintf( 'Order %d: confirmed callback without a forwarding transaction, not credited.', $order->get_id() ) );
			$order->save();
			/* translators: 1: amount, 2: coin label, 3: transaction id */
			$order->add_order_note( sprintf( __( 'NoncePay: a confirmed payment of %1$s %2$s (txid_in %3$s) arrived without a forwarding transaction and was not credited. Check your wallet and review the order manually.', 'noncepay-for-woocommerce' ), $value, $label, $txid_in ) );
			return;
		}
		$received  = NoncePay_Decimal::add( self::received( $order ), $value );
		$expected  = (string) $order->get_meta( self::AMOUNT );

		$done[] = $uuid;
		if ( ! in_array( $uuid, $seen, true ) ) {
			$seen[] = $uuid;
		}
		$order->update_meta_data( self::CONFIRMED, $done );
		$order->update_meta_data( self::SEEN, $seen );
		$order->update_meta_data( self::RECEIVED, $received );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: coin label, 3: txid_in, 4: amount forwarded, 5: txid_out, 6: total received, 7: total expected */
				__( 'NoncePay: payment of %1$s %2$s confirmed (txid_in %3$s). %4$s forwarded to your wallet (txid_out %5$s). Received so far: %6$s of %7$s.', 'noncepay-for-woocommerce' ),
				$value,
				$label,
				$txid_in,
				null === $forwarded ? '?' : $forwarded,
				$txid_out,
				$received,
				$expected
			)
		);

		self::settle( $order, $received, $expected, $txid_in, $settings, $done, $seen_at );
	}

	/**
	 * Marks the order paid, or holds it, from the confirmed total.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $received Confirmed total.
	 * @param string   $expected Quoted amount.
	 * @param string   $txid_in  Transaction that triggered the check.
	 * @param array    $settings Gateway settings.
	 * @param array    $done     Credited payment uuids.
	 * @param array    $seen_at  First-seen time per payment uuid.
	 */
	private static function settle( $order, $received, $expected, $txid_in, $settings, $done, $seen_at ) {
		$label = self::label( $order );

		if ( $order->is_paid() || $order->has_status( array( 'refunded' ) ) ) {
			$order->add_order_note( __( 'NoncePay: an extra payment arrived on an order that is already paid. Refund the customer from your wallet if needed.', 'noncepay-for-woocommerce' ) );
			return;
		}

		if ( null === NoncePay_Decimal::parse( $expected ) ) {
			$order->update_status( 'on-hold', __( 'NoncePay: the quoted amount is missing, review the order manually.', 'noncepay-for-woocommerce' ) );
			return;
		}

		$tolerance = NoncePay_Decimal::parse( isset( $settings['tolerance'] ) ? (string) $settings['tolerance'] : '' );
		if ( null === $tolerance || NoncePay_Decimal::cmp( $tolerance, '100' ) >= 0 ) {
			$tolerance = '0';
		}

		$expires = (int) $order->get_meta( self::EXPIRES_AT );
		$late    = false;
		foreach ( $done as $uuid ) {
			if ( isset( $seen_at[ $uuid ] ) && (int) $seen_at[ $uuid ] > $expires ) {
				$late = true;
			}
		}

		if ( NoncePay_Decimal::covers( $received, $expected, $tolerance ) ) {
			if ( NoncePay_Decimal::cmp( $received, $expected ) > 0 ) {
				/* translators: 1: amount over, 2: coin label */
				$order->add_order_note( sprintf( __( 'NoncePay: overpaid by %1$s %2$s.', 'noncepay-for-woocommerce' ), NoncePay_Decimal::sub_floor( $received, $expected ), $label ) );
			}
			if ( 'yes' === $order->get_meta( self::CANCELLED ) ) {
				self::hold( $order, __( 'NoncePay: the quoted amount was received, but the order had been cancelled before the payment arrived. Review it manually: restore the order or refund the customer from your wallet.', 'noncepay-for-woocommerce' ) );
				return;
			}
			if ( $late ) {
				$now = self::convert(
					$order,
					array(
						'ticker'  => (string) $order->get_meta( self::TICKER ),
						'minimum' => '',
					)
				);
				if ( is_wp_error( $now ) ) {
					self::hold( $order, __( 'NoncePay: part of the payment was sent after the quote expired and the current rate could not be checked. Review it manually.', 'noncepay-for-woocommerce' ) );
					return;
				}
				if ( ! NoncePay_Decimal::covers( $received, $now, $tolerance ) ) {
					/* translators: 1: amount received, 2: coin label, 3: amount the order total is worth now */
					self::hold( $order, sprintf( __( 'NoncePay: part of the payment was sent after the quote expired and is now worth less than the order total (%1$s %2$s received, %3$s needed at the current rate). Review it manually.', 'noncepay-for-woocommerce' ), $received, $label, $now ) );
					return;
				}
				$order->add_order_note( __( 'NoncePay: part of the payment was sent after the quote expired. It still covers the order total at the current rate, so the order is marked paid.', 'noncepay-for-woocommerce' ) );
			}
			$order->payment_complete( $txid_in );
			return;
		}

		$missing = NoncePay_Decimal::sub_floor( $expected, $received );
		if ( $late ) {
			/* translators: 1: amount received, 2: amount expected, 3: coin label, 4: amount missing */
			$note = sprintf( __( 'NoncePay: payment received after the quote expired and below the quoted amount (%1$s of %2$s %3$s, %4$s missing). Review it manually.', 'noncepay-for-woocommerce' ), $received, $expected, $label, $missing );
		} else {
			/* translators: 1: amount received, 2: amount expected, 3: coin label, 4: amount missing */
			$note = sprintf( __( 'NoncePay: underpaid, %1$s of %2$s %3$s received (%4$s missing). The order stays on hold until the rest arrives.', 'noncepay-for-woocommerce' ), $received, $expected, $label, $missing );
		}
		self::hold( $order, $note );
	}

	/**
	 * Puts the order on hold with a note, or only adds the note when it already is.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  Note.
	 */
	private static function hold( $order, $note ) {
		if ( $order->has_status( 'on-hold' ) ) {
			$order->add_order_note( $note );
		} else {
			$order->update_status( 'on-hold', $note );
		}
	}

	/**
	 * Records a payment made to an address quoted earlier for another coin. Never credited automatically.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $uuid    Payment uuid.
	 * @param string   $address Address paid.
	 * @param array    $params  Callback parameters.
	 * @param string   $pending '1' or '0'.
	 */
	private static function apply_previous_address_payment( $order, $uuid, $address, $params, $pending ) {
		$key  = $pending . ':' . $uuid;
		$done = self::list_meta( $order, self::CONFIRMED );
		if ( in_array( $key, $done, true ) ) {
			return;
		}
		$done[] = $key;
		$order->update_meta_data( self::CONFIRMED, $done );
		$order->save();

		$previous = self::list_meta( $order, self::PREVIOUS );
		$value    = NoncePay_Decimal::parse( isset( $params['value_coin'] ) ? $params['value_coin'] : null );
		$note     = sprintf(
			/* translators: 1: amount, 2: coin label, 3: address */
			__( 'NoncePay: a payment of %1$s %2$s arrived on %3$s, an address quoted earlier for this order. It is not credited automatically: review it manually.', 'noncepay-for-woocommerce' ),
			null === $value ? '?' : $value,
			is_string( $previous[ $address ] ) ? $previous[ $address ] : '',
			$address
		);
		if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Payment state shown to the customer.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function state( $order ) {
		$received = self::received( $order );
		$expected = (string) $order->get_meta( self::AMOUNT );
		$seen     = self::list_meta( $order, self::SEEN );
		$done     = self::list_meta( $order, self::CONFIRMED );

		if ( $order->is_paid() ) {
			$state = 'paid';
		} elseif ( array_diff( $seen, $done ) ) {
			$state = 'seen';
		} elseif ( NoncePay_Decimal::cmp( $received, '0' ) > 0 ) {
			$state = 'partial';
		} elseif ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			$state = 'cancelled';
		} elseif ( time() > (int) $order->get_meta( self::EXPIRES_AT ) ) {
			$state = 'expired';
		} else {
			$state = 'waiting';
		}

		$remaining = null === NoncePay_Decimal::parse( $expected ) ? '' : NoncePay_Decimal::sub_floor( $expected, $received );
		$minimum   = NoncePay_Decimal::parse( (string) $order->get_meta( self::MINIMUM ) );
		$to_send   = 'partial' === $state ? $remaining : $expected;
		$below     = 'partial' === $state && null !== $minimum && '' !== $remaining && NoncePay_Decimal::cmp( $remaining, '0' ) > 0 && NoncePay_Decimal::cmp( $remaining, $minimum ) < 0;
		if ( $below ) {
			// NoncePay ignores payments below the coin minimum: a smaller top-up would never reach the merchant.
			$to_send = $minimum;
		}

		return array(
			'state'         => $state,
			'amount'        => $expected,
			'received'      => $received,
			'remaining'     => $remaining,
			'to_send'       => $to_send,
			'minimum'       => null === $minimum ? '' : $minimum,
			'below_minimum' => $below,
			'expires_at'    => (int) $order->get_meta( self::EXPIRES_AT ),
		);
	}

	/**
	 * Confirmed total received.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function received( $order ) {
		$received = NoncePay_Decimal::parse( (string) $order->get_meta( self::RECEIVED ) );
		return null === $received ? '0' : $received;
	}

	/**
	 * Coin label saved on the order, such as "USDT (Tron TRC-20)". Never calls the API.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function label( $order ) {
		$label = (string) $order->get_meta( self::LABEL );
		return '' !== $label ? $label : strtoupper( (string) $order->get_meta( self::TICKER ) );
	}

	/**
	 * Whether a callback token belongs to the order, current or retired after a wallet change.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token Token from the callback URL.
	 * @return bool
	 */
	public static function token_matches( $order, $token ) {
		$tokens   = self::list_meta( $order, self::OLD_TOKENS );
		$tokens[] = (string) $order->get_meta( self::TOKEN );
		foreach ( $tokens as $known ) {
			if ( is_string( $known ) && '' !== $known && hash_equals( $known, $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scheme, host and port of a URL as NoncePay normalizes it when calling back (lowercase host, no default port).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_base( $url ) {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( preg_match( '/[^\x20-\x7e]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = false === $ascii ? $host : $ascii;
		}
		$port = isset( $parts['port'] ) && ! ( ( 'http' === $scheme && 80 === (int) $parts['port'] ) || ( 'https' === $scheme && 443 === (int) $parts['port'] ) ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}

	/**
	 * Creates the address for a callback URL and checks it forwards to the configured wallet.
	 *
	 * @param string $ticker   Ticker route.
	 * @param string $callback Callback URL.
	 * @param string $wallet   Merchant wallet.
	 * @return array|WP_Error The create response; error code noncepay_wallet_changed when it forwards elsewhere.
	 */
	private static function create_address( $ticker, $callback, $wallet ) {
		$created = NoncePay_API::create( $ticker, $callback, $wallet );
		if ( is_wp_error( $created ) ) {
			if ( 'You must provide a valid address' === $created->get_error_message() ) {
				NoncePay_Logger::error( sprintf( 'The wallet configured for %s was rejected by NoncePay. Fix it in WooCommerce > Settings > Payments > NoncePay.', $ticker ) );
				return new WP_Error( 'noncepay_wallet', __( 'This cryptocurrency is temporarily unavailable. Please choose another one.', 'noncepay-for-woocommerce' ) );
			}
			return $created;
		}

		$address = isset( $created['address_in'] ) && is_string( $created['address_in'] ) ? $created['address_in'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9:]{20,128}$/', $address ) ) {
			NoncePay_Logger::error( sprintf( 'NoncePay returned no usable address for %s.', $ticker ) );
			return new WP_Error( 'noncepay_error', 'No usable address' );
		}

		$out = isset( $created['address_out'] ) && is_string( $created['address_out'] ) ? $created['address_out'] : '';
		if ( self::canonical_wallet( $ticker, $out ) !== self::canonical_wallet( $ticker, $wallet ) ) {
			NoncePay_Logger::debug( sprintf( 'Address for %s forwards to another wallet than the configured one: creating a new one.', $ticker ) );
			return new WP_Error( 'noncepay_wallet_changed', 'Wallet changed' );
		}
		return $created;
	}

	/**
	 * A wallet in the form the API echoes it back: EVM hex, CashAddr (prefix optional) and bech32 are
	 * case-insensitive; base58 addresses are compared exactly.
	 *
	 * @param string $ticker Ticker route.
	 * @param string $wallet Wallet address.
	 * @return string
	 */
	private static function canonical_wallet( $ticker, $wallet ) {
		$wallet = trim( $wallet );
		if ( 'evm' === NoncePay_Coins::family( $ticker ) ) {
			return strtolower( $wallet );
		}
		$bare = preg_replace( '/^bitcoincash:/i', '', $wallet );
		return preg_match( '/^(bc1|ltc1|tb1|tltc1|q|p)/i', $bare ) ? strtolower( $bare ) : $bare;
	}

	/**
	 * The callback URL that identifies this order's address for a coin.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $ticker Ticker route.
	 * @param string   $token  Per-order secret.
	 * @return string
	 */
	private static function callback_url( $order, $ticker, $token ) {
		return add_query_arg(
			array(
				'order'  => $order->get_id(),
				'ticker' => NoncePay_Coins::callback_coin( $ticker ),
				'token'  => $token,
			),
			rest_url( 'noncepay/v1/callback' )
		);
	}

	/**
	 * Converts the order total into the coin.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $coin  Coin.
	 * @return string|WP_Error Coin amount.
	 */
	private static function convert( $order, $coin ) {
		$total = NoncePay_Decimal::parse( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) );
		if ( null === $total || NoncePay_Decimal::cmp( $total, '0' ) <= 0 ) {
			return new WP_Error( 'noncepay_total', __( 'This order has nothing to pay.', 'noncepay-for-woocommerce' ) );
		}

		$currency  = $order->get_currency();
		$converted = NoncePay_API::convert( $coin['ticker'], $total, $currency );
		if ( is_wp_error( $converted ) ) {
			if ( 'Coin not supported' === $converted->get_error_message() ) {
				NoncePay_Logger::error( sprintf( 'Store currency %s is not supported by NoncePay conversions.', $currency ) );
				/* translators: %s: currency code */
				return new WP_Error( 'noncepay_currency', sprintf( __( 'Crypto payments are not available for prices in %s. Please choose another payment method.', 'noncepay-for-woocommerce' ), $currency ) );
			}
			return self::generic_error( $converted );
		}

		$amount = NoncePay_Decimal::parse( isset( $converted['value_coin'] ) ? $converted['value_coin'] : null );
		if ( null === $amount || NoncePay_Decimal::cmp( $amount, '0' ) <= 0 ) {
			NoncePay_Logger::error( sprintf( 'Unusable conversion for %s.', $coin['ticker'] ) );
			return self::generic_error();
		}
		if ( '' !== $coin['minimum'] && NoncePay_Decimal::cmp( $amount, $coin['minimum'] ) < 0 ) {
			return self::below_minimum_error( $coin, $coin['minimum'] );
		}
		return $amount;
	}

	/**
	 * Saves the quoted amount, its validity and its QR code.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $ticker  Ticker route.
	 * @param string   $address Payment address.
	 * @param string   $amount  Coin amount.
	 * @param int      $minutes Validity.
	 */
	private static function store_quote( $order, $ticker, $address, $amount, $minutes ) {
		$qr   = NoncePay_API::qrcode( $ticker, $address, $amount, 300 );
		$code = ! is_wp_error( $qr ) && isset( $qr['qr_code'] ) && is_string( $qr['qr_code'] ) && preg_match( '/^[A-Za-z0-9+\/=]+$/', $qr['qr_code'] ) ? $qr['qr_code'] : '';

		$order->update_meta_data( self::AMOUNT, $amount );
		$order->update_meta_data( self::QUOTED_AT, time() );
		$order->update_meta_data( self::EXPIRES_AT, time() + max( 1, (int) $minutes ) * MINUTE_IN_SECONDS );
		$order->update_meta_data( self::QR, $code );
		$order->save();
	}

	/**
	 * Array meta value.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 * @return array
	 */
	private static function list_meta( $order, $key ) {
		$value = $order->get_meta( $key );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Error for an amount below the coin minimum.
	 *
	 * @param array  $coin    Coin.
	 * @param string $minimum Minimum.
	 * @return WP_Error
	 */
	private static function below_minimum_error( $coin, $minimum ) {
		return new WP_Error(
			'noncepay_minimum',
			/* translators: 1: coin label, 2: minimum amount, 3: coin symbol */
			sprintf( __( 'The order total is below the minimum payment for %1$s (%2$s %3$s). Please choose another cryptocurrency.', 'noncepay-for-woocommerce' ), NoncePay_Coins::label( $coin ), $minimum, $coin['symbol'] )
		);
	}

	/**
	 * Generic customer-facing error.
	 *
	 * @param WP_Error|null $error API error carrying a customer-safe message for transport failures.
	 * @return WP_Error
	 */
	private static function generic_error( $error = null ) {
		if ( $error instanceof WP_Error && in_array( $error->get_error_code(), array( 'noncepay_unreachable', 'noncepay_bad_response' ), true ) ) {
			return $error;
		}
		return new WP_Error( 'noncepay_error', __( 'The crypto payment could not be prepared. Please try again or choose another payment method.', 'noncepay-for-woocommerce' ) );
	}
}
