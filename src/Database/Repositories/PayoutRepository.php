<?php
/**
 * Payout Repository.
 *
 * @package WCPE\Database\Repositories
 */

namespace WCPE\Database\Repositories;

/**
 * Handles database operations for payouts.
 *
 * @since 1.0.0
 */
class PayoutRepository extends BaseRepository {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	protected $table = 'wcpe_payouts';

	/**
	 * Get payouts by vendor ID.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array
	 */
	public function get_by_vendor( $vendor_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE vendor_id = %d ORDER BY created_at DESC",
				$vendor_id
			)
		);
	}

	/**
	 * Get payout by Stripe transfer ID.
	 *
	 * @param string $transfer_id Stripe transfer ID.
	 * @return object|null
	 */
	public function get_by_transfer_id( $transfer_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE stripe_transfer_id = %s",
				$transfer_id
			)
		);
	}

	/**
	 * Get pending payouts.
	 *
	 * @return array
	 */
	public function get_pending() {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table_name} WHERE status = 'pending' ORDER BY created_at ASC"
		);
	}

	/**
	 * Get payouts with pagination and filtering.
	 *
	 * @param array $args Query arguments.
	 * @return array Array with 'items' and 'total' keys.
	 */
	public function get_paginated( $args = array() ) {
		$defaults = array(
			'per_page'  => 20,
			'page'      => 1,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'status'    => '',
			'vendor_id' => 0,
		);

		$args   = wp_parse_args( $args, $defaults );
		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// Build WHERE clause.
		$where = array( '1=1' );

		if ( ! empty( $args['status'] ) ) {
			$where[] = $this->wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['vendor_id'] ) ) {
			$where[] = $this->wpdb->prepare( 'vendor_id = %d', $args['vendor_id'] );
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitize orderby.
		$allowed_orderby = array( 'id', 'vendor_id', 'amount', 'status', 'created_at', 'processed_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Get items.
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE {$where_clause} 
				ORDER BY {$orderby} {$order} 
				LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args['per_page'],
				$offset
			)
		);

		// Get total count.
		$total = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'items' => $items,
			'total' => (int) $total,
		);
	}

	/**
	 * Update payout status.
	 *
	 * @param int    $id     Payout ID.
	 * @param string $status New status.
	 * @param string $error  Optional error message.
	 * @return bool
	 */
	public function update_status( $id, $status, $error = '' ) {
		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( 'completed' === $status || 'failed' === $status ) {
			$data['processed_at'] = current_time( 'mysql' );
		}

		if ( ! empty( $error ) ) {
			$data['error_message'] = $error;
		}

		return $this->update( $id, $data );
	}

	/**
	 * Create a new payout.
	 *
	 * @param array $data Payout data.
	 * @return int|false The new payout ID or false on failure.
	 */
	public function create( $data ) {
		$defaults = array(
			'vendor_id'          => 0,
			'amount'             => 0,
			'fee_amount'         => 0,
			'net_amount'         => 0,
			'currency'           => get_woocommerce_currency(),
			'status'             => 'pending',
			'stripe_transfer_id' => null,
			'error_message'      => null,
			'processed_at'       => null,
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		return parent::create( $data );
	}

	/**
	 * Get summary statistics.
	 *
	 * @return array
	 */
	public function get_summary() {
		$stats = array(
			'total_count'      => 0,
			'total_amount'     => 0,
			'pending_count'    => 0,
			'pending_amount'   => 0,
			'processing_count' => 0,
			'processing_amount'=> 0,
			'completed_count'  => 0,
			'completed_amount' => 0,
			'failed_count'     => 0,
			'failed_amount'    => 0,
		);

		$results = $this->wpdb->get_results(
			"SELECT status, COUNT(*) as count, SUM(amount) as amount 
			FROM {$this->table_name} 
			GROUP BY status"
		);

		foreach ( $results as $row ) {
			$stats['total_count']  += $row->count;
			$stats['total_amount'] += $row->amount;

			switch ( $row->status ) {
				case 'pending':
					$stats['pending_count']  = $row->count;
					$stats['pending_amount'] = $row->amount;
					break;
				case 'processing':
					$stats['processing_count']  = $row->count;
					$stats['processing_amount'] = $row->amount;
					break;
				case 'completed':
					$stats['completed_count']  = $row->count;
					$stats['completed_amount'] = $row->amount;
					break;
				case 'failed':
					$stats['failed_count']  = $row->count;
					$stats['failed_amount'] = $row->amount;
					break;
			}
		}

		return $stats;
	}

	/**
	 * Get vendors with pending payouts above minimum.
	 *
	 * @param float $minimum_amount Minimum payout amount.
	 * @return array
	 */
	public function get_vendors_ready_for_payout( $minimum_amount = 0 ) {
		global $wpdb;

		$commission_table = $wpdb->prefix . 'wcpe_commissions';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vendor_id, SUM(commission_amount) as total_amount, COUNT(*) as commission_count
				FROM {$commission_table}
				WHERE status = 'approved'
				GROUP BY vendor_id
				HAVING total_amount >= %f
				ORDER BY total_amount DESC",
				$minimum_amount
			)
		);
	}
}
