<?php
/**
 * Commission Repository.
 *
 * @package WCPE\Database\Repositories
 */

namespace WCPE\Database\Repositories;

/**
 * Handles database operations for commissions.
 *
 * @since 1.0.0
 */
class CommissionRepository extends BaseRepository {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	protected $table = 'wcpe_commissions';

	/**
	 * Get commissions by order ID.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function get_by_order( $order_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE order_id = %d ORDER BY id ASC",
				$order_id
			)
		);
	}

	/**
	 * Get commissions by vendor ID.
	 *
	 * @param int    $vendor_id Vendor ID.
	 * @param string $status    Optional status filter.
	 * @return array
	 */
	public function get_by_vendor( $vendor_id, $status = '' ) {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE vendor_id = %d",
			$vendor_id
		);

		if ( ! empty( $status ) ) {
			$sql .= $this->wpdb->prepare( ' AND status = %s', $status );
		}

		$sql .= ' ORDER BY created_at DESC';

		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Get pending commissions for a vendor.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array
	 */
	public function get_pending_for_vendor( $vendor_id ) {
		return $this->get_by_vendor( $vendor_id, 'pending' );
	}

	/**
	 * Get approved commissions for a vendor (ready for payout).
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array
	 */
	public function get_approved_for_vendor( $vendor_id ) {
		return $this->get_by_vendor( $vendor_id, 'approved' );
	}

	/**
	 * Get total approved amount for a vendor.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return float
	 */
	public function get_approved_total_for_vendor( $vendor_id ) {
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT SUM(commission_amount) FROM {$this->table_name} 
				WHERE vendor_id = %d AND status = 'approved'",
				$vendor_id
			)
		);

		return floatval( $result );
	}

	/**
	 * Check if commission already exists for an order item.
	 *
	 * @param int $order_id      Order ID.
	 * @param int $order_item_id Order item ID.
	 * @return bool
	 */
	public function exists_for_order_item( $order_id, $order_item_id ) {
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table_name} WHERE order_id = %d AND order_item_id = %d",
				$order_id,
				$order_item_id
			)
		);

		return null !== $result;
	}

	/**
	 * Update commission status.
	 *
	 * @param int    $id     Commission ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public function update_status( $id, $status ) {
		return $this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * Bulk update status.
	 *
	 * @param array  $ids    Commission IDs.
	 * @param string $status New status.
	 * @return int Number of updated rows.
	 */
	public function bulk_update_status( $ids, $status ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$query = $this->wpdb->prepare(
			"UPDATE {$this->table_name} SET status = %s, updated_at = %s WHERE id IN ({$ids_placeholder})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(
				array( $status, current_time( 'mysql' ) ),
				$ids
			)
		);

		return $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Mark commissions as paid by payout ID.
	 *
	 * @param int $payout_id Payout ID.
	 * @param array $commission_ids Commission IDs.
	 * @return bool
	 */
	public function mark_as_paid( $payout_id, $commission_ids ) {
		if ( empty( $commission_ids ) ) {
			return false;
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $commission_ids ), '%d' ) );

		$query = $this->wpdb->prepare(
			"UPDATE {$this->table_name} SET status = 'paid', payout_id = %d, updated_at = %s WHERE id IN ({$ids_placeholder})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge(
				array( $payout_id, current_time( 'mysql' ) ),
				$commission_ids
			)
		);

		return false !== $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get commissions with pagination and filtering.
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
			'search'    => '',
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

		if ( ! empty( $args['search'] ) ) {
			$where[] = $this->wpdb->prepare( 'order_id = %d', absint( $args['search'] ) );
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitize orderby.
		$allowed_orderby = array( 'id', 'order_id', 'vendor_id', 'commission_amount', 'status', 'created_at' );
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
			'approved_count'   => 0,
			'approved_amount'  => 0,
			'paid_count'       => 0,
			'paid_amount'      => 0,
		);

		$results = $this->wpdb->get_results(
			"SELECT status, COUNT(*) as count, SUM(commission_amount) as amount 
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
				case 'approved':
					$stats['approved_count']  = $row->count;
					$stats['approved_amount'] = $row->amount;
					break;
				case 'paid':
					$stats['paid_count']  = $row->count;
					$stats['paid_amount'] = $row->amount;
					break;
			}
		}

		return $stats;
	}

	/**
	 * Get all commissions for export.
	 *
	 * @param array $args Filter arguments.
	 * @return array
	 */
	public function get_for_export( $args = array() ) {
		$where = array( '1=1' );

		if ( ! empty( $args['status'] ) ) {
			$where[] = $this->wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['vendor_id'] ) ) {
			$where[] = $this->wpdb->prepare( 'vendor_id = %d', $args['vendor_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[] = $this->wpdb->prepare( 'created_at >= %s', $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[] = $this->wpdb->prepare( 'created_at <= %s', $args['date_to'] );
		}

		$where_clause = implode( ' AND ', $where );

		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
