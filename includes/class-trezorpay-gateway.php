<?php
/**
 * WooCommerce payment gateway.
 *
 * @package TrezorPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * TrezorPay gateway.
 */
class TrezorPay_Gateway extends WC_Payment_Gateway {

	const FIATS = array( 'USD', 'EUR', 'GBP', 'CAD', 'JPY', 'AED', 'MYR', 'IDR', 'THB', 'CHF', 'SGD', 'RUB', 'ZAR', 'TRY', 'LKR', 'RON', 'BGN', 'HUF', 'CZK', 'PHP', 'PLN', 'UGX', 'MXN', 'INR', 'HKD', 'CNY', 'BRL', 'DKK', 'TWD', 'AUD', 'NGN', 'SEK', 'NOK', 'UAH', 'VND', 'COP', 'NZD' );

	/**
	 * Sets the gateway up.
	 */
	public function __construct() {
		$this->id                 = 'trezorpay';
		$this->has_fields         = true;
		$this->method_title       = __( 'TrezorPay (crypto)', 'trezorpay-for-woocommerce' );
		$this->method_description = __( 'Accept USDT, USDC, BTC, ETH, SOL and more. Each payment is forwarded straight to your own wallet: no account, no API key, no custody. TrezorPay deducts its 1.25% fee from what reaches your wallet.', 'trezorpay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'paid_status' ), 10, 3 );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable/Disable', 'trezorpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable crypto payments with TrezorPay', 'trezorpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'         => array(
				'title'       => __( 'Title', 'trezorpay-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method name shown at checkout.', 'trezorpay-for-woocommerce' ),
				'default'     => __( 'Crypto (USDT, USDC, BTC, ETH and more)', 'trezorpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Description', 'trezorpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text shown under the payment method at checkout.', 'trezorpay-for-woocommerce' ),
				'default'     => __( 'Pay with a cryptocurrency from any wallet or exchange. The payment address and exact amount are shown after you place the order.', 'trezorpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'wallets'       => array(
				'title'       => __( 'Accepted coins', 'trezorpay-for-woocommerce' ),
				'type'        => 'trezorpay_wallets',
				'description' => __( 'Enter your receiving wallet for each coin you accept. A coin is offered at checkout only when it has a wallet. Payments are forwarded to these addresses.', 'trezorpay-for-woocommerce' ),
				'default'     => array(),
			),
			'tolerance'     => array(
				'title'       => __( 'Underpayment tolerance (%)', 'trezorpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'An order is marked paid when the customer sent at least the quoted amount minus this percentage. Covers wallets that round amounts.', 'trezorpay-for-woocommerce' ),
				'default'     => '0.5',
				'desc_tip'    => true,
			),
			'quote_minutes' => array(
				'title'             => __( 'Quote validity (minutes)', 'trezorpay-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'How long the exchange rate shown to the customer is guaranteed. Payments sent later are still credited, and held for review when short.', 'trezorpay-for-woocommerce' ),
				'default'           => '30',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '5',
					'max'  => '1440',
					'step' => '1',
				),
			),
			'paid_status'   => array(
				'title'       => __( 'Order status when paid', 'trezorpay-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'processing' => _x( 'Processing', 'Order status', 'trezorpay-for-woocommerce' ),
					'completed'  => _x( 'Completed', 'Order status', 'trezorpay-for-woocommerce' ),
				),
				'default'     => 'processing',
				'description' => __( 'Orders of virtual and downloadable products are always completed.', 'trezorpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'debug'         => array(
				'title'       => __( 'Debug log', 'trezorpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API calls and callbacks', 'trezorpay-for-woocommerce' ),
				'default'     => 'no',
				/* translators: %s: log source name */
				'description' => sprintf( __( 'Logs are under WooCommerce > Status > Logs, source %s. Errors are always logged.', 'trezorpay-for-woocommerce' ), '<code>' . TrezorPay_Logger::SOURCE . '</code>' ),
			),
		);
	}

	/**
	 * Settings screen, with warnings that would stop checkout from working.
	 */
	public function admin_options() {
		if ( ! in_array( get_woocommerce_currency(), self::FIATS, true ) ) {
			/* translators: %s: currency code */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Your store currency (%s) cannot be converted to crypto by TrezorPay. Customers will not be able to pay with this gateway.', 'trezorpay-for-woocommerce' ), get_woocommerce_currency() ) ) . '</p></div>';
		}
		if ( ! $this->wallets() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add at least one wallet below: the gateway is hidden at checkout until a coin has a wallet.', 'trezorpay-for-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Renders the per-coin wallet table.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_trezorpay_wallets_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$wallets   = $this->wallets();
		$coins     = TrezorPay_Coins::all();
		$popular   = array();
		$others    = array();
		foreach ( $coins as $ticker => $coin ) {
			if ( in_array( $ticker, TrezorPay_Coins::POPULAR, true ) || isset( $wallets[ $ticker ] ) ) {
				$popular[ $ticker ] = $coin;
			} else {
				$others[ $ticker ] = $coin;
			}
		}

		$rows = static function ( $list ) use ( $field_key, $wallets ) {
			$html = '';
			foreach ( $list as $ticker => $coin ) {
				$id    = $field_key . '_' . sanitize_key( str_replace( array( '/', '.' ), '_', $ticker ) );
				$html .= '<tr><td><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $coin['symbol'] ) . '</strong> <span class="description">' . esc_html( $coin['network_label'] ) . '</span></label></td>';
				$html .= '<td><input type="text" class="input-text regular-input" style="width:100%;font-family:monospace" autocomplete="off" spellcheck="false" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field_key . '[' . $ticker . ']' ) . '" value="' . esc_attr( isset( $wallets[ $ticker ] ) ? $wallets[ $ticker ] : '' ) . '" /></td>';
				$html .= '<td>' . ( '' !== $coin['minimum'] ? esc_html( $coin['minimum'] . ' ' . $coin['symbol'] ) : '&mdash;' ) . '</td></tr>';
			}
			return $html;
		};

		$head = '<thead><tr><th>' . esc_html__( 'Coin', 'trezorpay-for-woocommerce' ) . '</th><th>' . esc_html__( 'Your receiving wallet', 'trezorpay-for-woocommerce' ) . '</th><th>' . esc_html__( 'Minimum payment', 'trezorpay-for-woocommerce' ) . '</th></tr></thead>';

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<p class="description" style="margin-bottom:8px"><?php echo esc_html( $data['description'] ); ?></p>
				<table class="widefat striped" style="max-width:860px">
					<?php echo $head . $rows( $popular ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
				</table>
				<?php if ( $others ) : ?>
					<details style="margin-top:12px;max-width:860px">
						<summary style="cursor:pointer"><?php echo esc_html( sprintf( /* translators: %d: number of coins */ _n( '%d more coin', '%d more coins', count( $others ), 'trezorpay-for-woocommerce' ), count( $others ) ) ); ?></summary>
						<table class="widefat striped" style="margin-top:8px">
							<?php echo $head . $rows( $others ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
						</table>
					</details>
				<?php endif; ?>
				<p class="description" style="margin-top:8px"><?php esc_html_e( 'Use an address you control on that exact network. One EVM address (0x...) works for every EVM network; the same applies to Tron and Solana.', 'trezorpay-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sanitizes and validates the wallet table.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return array
	 */
	public function validate_trezorpay_wallets_field( $key, $value ) {
		$coins   = TrezorPay_Coins::all();
		$wallets = array();
		if ( ! is_array( $value ) ) {
			return $wallets;
		}
		foreach ( wp_unslash( $value ) as $ticker => $address ) {
			$ticker  = sanitize_text_field( (string) $ticker );
			$address = trim( sanitize_text_field( (string) $address ) );
			if ( '' === $address || ! isset( $coins[ $ticker ] ) ) {
				continue;
			}
			if ( ! TrezorPay_Coins::looks_valid( $ticker, $address ) ) {
				/* translators: 1: coin label, 2: address */
				WC_Admin_Settings::add_error( sprintf( __( 'The %1$s wallet "%2$s" does not look like a valid address for that network and was not saved.', 'trezorpay-for-woocommerce' ), TrezorPay_Coins::label( $coins[ $ticker ] ), $address ) );
				continue;
			}
			$wallets[ $ticker ] = $address;
		}
		return $wallets;
	}

	/**
	 * Validates the tolerance as a decimal between 0 and 50.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_tolerance_field( $key, $value ) {
		$parsed = TrezorPay_Decimal::parse( str_replace( ',', '.', sanitize_text_field( wp_unslash( (string) $value ) ) ) );
		if ( null === $parsed || TrezorPay_Decimal::cmp( $parsed, '50' ) > 0 ) {
			WC_Admin_Settings::add_error( __( 'The underpayment tolerance must be a number between 0 and 50.', 'trezorpay-for-woocommerce' ) );
			return $this->get_option( 'tolerance', '0.5' );
		}
		return $parsed;
	}

	/**
	 * Validates the quote validity.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_quote_minutes_field( $key, $value ) {
		return (string) min( 1440, max( 5, absint( $value ) ) );
	}

	/**
	 * Configured wallets keyed by ticker route.
	 *
	 * @return array
	 */
	public function wallets() {
		$wallets = $this->get_option( 'wallets', array() );
		return is_array( $wallets ) ? array_filter( $wallets, 'is_string' ) : array();
	}

	/**
	 * Coins offered at checkout: configured and still supported.
	 *
	 * @return array Ticker route => label.
	 */
	public function checkout_coins() {
		$coins   = TrezorPay_Coins::all();
		$offered = array();
		foreach ( $this->wallets() as $ticker => $address ) {
			if ( isset( $coins[ $ticker ] ) && '' !== $address ) {
				$offered[ $ticker ] = TrezorPay_Coins::label( $coins[ $ticker ] );
			}
		}
		return $offered;
	}

	/**
	 * Quote validity in minutes.
	 *
	 * @return int
	 */
	public function quote_minutes() {
		return min( 1440, max( 5, absint( $this->get_option( 'quote_minutes', 30 ) ) ) );
	}

	/**
	 * Hidden when no coin has a wallet.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && (bool) $this->wallets();
	}

	/**
	 * Classic checkout fields.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		$coins = $this->checkout_coins();
		if ( ! $coins ) {
			echo '<p>' . esc_html__( 'No cryptocurrency is available right now.', 'trezorpay-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<p class="form-row form-row-wide"><label for="trezorpay_coin">' . esc_html__( 'Pay with', 'trezorpay-for-woocommerce' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'trezorpay-for-woocommerce' ) . '">*</abbr></label>';
		echo '<select id="trezorpay_coin" name="trezorpay_coin" style="width:100%">';
		foreach ( $coins as $ticker => $label ) {
			echo '<option value="' . esc_attr( $ticker ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Validates the chosen coin.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( ! isset( $this->checkout_coins()[ $this->posted_coin() ] ) ) {
			wc_add_notice( __( 'Please choose a cryptocurrency to pay with.', 'trezorpay-for-woocommerce' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Quotes the order, creates its payment address and sends the customer to the payment page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order  = wc_get_order( $order_id );
		$ticker = $this->posted_coin();
		$coins  = $this->checkout_coins();

		if ( ! $order || ! isset( $coins[ $ticker ] ) ) {
			wc_add_notice( __( 'Please choose a cryptocurrency to pay with.', 'trezorpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! TrezorPay_REST::lock( $order_id ) ) {
			wc_add_notice( __( 'Please try again in a moment.', 'trezorpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		try {
			$order = TrezorPay_Order::reload( $order_id );
			if ( TrezorPay_Order::has_payments( $order ) ) {
				if ( (string) $order->get_meta( TrezorPay_Order::TICKER ) === $ticker ) {
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}
				wc_add_notice( __( 'A payment was already detected for this order in another cryptocurrency. Please complete it with the same coin.', 'trezorpay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$started = TrezorPay_Order::start( $order, $ticker, $this->wallets()[ $ticker ], $this->quote_minutes() );
			if ( ! is_wp_error( $started ) && ! $order->has_status( 'pending' ) ) {
				$order->update_status( 'pending' );
			}
		} finally {
			TrezorPay_REST::unlock( $order_id );
		}
		if ( is_wp_error( $started ) ) {
			wc_add_notice( $started->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Applies the configured paid status to TrezorPay orders that WooCommerce would set to processing.
	 *
	 * @param string   $status   Status WooCommerce chose.
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 * @return string
	 */
	public function paid_status( $status, $order_id, $order = null ) {
		if ( 'processing' !== $status || ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() ) {
			return $status;
		}
		return 'completed' === $this->get_option( 'paid_status' ) ? 'completed' : $status;
	}

	/**
	 * Coin posted by the classic or block checkout.
	 *
	 * @return string
	 */
	private function posted_coin() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling the gateway.
		return isset( $_POST['trezorpay_coin'] ) ? sanitize_text_field( wp_unslash( $_POST['trezorpay_coin'] ) ) : '';
	}
}
