<?php
/**
 * Removes the plugin's settings and caches. Order data is kept: it is part of your accounting.
 *
 * @package TrezorPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_trezorpay_settings' );
delete_option( 'trezorpay_pubkey' );
delete_option( 'trezorpay_info_cache' );
delete_transient( 'trezorpay_info' );
delete_transient( 'trezorpay_pubkey_retry' );
