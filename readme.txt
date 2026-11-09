=== NoncePay for WooCommerce ===
Contributors: swartz13
Tags: crypto, bitcoin, usdt, woocommerce, payment gateway
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT, USDC, Bitcoin, Ethereum, Solana and more in WooCommerce. Payments go straight to your own wallets: no account, no API key.

== Description ==

NoncePay for WooCommerce adds a crypto payment method to your store. Customers pick a coin at checkout, see the exact amount, a QR code and a unique payment address, and the order updates by itself once the payment is confirmed on the blockchain.

NoncePay is non-custodial: every payment is forwarded automatically to the wallet you entered in the settings. There is no sign-up, no API key, no monthly fee and no withdrawal step.

= Features =

* Stablecoins and major coins: USDT (Tron, BNB Smart Chain, Ethereum), USDC (Base, Polygon, Arbitrum, Solana), BTC, ETH, LTC, TRX, SOL, BNB, DOGE, and every other coin NoncePay supports.
* One wallet per coin, entered in the gateway settings. Only coins with a wallet are offered.
* Works with the classic checkout and the Cart and Checkout blocks.
* Compatible with High-Performance Order Storage (HPOS).
* Payment page with amount and address copy buttons, QR code, network warning, quote countdown and a live status (waiting, payment seen, confirmed).
* Partial payments add up: a customer may pay in several transactions. Underpayments put the order on hold, overpayments are noted on the order.
* Configurable underpayment tolerance, quote validity and paid status (processing or completed).
* Every callback is verified with NoncePay's RSA signature and processed once, even when callbacks arrive at the same time.
* Exact decimal math for crypto amounts, never floating point.

= Fees =

NoncePay deducts a 1.25% service fee (lower with volume) and the network cost from each payment before forwarding it to your wallet. The customer pays the quoted amount; you receive that amount minus the fee. See https://noncepay.app/docs/fees/.

== External services ==

This plugin relies on the NoncePay API (https://api.noncepay.app), operated by NoncePay, to create payment addresses, convert prices, draw QR codes and notify your store of payments. Without it the payment method cannot work.

What is sent, and when:

* When a customer places an order with this payment method: the order total and your store currency (to convert the price into the chosen coin), your receiving wallet address for that coin, and a callback URL on your site that contains the order number and a random per-order secret. NoncePay uses the callback URL to notify your store of payments. The payment address and amount are then sent back to draw the QR code.
* When the payment page refreshes an expired quote: the order total and store currency again.
* When the settings page or checkout is loaded and the coin list is not cached (at most once an hour): a request for the list of supported coins. No store or customer data is sent.
* When the first payment notification arrives, and again when a signature does not match (at most once an hour, and at most once every 15 minutes after a failed attempt): a request for NoncePay's public key, to verify notifications. No data is sent.
* When a payment is confirmed after its quote expired: the order total and store currency, to check the payment still covers the order at the current rate.

No customer name, email, postal address or IP address is sent to NoncePay by this plugin. NoncePay sees the requests of your web server, not of your customers' browsers.

NoncePay terms of service: https://noncepay.app/terms/
NoncePay privacy policy: https://noncepay.app/privacy/

== Installation ==

1. Install and activate WooCommerce 8.0 or later.
2. Install this plugin from Plugins > Add New, or upload the `noncepay-for-woocommerce` folder to `/wp-content/plugins/`, then activate it.
3. Go to WooCommerce > Settings > Payments > NoncePay.
4. Enable the gateway and enter your receiving wallet address for each coin you want to accept. Use an address you control on that exact network.
5. Save. The coins with a wallet are now offered at checkout.

Your store currency must be one NoncePay converts (USD, EUR, GBP, CAD, JPY, CHF, AUD and about thirty others). The settings page warns you when it is not.

== Frequently Asked Questions ==

= Do I need an account or an API key? =

No. The callback URL created for each order identifies the payment, and funds go to the wallets you configure.

= When is an order marked as paid? =

When the confirmed payments add up to at least the quoted amount minus the underpayment tolerance (0.5% by default). The order goes on hold as soon as a payment is seen on the blockchain, and to processing (or completed, if you choose) once enough is confirmed and forwarded to your wallet.

= What happens if the customer pays too little, too much or too late? =

Too little: the order stays on hold with a note giving the missing amount; the customer can send the rest to the same address. When the rest is below the coin's minimum payment, the payment page asks for the minimum instead, because smaller payments are not processed. Too much: the order is paid and the excess is noted on the order. Too late: payment addresses never expire, so a late payment is still credited, but the order is marked paid only if the coins received still cover the order total at the rate of the moment; otherwise it is held for your review. A payment arriving on an order that was already cancelled always puts it on hold for review. Customers can also refresh an expired quote from the payment page before paying.

= I changed a wallet: where do pending orders pay? =

Orders created before the change keep their payment address, which forwards to the old wallet. When a customer pays such an order again, a new address forwarding to the new wallet is created, and payments still arriving on the old address are noted on the order for review.

= Can a customer pay in several transactions? =

Yes. Each transaction is credited once, and the amounts add up.

= My site is behind a reverse proxy or CDN and callbacks are rejected =

Callbacks are signed over the exact URL NoncePay called. The plugin rebuilds it from the scheme, host and port of the callback URL saved on the order and the request path received. If your proxy changes the path, or you need another base, set it with a filter:

`add_filter( 'noncepay_callback_base_url', function () { return 'https://shop.example.com'; } );`

Callbacks are delivered to `/wp-json/noncepay/v1/callback`. Make sure security plugins or firewalls do not block that REST route for anonymous requests.

= Which databases are supported? =

Concurrent payment notifications for the same order are serialized with a MySQL/MariaDB named lock (`GET_LOCK`). It works on a single primary database server, which is what almost every WordPress host uses. Setups that send queries to several servers (read/write splitting across replicas, Galera multi-primary clusters, Vitess or PlanetScale) do not share named locks between servers; on those, make sure WordPress writes and these locks go to a single primary.

= Can I pin NoncePay's public key? =

Yes. Define `NONCEPAY_PUBLIC_KEY_PEM` in `wp-config.php` with the PEM key from https://api.noncepay.app/pubkey/. By default the key is fetched from that URL and cached.

= Where are the logs? =

WooCommerce > Status > Logs, source `noncepay-for-woocommerce`. Errors are always logged; enable "Debug log" in the settings for API calls and callbacks. Per-order secrets are never logged.

= Can I refund from WooCommerce? =

No. Payments are already in your wallet: refund the customer from your wallet, then mark the order refunded.

== Screenshots ==

1. Gateway settings: one receiving wallet per coin.
2. Coin choice in the Checkout block.
3. Payment page with QR code, amount, address and live status.
4. Order notes after a confirmed payment.

== Changelog ==

= 1.1.0 =
* TrezorPay is now NoncePay: new plugin name and slug (`noncepay-for-woocommerce`), API at https://api.noncepay.app, callback route `/wp-json/noncepay/v1/callback`, filters `noncepay_callback_base_url` and `noncepay_api_base_url`, constant `NONCEPAY_PUBLIC_KEY_PEM`.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
TrezorPay is now NoncePay. This is a new plugin slug: deactivate and delete TrezorPay for WooCommerce, install this one and enter your wallets again. Finish or cancel open crypto orders first.

= 1.0.0 =
First release.
