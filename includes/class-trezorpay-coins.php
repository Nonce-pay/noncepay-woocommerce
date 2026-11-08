<?php
/**
 * Supported coins, read from the API's /info/ route.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coin catalogue.
 */
final class TrezorPay_Coins {

	const TRANSIENT = 'trezorpay_info';

	const FALLBACK_OPTION = 'trezorpay_info_cache';

	const POPULAR = array(
		'trc20/usdt',
		'bep20/usdt',
		'erc20/usdt',
		'base/usdc',
		'polygon/usdc',
		'arbitrum/usdc',
		'sol/usdc',
		'btc',
		'eth',
		'ltc',
		'trx',
		'sol/sol',
		'bep20/bnb',
		'doge',
	);

	/**
	 * Returns every supported coin keyed by ticker route, popular coins first.
	 *
	 * Each coin: ticker, symbol, network, network_label, minimum (decimal string or '').
	 *
	 * @return array
	 */
	public static function all() {
		$coins = get_transient( self::TRANSIENT );
		if ( false === $coins ) {
			$coins = self::fetch();
		}
		if ( ! is_array( $coins ) || ! $coins ) {
			$fallback = get_option( self::FALLBACK_OPTION );
			$coins    = is_array( $fallback ) && $fallback ? $fallback : self::defaults();
		}

		foreach ( $coins as $ticker => $coin ) {
			$coins[ $ticker ]['network_label'] = self::network_label( $coin['network'] );
		}

		$sorted = array();
		foreach ( self::POPULAR as $ticker ) {
			if ( isset( $coins[ $ticker ] ) ) {
				$sorted[ $ticker ] = $coins[ $ticker ];
			}
		}
		return $sorted + $coins;
	}

	/**
	 * Returns one coin, or null.
	 *
	 * @param string $ticker Ticker route.
	 * @return array|null
	 */
	public static function get( $ticker ) {
		$coins = self::all();
		return isset( $coins[ $ticker ] ) ? $coins[ $ticker ] : null;
	}

	/**
	 * Underscore form used in callbacks (trc20_usdt).
	 *
	 * @param string $ticker Ticker route.
	 * @return string
	 */
	public static function callback_coin( $ticker ) {
		return str_replace( '/', '_', $ticker );
	}

	/**
	 * Human label, such as "USDT (Tron TRC-20)".
	 *
	 * @param array $coin Coin.
	 * @return string
	 */
	public static function label( $coin ) {
		/* translators: 1: coin symbol, 2: network name */
		return sprintf( __( '%1$s (%2$s)', 'trezorpay-for-woocommerce' ), $coin['symbol'], $coin['network_label'] );
	}

	/**
	 * Whether a wallet address looks valid for a coin's chain. The API validates it for real at checkout.
	 *
	 * @param string $ticker  Ticker route.
	 * @param string $address Address.
	 * @return bool
	 */
	public static function looks_valid( $ticker, $address ) {
		switch ( self::family( $ticker ) ) {
			case 'evm':
				return (bool) preg_match( '/^0x[0-9a-fA-F]{40}$/', $address );
			case 'tron':
				return (bool) preg_match( '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address );
			case 'solana':
				return (bool) preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address );
			default:
				return (bool) preg_match( '/^(bitcoincash:)?[0-9A-Za-z]{25,90}$/', $address );
		}
	}

	/**
	 * Address family of a ticker.
	 *
	 * @param string $ticker Ticker route.
	 * @return string evm, tron, solana or utxo.
	 */
	public static function family( $ticker ) {
		$network = strtok( $ticker, '/' );
		if ( in_array( $network, array( 'trx', 'trc20' ), true ) ) {
			return 'tron';
		}
		if ( 'sol' === $network ) {
			return 'solana';
		}
		if ( in_array( $network, array( 'btc', 'ltc', 'bch', 'doge' ), true ) ) {
			return 'utxo';
		}
		return 'evm';
	}

	/**
	 * Fetches /info/ and caches the reduced catalogue.
	 *
	 * @return array
	 */
	private static function fetch() {
		$info = TrezorPay_API::info();
		if ( is_wp_error( $info ) ) {
			set_transient( self::TRANSIENT, array(), 5 * MINUTE_IN_SECONDS );
			return array();
		}

		$coins = array();
		foreach ( $info as $network => $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $network ) || ! preg_match( '/^[a-z0-9-]+$/', $network ) ) {
				continue;
			}
			if ( isset( $entry['minimum_transaction_coin'] ) ) {
				$coins[ $network ] = self::coin( $network, $network, strtoupper( $network ), $entry );
				continue;
			}
			foreach ( $entry as $token => $data ) {
				if ( is_array( $data ) && is_string( $token ) && isset( $data['minimum_transaction_coin'] ) && preg_match( '/^[a-z0-9.]+$/', $token ) ) {
					$coins[ $network . '/' . $token ] = self::coin( $network . '/' . $token, $network, strtoupper( $token ), $data );
				}
			}
		}

		if ( $coins ) {
			set_transient( self::TRANSIENT, $coins, HOUR_IN_SECONDS );
			update_option( self::FALLBACK_OPTION, $coins, false );
		}
		return $coins;
	}

	/**
	 * Builds a coin entry.
	 *
	 * @param string $ticker  Ticker route.
	 * @param string $network Network key.
	 * @param string $symbol  Display symbol.
	 * @param array  $data    /info/ entry.
	 * @return array
	 */
	private static function coin( $ticker, $network, $symbol, $data ) {
		$minimum = TrezorPay_Decimal::parse( isset( $data['minimum_transaction_coin'] ) ? $data['minimum_transaction_coin'] : null );
		return array(
			'ticker'        => $ticker,
			'symbol'        => sanitize_text_field( $symbol ),
			'network'       => $network,
			'minimum'       => null === $minimum ? '' : $minimum,
		);
	}

	/**
	 * Built-in list used when /info/ has never been reachable.
	 *
	 * @return array
	 */
	private static function defaults() {
		$symbols = array(
			'trc20/usdt'    => 'USDT',
			'bep20/usdt'    => 'USDT',
			'erc20/usdt'    => 'USDT',
			'base/usdc'     => 'USDC',
			'polygon/usdc'  => 'USDC',
			'arbitrum/usdc' => 'USDC',
			'sol/usdc'      => 'USDC',
			'btc'           => 'BTC',
			'eth'           => 'ETH',
			'ltc'           => 'LTC',
			'trx'           => 'TRX',
			'sol/sol'       => 'SOL',
			'bep20/bnb'     => 'BNB',
			'doge'          => 'DOGE',
		);
		$coins   = array();
		foreach ( $symbols as $ticker => $symbol ) {
			$network          = strtok( $ticker, '/' );
			$coins[ $ticker ] = array(
				'ticker'        => $ticker,
				'symbol'        => $symbol,
				'network'       => $network,
				'minimum'       => '',
			);
		}
		return $coins;
	}

	/**
	 * Human network name.
	 *
	 * @param string $network Network key.
	 * @return string
	 */
	private static function network_label( $network ) {
		$labels = array(
			'btc'      => __( 'Bitcoin', 'trezorpay-for-woocommerce' ),
			'eth'      => __( 'Ethereum', 'trezorpay-for-woocommerce' ),
			'erc20'    => __( 'Ethereum ERC-20', 'trezorpay-for-woocommerce' ),
			'bep20'    => __( 'BNB Smart Chain BEP-20', 'trezorpay-for-woocommerce' ),
			'trx'      => __( 'Tron', 'trezorpay-for-woocommerce' ),
			'trc20'    => __( 'Tron TRC-20', 'trezorpay-for-woocommerce' ),
			'polygon'  => __( 'Polygon', 'trezorpay-for-woocommerce' ),
			'base'     => __( 'Base', 'trezorpay-for-woocommerce' ),
			'arbitrum' => __( 'Arbitrum One', 'trezorpay-for-woocommerce' ),
			'optimism' => __( 'Optimism', 'trezorpay-for-woocommerce' ),
			'avax-c'   => __( 'Avalanche C-Chain', 'trezorpay-for-woocommerce' ),
			'sol'      => __( 'Solana', 'trezorpay-for-woocommerce' ),
			'ltc'      => __( 'Litecoin', 'trezorpay-for-woocommerce' ),
			'bch'      => __( 'Bitcoin Cash', 'trezorpay-for-woocommerce' ),
			'doge'     => __( 'Dogecoin', 'trezorpay-for-woocommerce' ),
		);
		return isset( $labels[ $network ] ) ? $labels[ $network ] : ucfirst( $network );
	}
}
