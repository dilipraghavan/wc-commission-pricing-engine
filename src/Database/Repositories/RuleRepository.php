<?php
/**
 * Rule Repository.
 *
 * @package WCPE\Database\Repositories
 */

namespace WCPE\Database\Repositories;

/**
 * Handles database operations for commission rules.
 *
 * @since 1.0.0
 */
class RuleRepository extends BaseRepository {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	protected $table = 'wcpe_rules';

	/**
	 * Get all active rules.
	 *
	 * @return array
	 */
	public function get_active() {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table_name} 
			WHERE status = 'active' 
			ORDER BY priority DESC, id ASC"
		);
	}

	/**
	 * Get rules by type.
	 *
	 * @param string $type Rule type (global, category, vendor, product).
	 * @return array
	 */
	public function get_by_type( $type ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE rule_type = %s ORDER BY priority DESC",
				$type
			)
		);
	}

	/**
	 * Get rules applicable to a specific target.
	 *
	 * @param string   $type      Rule type.
	 * @param int|null $target_id Target ID (category, vendor, or product ID).
	 * @return array
	 */
	public function get_applicable_rules( $type, $target_id = null ) {
		$now = current_time( 'mysql' );

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} 
			WHERE status = 'active'
			AND rule_type = %s
			AND (start_date IS NULL OR start_date <= %s)
			AND (end_date IS NULL OR end_date >= %s)",
			$type,
			$now,
			$now
		);

		if ( null !== $target_id ) {
			$sql .= $this->wpdb->prepare( ' AND target_id = %d', $target_id );
		}

		$sql .= ' ORDER BY priority DESC';

		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Get global rules.
	 *
	 * @return array
	 */
	public function get_global_rules() {
		$now = current_time( 'mysql' );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = 'active'
				AND rule_type = 'global'
				AND (start_date IS NULL OR start_date <= %s)
				AND (end_date IS NULL OR end_date >= %s)
				ORDER BY priority DESC",
				$now,
				$now
			)
		);
	}

	/**
	 * Get rules for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_rules_for_product( $product_id ) {
		$now = current_time( 'mysql' );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = 'active'
				AND rule_type = 'product'
				AND target_id = %d
				AND (start_date IS NULL OR start_date <= %s)
				AND (end_date IS NULL OR end_date >= %s)
				ORDER BY priority DESC",
				$product_id,
				$now,
				$now
			)
		);
	}

	/**
	 * Get rules for a category.
	 *
	 * @param int $category_id Category ID.
	 * @return array
	 */
	public function get_rules_for_category( $category_id ) {
		$now = current_time( 'mysql' );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = 'active'
				AND rule_type = 'category'
				AND target_id = %d
				AND (start_date IS NULL OR start_date <= %s)
				AND (end_date IS NULL OR end_date >= %s)
				ORDER BY priority DESC",
				$category_id,
				$now,
				$now
			)
		);
	}

	/**
	 * Get rules for a vendor.
	 *
	 * @param int $vendor_id Vendor (user) ID.
	 * @return array
	 */
	public function get_rules_for_vendor( $vendor_id ) {
		$now = current_time( 'mysql' );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = 'active'
				AND rule_type = 'vendor'
				AND target_id = %d
				AND (start_date IS NULL OR start_date <= %s)
				AND (end_date IS NULL OR end_date >= %s)
				ORDER BY priority DESC",
				$vendor_id,
				$now,
				$now
			)
		);
	}

	/**
	 * Create a new rule.
	 *
	 * @param array $data Rule data.
	 * @return int|false The new rule ID or false on failure.
	 */
	public function create( $data ) {
		$defaults = array(
			'name'               => '',
			'rule_type'          => 'global',
			'calculation_method' => 'percentage',
			'value'              => 0,
			'target_id'          => null,
			'priority'           => 10,
			'status'             => 'active',
			'start_date'         => null,
			'end_date'           => null,
			'created_by'         => get_current_user_id(),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// Sanitize data.
		$data['name']               = sanitize_text_field( $data['name'] );
		$data['rule_type']          = sanitize_key( $data['rule_type'] );
		$data['calculation_method'] = sanitize_key( $data['calculation_method'] );
		$data['value']              = floatval( $data['value'] );
		$data['target_id']          = $data['target_id'] ? absint( $data['target_id'] ) : null;
		$data['priority']           = absint( $data['priority'] );
		$data['status']             = sanitize_key( $data['status'] );

		return parent::create( $data );
	}

	/**
	 * Update a rule.
	 *
	 * @param int   $id   Rule ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public function update( $id, $data ) {
		// Sanitize data.
		if ( isset( $data['name'] ) ) {
			$data['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['rule_type'] ) ) {
			$data['rule_type'] = sanitize_key( $data['rule_type'] );
		}
		if ( isset( $data['calculation_method'] ) ) {
			$data['calculation_method'] = sanitize_key( $data['calculation_method'] );
		}
		if ( isset( $data['value'] ) ) {
			$data['value'] = floatval( $data['value'] );
		}
		if ( isset( $data['target_id'] ) ) {
			$data['target_id'] = $data['target_id'] ? absint( $data['target_id'] ) : null;
		}
		if ( isset( $data['priority'] ) ) {
			$data['priority'] = absint( $data['priority'] );
		}
		if ( isset( $data['status'] ) ) {
			$data['status'] = sanitize_key( $data['status'] );
		}

		$data['updated_at'] = current_time( 'mysql' );

		return parent::update( $id, $data );
	}

	/**
	 * Toggle rule status.
	 *
	 * @param int $id Rule ID.
	 * @return bool
	 */
	public function toggle_status( $id ) {
		$rule = $this->get( $id );

		if ( ! $rule ) {
			return false;
		}

		$new_status = 'active' === $rule->status ? 'inactive' : 'active';

		return $this->update( $id, array( 'status' => $new_status ) );
	}

	/**
	 * Get rules with pagination and filtering.
	 *
	 * @param array $args Query arguments.
	 * @return array Array with 'items' and 'total' keys.
	 */
	public function get_paginated( $args = array() ) {
		$defaults = array(
			'per_page'  => 20,
			'page'      => 1,
			'orderby'   => 'priority',
			'order'     => 'DESC',
			'status'    => '',
			'rule_type' => '',
			'search'    => '',
		);

		$args   = wp_parse_args( $args, $defaults );
		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		// Build WHERE clause.
		$where = array( '1=1' );

		if ( ! empty( $args['status'] ) ) {
			$where[] = $this->wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['rule_type'] ) ) {
			$where[] = $this->wpdb->prepare( 'rule_type = %s', $args['rule_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[] = $this->wpdb->prepare( 'name LIKE %s', '%' . $this->wpdb->esc_like( $args['search'] ) . '%' );
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitize orderby.
		$allowed_orderby = array( 'id', 'name', 'rule_type', 'value', 'priority', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'priority';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// Get items.
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE {$where_clause} 
				ORDER BY {$orderby} {$order} 
				LIMIT %d OFFSET %d",
				$args['per_page'],
				$offset
			)
		);

		// Get total count.
		$total = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}"
		);

		return array(
			'items' => $items,
			'total' => (int) $total,
		);
	}
}
