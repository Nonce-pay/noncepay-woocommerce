<?php
/**
 * Removes the plugin's settings and caches. Order data is kept: it is part of your accounting.
 *
 * @package NoncePay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_noncepay_settings' );
delete_option( 'noncepay_pubkey' );
delete_option( 'noncepay_info_cache' );
delete_transient( 'noncepay_info' );
delete_transient( 'noncepay_pubkey_retry' );
