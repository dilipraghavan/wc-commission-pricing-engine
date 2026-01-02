<?php
/**
 * Plugin Activator.
 *
 * @package WCPE\Includes
 */

namespace WCPE\Includes;

/**
 * Handles plugin activation tasks.
 *
 * @since 1.0.0
 */
class Activator {

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();

		// Store version for future migrations.
		update_option( 'wcpe_version', WCPE_VERSION );

		// Clear permalinks.
		flush_rewrite_rules();
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Rules table.
		$table_rules = $wpdb->prefix . 'wcpe_rules';
		$sql_rules   = "CREATE TABLE {$table_rules} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			rule_type ENUM('global','category','vendor','product') NOT NULL DEFAULT 'global',
			calculation_method ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
			value DECIMAL(10,4) NOT NULL DEFAULT 0,
			target_id BIGINT(20) UNSIGNED DEFAULT NULL,
			priority INT(11) NOT NULL DEFAULT 10,
			status ENUM('active','inactive') NOT NULL DEFAULT 'active',
			start_date DATETIME DEFAULT NULL,
			end_date DATETIME DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_type_status (rule_type, status),
			KEY idx_target (target_id),
			KEY idx_priority (priority)
		) {$charset_collate};";

		// Commissions table.
		$table_commissions = $wpdb->prefix . 'wcpe_commissions';
		$sql_commissions   = "CREATE TABLE {$table_commissions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			order_item_id BIGINT(20) UNSIGNED NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			vendor_id BIGINT(20) UNSIGNED NOT NULL,
			rule_id BIGINT(20) UNSIGNED DEFAULT NULL,
			order_total DECIMAL(10,2) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			commission_rate DECIMAL(10,4) DEFAULT NULL,
			status ENUM('pending','approved','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
			payout_id BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_order (order_id),
			KEY idx_vendor_status (vendor_id, status),
			KEY idx_payout (payout_id),
			KEY idx_product (product_id)
		) {$charset_collate};";

		// Payouts table.
		$table_payouts = $wpdb->prefix . 'wcpe_payouts';
		$sql_payouts   = "CREATE TABLE {$table_payouts} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			vendor_id BIGINT(20) UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			net_amount DECIMAL(10,2) NOT NULL,
			currency VARCHAR(3) NOT NULL DEFAULT 'USD',
			status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
			stripe_transfer_id VARCHAR(255) DEFAULT NULL,
			error_message TEXT DEFAULT NULL,
			processed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_vendor (vendor_id),
			KEY idx_status (status),
			KEY idx_stripe_transfer (stripe_transfer_id)
		) {$charset_collate};";

		// Webhooks table.
		$table_webhooks = $wpdb->prefix . 'wcpe_webhooks';
		$sql_webhooks   = "CREATE TABLE {$table_webhooks} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			url VARCHAR(2048) NOT NULL,
			secret VARCHAR(255) NOT NULL,
			events TEXT NOT NULL,
			status ENUM('active','inactive') NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_status (status)
		) {$charset_collate};";

		// Logs table.
		$table_logs = $wpdb->prefix . 'wcpe_logs';
		$sql_logs   = "CREATE TABLE {$table_logs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
			event VARCHAR(100) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_level_event (level, event),
			KEY idx_created (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_rules );
		dbDelta( $sql_commissions );
		dbDelta( $sql_payouts );
		dbDelta( $sql_webhooks );
		dbDelta( $sql_logs );
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options() {
		$defaults = array(
			'wcpe_default_commission_rate'  => 10,
			'wcpe_commission_trigger_status' => 'completed',
			'wcpe_stripe_mode'              => 'test',
			'wcpe_minimum_payout'           => 50,
			'wcpe_payout_fee_handling'      => 'platform',
			'wcpe_auto_payout_schedule'     => 'disabled',
			'wcpe_api_enabled'              => 'yes',
			'wcpe_log_retention_days'       => 30,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
