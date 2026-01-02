<?php
/**
 * Plugin Deactivator.
 *
 * @package WCPE\Includes
 */

namespace WCPE\Includes;

/**
 * Handles plugin deactivation tasks.
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'wcpe_process_scheduled_payouts' );
		wp_clear_scheduled_hook( 'wcpe_cleanup_old_logs' );
		wp_clear_scheduled_hook( 'wcpe_retry_failed_webhooks' );

		// Clear permalinks.
		flush_rewrite_rules();
	}
}
