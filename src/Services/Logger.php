<?php
/**
 * Logger Service.
 *
 * @package WCPE\Services
 */

namespace WCPE\Services;

/**
 * Handles event logging to the database.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * Log table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wcpe_logs';
	}

	/**
	 * Log an info message.
	 *
	 * @param string $event   The event type.
	 * @param array  $context Additional context data.
	 * @param string $message Optional message.
	 * @return int|false The log ID or false on failure.
	 */
	public function info( $event, $context = array(), $message = '' ) {
		return $this->log( 'info', $event, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $event   The event type.
	 * @param array  $context Additional context data.
	 * @param string $message Optional message.
	 * @return int|false The log ID or false on failure.
	 */
	public function warning( $event, $context = array(), $message = '' ) {
		return $this->log( 'warning', $event, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $event   The event type.
	 * @param array  $context Additional context data.
	 * @param string $message Optional message.
	 * @return int|false The log ID or false on failure.
	 */
	public function error( $event, $context = array(), $message = '' ) {
		return $this->log( 'error', $event, $message, $context );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Log level (info, warning, error).
	 * @param string $event   The event type.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return int|false The log ID or false on failure.
	 */
	private function log( $level, $event, $message, $context = array() ) {
		global $wpdb;

		// Auto-generate message if not provided.
		if ( empty( $message ) ) {
			$message = $this->generate_message( $event, $context );
		}

		$result = $wpdb->insert(
			$this->table,
			array(
				'level'      => $level,
				'event'      => sanitize_key( $event ),
				'message'    => sanitize_text_field( $message ),
				'context'    => wp_json_encode( $context ),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Generate a human-readable message from event and context.
	 *
	 * @param string $event   The event type.
	 * @param array  $context Context data.
	 * @return string Generated message.
	 */
	private function generate_message( $event, $context ) {
		$messages = array(
			'rule_created'          => 'Commission rule created',
			'rule_updated'          => 'Commission rule updated',
			'rule_deleted'          => 'Commission rule deleted',
			'commission_calculated' => 'Commission calculated for order',
			'commission_approved'   => 'Commission approved',
			'commission_paid'       => 'Commission marked as paid',
			'payout_initiated'      => 'Payout initiated',
			'payout_completed'      => 'Payout completed successfully',
			'payout_failed'         => 'Payout failed',
			'stripe_transfer'       => 'Stripe transfer created',
			'stripe_error'          => 'Stripe API error',
			'webhook_sent'          => 'Webhook delivered',
			'webhook_failed'        => 'Webhook delivery failed',
			'api_request'           => 'API request received',
		);

		$message = $messages[ $event ] ?? ucfirst( str_replace( '_', ' ', $event ) );

		// Append context details if available.
		if ( ! empty( $context['order_id'] ) ) {
			$message .= sprintf( ' (Order #%d)', $context['order_id'] );
		}

		if ( ! empty( $context['amount'] ) ) {
			$message .= sprintf( ' - %s', wc_price( $context['amount'] ) );
		}

		return $message;
	}

	/**
	 * Get recent logs.
	 *
	 * @param int    $limit  Number of logs to retrieve.
	 * @param string $level  Filter by level (optional).
	 * @param string $event  Filter by event type (optional).
	 * @return array Array of log entries.
	 */
	public function get_logs( $limit = 100, $level = '', $event = '' ) {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $level ) ) {
			$where[] = 'level = %s';
			$args[]  = $level;
		}

		if ( ! empty( $event ) ) {
			$where[] = 'event = %s';
			$args[]  = $event;
		}

		$where_clause = implode( ' AND ', $where );
		$args[]       = $limit;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args
		);

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete old logs.
	 *
	 * @param int $days Delete logs older than this many days.
	 * @return int Number of deleted rows.
	 */
	public function cleanup( $days = 30 ) {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);

		return $result;
	}
}
