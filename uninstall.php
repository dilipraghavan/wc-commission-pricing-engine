<?php
/**
 * Uninstall script for WooCommerce Commission & Pricing Engine.
 *
 * @package WCPE
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete custom tables.
$tables = array(
	$wpdb->prefix . 'wcpe_rules',
	$wpdb->prefix . 'wcpe_commissions',
	$wpdb->prefix . 'wcpe_payouts',
	$wpdb->prefix . 'wcpe_webhooks',
	$wpdb->prefix . 'wcpe_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// Delete options.
$options = array(
	'wcpe_version',
	'wcpe_default_commission_rate',
	'wcpe_commission_trigger_status',
	'wcpe_stripe_mode',
	'wcpe_stripe_test_secret_key',
	'wcpe_stripe_test_publishable_key',
	'wcpe_stripe_live_secret_key',
	'wcpe_stripe_live_publishable_key',
	'wcpe_stripe_client_id',
	'wcpe_stripe_webhook_secret',
	'wcpe_minimum_payout',
	'wcpe_payout_fee_handling',
	'wcpe_auto_payout_schedule',
	'wcpe_api_enabled',
	'wcpe_api_key',
	'wcpe_log_retention_days',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete user meta for Stripe Connect.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_wcpe_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'wcpe_process_scheduled_payouts' );
wp_clear_scheduled_hook( 'wcpe_cleanup_old_logs' );
wp_clear_scheduled_hook( 'wcpe_retry_failed_webhooks' );

// Flush rewrite rules.
flush_rewrite_rules();
