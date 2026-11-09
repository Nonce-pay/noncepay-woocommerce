<?php
/**
 * TrezorPay data stored on a WooCommerce order, quoting and callback bookkeeping.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order helper.
 */
final class TrezorPay_Order {

	const TICKER        = '_trezorpay_ticker';
	const ADDRESS       = '_trezorpay_address';
	const AMOUNT        = '_trezorpay_amount';
	const MINIMUM       = '_trezorpay_minimum';
	const QUOTED_AT     = '_trezorpay_quoted_at';
	const EXPIRES_AT    = '_trezorpay_expires_at';
	const TOKEN         = '_trezorpay_token';
	const RECEIVED      = '_trezorpay_received';
	const CONFIRMED     = '_trezorpay_confirmed_uuids';
	const SEEN          = '_trezorpay_seen_uuids';
	const SEEN_AT       = '_trezorpay_seen_at';
	const PREVIOUS      = '_trezorpay_previous_addresses';
	const OLD_TOKENS    = '_trezorpay_old_tokens';
	const QR            = '_trezorpay_qr';
	const LABEL         = '_trezorpay_label';
	const SYMBOL        = '_trezorpay_symbol';
	const NETWORK       = '_trezorpay_network_label';
	const BASE_URL      = '_trezorpay_callback_base';
	const CANCELLED     = '_trezorpay_was_cancelled';

	/**
	 * Whether the order is paid with TrezorPay and has a payment address.
	 *
	 * @param WC_Order|false|null $order Order.
	 * @return bool
	 */
	public static function is_ours( $order ) {
		return $order instanceof WC_Order && 'trezorpay' === $order->get_payment_method() && '' !== (string) $order->get_meta( self::ADDRESS );
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
		$coin = TrezorPay_Coins::get( $ticker );
		if ( ! $coin ) {
			return new WP_Error( 'trezorpay_coin', __( 'Please choose a supported cryptocurrency.', 'trezorpay-for-woocommerce' ) );
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
		if ( is_wp_error( $created ) && 'trezorpay_wallet_changed' === $created->get_error_code() ) {
			// The API keeps the first destination of a callback URL forever: a new wallet needs a new callback URL.
			$old_tokens[] = $token;
			$token        = bin2hex( random_bytes( 16 ) );
			$callback     = self::callback_url( $order, $ticker, $token );
			$created      = self::create_address( $ticker, $callback, $wallet );
			if ( is_wp_error( $created ) && 'trezorpay_wallet_changed' === $created->get_error_code() ) {
				TrezorPay_Logger::error( sprintf( 'TrezorPay forwards %s to another wallet than the configured one, even under a new callback URL: payment refused.', $ticker ) );
			}
		}
		if ( is_wp_error( $created ) ) {
			return 'trezorpay_wallet' === $created->get_error_code() ? $created : self::generic_error( $created );
		}
		$address = $created['address_in'];

		$minimum = TrezorPay_Decimal::parse( isset( $created['minimum_transaction_coin'] ) ? $created['minimum_transaction_coin'] : null );
		if ( null === $minimum && '' !== $coin['minimum'] ) {
			$minimum = $coin['minimum'];
		}
		if ( null === $minimum ) {
			TrezorPay_Logger::error( sprintf( 'Unknown minimum for %s: payment refused.', $ticker ) );
			return self::generic_error();
		}
		if ( TrezorPay_Decimal::cmp( $amount, $minimum ) < 0 ) {
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
		$order->update_meta_data( self::LABEL, TrezorPay_Coins::label( $coin ) );
		$order->update_meta_data( self::SYMBOL, $coin['symbol'] );
		$order->update_meta_data( self::NETWORK, $coin['network_label'] );
		$order->update_meta_data( self::BASE_URL, self::url_base( $callback ) );
		if ( '' === (string) $order->get_meta( self::RECEIVED ) ) {
			$order->update_meta_data( self::RECEIVED, '0' );
		}
		self::store_quote( $order, $ticker, $address, $amount, $minutes );

		/* translators: 1: amount, 2: coin label, 3: payment address */
		$order->add_order_note( sprintf( __( 'TrezorPay: awaiting %1$s %2$s on %3$s.', 'trezorpay-for-woocommerce' ), $amount, TrezorPay_Coins::label( $coin ), $address ) );
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
		$coin   = TrezorPay_Coins::get( $ticker );
		if ( ! $coin ) {
			return self::generic_error();
		}
		$amount = self::convert( $order, $coin );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}
		$minimum = (string) $order->get_meta( self::MINIMUM );
		if ( '' !== $minimum && TrezorPay_Decimal::cmp( $amount, $minimum ) < 0 ) {
			return self::below_minimum_error( $coin, $minimum );
		}
		self::store_quote( $order, $ticker, (string) $order->get_meta( self::ADDRESS ), $amount, $minutes );
		/* translators: 1: amount, 2: coin label */
		$order->add_order_note( sprintf( __( 'TrezorPay: quote refreshed to %1$s %2$s.', 'trezorpay-for-woocommerce' ), $amount, self::label( $order ) ) );
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
			TrezorPay_Logger::error( sprintf( 'Order %d: callback without uuid or pending flag ignored.', $order->get_id() ) );
			return;
		}

		$ticker   = (string) $order->get_meta( self::TICKER );
		$previous = self::list_meta( $order, self::PREVIOUS );

		if ( $address !== (string) $order->get_meta( self::ADDRESS ) ) {
			if ( isset( $previous[ $address ] ) ) {
				self::apply_previous_address_payment( $order, $uuid, $address, $params, $pending );
			} else {
				TrezorPay_Logger::debug( sprintf( 'Order %d: callback for an unknown address ignored.', $order->get_id() ) );
			}
			return;
		}

		if ( TrezorPay_Coins::callback_coin( $ticker ) !== $coin ) {
			TrezorPay_Logger::error( sprintf( 'Order %d: callback for coin %s on a %s address ignored.', $order->get_id(), $coin, $ticker ) );
			return;
		}

		$value   = TrezorPay_Decimal::parse( isset( $params['value_coin'] ) ? $params['value_coin'] : null );
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
				__( 'TrezorPay: payment of %1$s %2$s seen on chain (txid_in %3$s), waiting for confirmations.', 'trezorpay-for-woocommerce' ),
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
			TrezorPay_Logger::debug( sprintf( 'Order %d: duplicate confirmed callback ignored.', $order->get_id() ) );
			return;
		}

		if ( null === $value ) {
			TrezorPay_Logger::error( sprintf( 'Order %d: confirmed callback with an unreadable amount, not credited.', $order->get_id() ) );
			$order->add_order_note( __( 'TrezorPay: a confirmed payment arrived with an unreadable amount and was not credited. Check your wallet and review the order manually.', 'trezorpay-for-woocommerce' ) );
			return;
		}

		$txid_out  = $field( 'txid_out', '/^[A-Za-z0-9]{1,128}$/' );
		$forwarded = TrezorPay_Decimal::parse( isset( $params['value_forwarded_coin'] ) ? $params['value_forwarded_coin'] : null );
		if ( '' === $txid_out || null === $forwarded ) {
			TrezorPay_Logger::error( sprintf( 'Order %d: confirmed callback without a forwarding transaction, not credited.', $order->get_id() ) );
			$order->save();
			/* translators: 1: amount, 2: coin label, 3: transaction id */
			$order->add_order_note( sprintf( __( 'TrezorPay: a confirmed payment of %1$s %2$s (txid_in %3$s) arrived without a forwarding transaction and was not credited. Check your wallet and review the order manually.', 'trezorpay-for-woocommerce' ), $value, $label, $txid_in ) );
			return;
		}
		$received  = TrezorPay_Decimal::add( self::received( $order ), $value );
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
				__( 'TrezorPay: payment of %1$s %2$s confirmed (txid_in %3$s). %4$s forwarded to your wallet (txid_out %5$s). Received so far: %6$s of %7$s.', 'trezorpay-for-woocommerce' ),
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
			$order->add_order_note( __( 'TrezorPay: an extra payment arrived on an order that is already paid. Refund the customer from your wallet if needed.', 'trezorpay-for-woocommerce' ) );
			return;
		}

		if ( null === TrezorPay_Decimal::parse( $expected ) ) {
			$order->update_status( 'on-hold', __( 'TrezorPay: the quoted amount is missing, review the order manually.', 'trezorpay-for-woocommerce' ) );
			return;
		}

		$tolerance = TrezorPay_Decimal::parse( isset( $settings['tolerance'] ) ? (string) $settings['tolerance'] : '' );
		if ( null === $tolerance || TrezorPay_Decimal::cmp( $tolerance, '100' ) >= 0 ) {
			$tolerance = '0';
		}

		$expires = (int) $order->get_meta( self::EXPIRES_AT );
		$late    = false;
		foreach ( $done as $uuid ) {
			if ( isset( $seen_at[ $uuid ] ) && (int) $seen_at[ $uuid ] > $expires ) {
				$late = true;
			}
		}

		if ( TrezorPay_Decimal::covers( $received, $expected, $tolerance ) ) {
			if ( TrezorPay_Decimal::cmp( $received, $expected ) > 0 ) {
				/* translators: 1: amount over, 2: coin label */
				$order->add_order_note( sprintf( __( 'TrezorPay: overpaid by %1$s %2$s.', 'trezorpay-for-woocommerce' ), TrezorPay_Decimal::sub_floor( $received, $expected ), $label ) );
			}
			if ( 'yes' === $order->get_meta( self::CANCELLED ) ) {
				self::hold( $order, __( 'TrezorPay: the quoted amount was received, but the order had been cancelled before the payment arrived. Review it manually: restore the order or refund the customer from your wallet.', 'trezorpay-for-woocommerce' ) );
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
					self::hold( $order, __( 'TrezorPay: part of the payment was sent after the quote expired and the current rate could not be checked. Review it manually.', 'trezorpay-for-woocommerce' ) );
					return;
				}
				if ( ! TrezorPay_Decimal::covers( $received, $now, $tolerance ) ) {
					/* translators: 1: amount received, 2: coin label, 3: amount the order total is worth now */
					self::hold( $order, sprintf( __( 'TrezorPay: part of the payment was sent after the quote expired and is now worth less than the order total (%1$s %2$s received, %3$s needed at the current rate). Review it manually.', 'trezorpay-for-woocommerce' ), $received, $label, $now ) );
					return;
				}
				$order->add_order_note( __( 'TrezorPay: part of the payment was sent after the quote expired. It still covers the order total at the current rate, so the order is marked paid.', 'trezorpay-for-woocommerce' ) );
			}
			$order->payment_complete( $txid_in );
			return;
		}

		$missing = TrezorPay_Decimal::sub_floor( $expected, $received );
		if ( $late ) {
			/* translators: 1: amount received, 2: amount expected, 3: coin label, 4: amount missing */
			$note = sprintf( __( 'TrezorPay: payment received after the quote expired and below the quoted amount (%1$s of %2$s %3$s, %4$s missing). Review it manually.', 'trezorpay-for-woocommerce' ), $received, $expected, $label, $missing );
		} else {
			/* translators: 1: amount received, 2: amount expected, 3: coin label, 4: amount missing */
			$note = sprintf( __( 'TrezorPay: underpaid, %1$s of %2$s %3$s received (%4$s missing). The order stays on hold until the rest arrives.', 'trezorpay-for-woocommerce' ), $received, $expected, $label, $missing );
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
		$value    = TrezorPay_Decimal::parse( isset( $params['value_coin'] ) ? $params['value_coin'] : null );
		$note     = sprintf(
			/* translators: 1: amount, 2: coin label, 3: address */
			__( 'TrezorPay: a payment of %1$s %2$s arrived on %3$s, an address quoted earlier for this order. It is not credited automatically: review it manually.', 'trezorpay-for-woocommerce' ),
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
		} elseif ( TrezorPay_Decimal::cmp( $received, '0' ) > 0 ) {
			$state = 'partial';
		} elseif ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			$state = 'cancelled';
		} elseif ( time() > (int) $order->get_meta( self::EXPIRES_AT ) ) {
			$state = 'expired';
		} else {
			$state = 'waiting';
		}

		$remaining = null === TrezorPay_Decimal::parse( $expected ) ? '' : TrezorPay_Decimal::sub_floor( $expected, $received );
		$minimum   = TrezorPay_Decimal::parse( (string) $order->get_meta( self::MINIMUM ) );
		$to_send   = 'partial' === $state ? $remaining : $expected;
		$below     = 'partial' === $state && null !== $minimum && '' !== $remaining && TrezorPay_Decimal::cmp( $remaining, '0' ) > 0 && TrezorPay_Decimal::cmp( $remaining, $minimum ) < 0;
		if ( $below ) {
			// TrezorPay ignores payments below the coin minimum: a smaller top-up would never reach the merchant.
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
		$received = TrezorPay_Decimal::parse( (string) $order->get_meta( self::RECEIVED ) );
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
	 * Scheme, host and port of a URL as TrezorPay normalizes it when calling back (lowercase host, no default port).
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
	 * @return array|WP_Error The create response; error code trezorpay_wallet_changed when it forwards elsewhere.
	 */
	private static function create_address( $ticker, $callback, $wallet ) {
		$created = TrezorPay_API::create( $ticker, $callback, $wallet );
		if ( is_wp_error( $created ) ) {
			if ( 'You must provide a valid address' === $created->get_error_message() ) {
				TrezorPay_Logger::error( sprintf( 'The wallet configured for %s was rejected by TrezorPay. Fix it in WooCommerce > Settings > Payments > TrezorPay.', $ticker ) );
				return new WP_Error( 'trezorpay_wallet', __( 'This cryptocurrency is temporarily unavailable. Please choose another one.', 'trezorpay-for-woocommerce' ) );
			}
			return $created;
		}

		$address = isset( $created['address_in'] ) && is_string( $created['address_in'] ) ? $created['address_in'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9:]{20,128}$/', $address ) ) {
			TrezorPay_Logger::error( sprintf( 'TrezorPay returned no usable address for %s.', $ticker ) );
			return new WP_Error( 'trezorpay_error', 'No usable address' );
		}

		$out = isset( $created['address_out'] ) && is_string( $created['address_out'] ) ? $created['address_out'] : '';
		if ( self::canonical_wallet( $ticker, $out ) !== self::canonical_wallet( $ticker, $wallet ) ) {
			TrezorPay_Logger::debug( sprintf( 'Address for %s forwards to another wallet than the configured one: creating a new one.', $ticker ) );
			return new WP_Error( 'trezorpay_wallet_changed', 'Wallet changed' );
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
		if ( 'evm' === TrezorPay_Coins::family( $ticker ) ) {
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
				'ticker' => TrezorPay_Coins::callback_coin( $ticker ),
				'token'  => $token,
			),
			rest_url( 'trezorpay/v1/callback' )
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
		$total = TrezorPay_Decimal::parse( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) );
		if ( null === $total || TrezorPay_Decimal::cmp( $total, '0' ) <= 0 ) {
			return new WP_Error( 'trezorpay_total', __( 'This order has nothing to pay.', 'trezorpay-for-woocommerce' ) );
		}

		$currency  = $order->get_currency();
		$converted = TrezorPay_API::convert( $coin['ticker'], $total, $currency );
		if ( is_wp_error( $converted ) ) {
			if ( 'Coin not supported' === $converted->get_error_message() ) {
				TrezorPay_Logger::error( sprintf( 'Store currency %s is not supported by TrezorPay conversions.', $currency ) );
				/* translators: %s: currency code */
				return new WP_Error( 'trezorpay_currency', sprintf( __( 'Crypto payments are not available for prices in %s. Please choose another payment method.', 'trezorpay-for-woocommerce' ), $currency ) );
			}
			return self::generic_error( $converted );
		}

		$amount = TrezorPay_Decimal::parse( isset( $converted['value_coin'] ) ? $converted['value_coin'] : null );
		if ( null === $amount || TrezorPay_Decimal::cmp( $amount, '0' ) <= 0 ) {
			TrezorPay_Logger::error( sprintf( 'Unusable conversion for %s.', $coin['ticker'] ) );
			return self::generic_error();
		}
		if ( '' !== $coin['minimum'] && TrezorPay_Decimal::cmp( $amount, $coin['minimum'] ) < 0 ) {
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
		$qr   = TrezorPay_API::qrcode( $ticker, $address, $amount, 300 );
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
			'trezorpay_minimum',
			/* translators: 1: coin label, 2: minimum amount, 3: coin symbol */
			sprintf( __( 'The order total is below the minimum payment for %1$s (%2$s %3$s). Please choose another cryptocurrency.', 'trezorpay-for-woocommerce' ), TrezorPay_Coins::label( $coin ), $minimum, $coin['symbol'] )
		);
	}

	/**
	 * Generic customer-facing error.
	 *
	 * @param WP_Error|null $error API error carrying a customer-safe message for transport failures.
	 * @return WP_Error
	 */
	private static function generic_error( $error = null ) {
		if ( $error instanceof WP_Error && in_array( $error->get_error_code(), array( 'trezorpay_unreachable', 'trezorpay_bad_response' ), true ) ) {
			return $error;
		}
		return new WP_Error( 'trezorpay_error', __( 'The crypto payment could not be prepared. Please try again or choose another payment method.', 'trezorpay-for-woocommerce' ) );
	}
}
