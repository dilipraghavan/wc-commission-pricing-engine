<?php
/**
 * Base Repository Class.
 *
 * @package WCPE\Database\Repositories
 */

namespace WCPE\Database\Repositories;

/**
 * Abstract base class for repositories.
 *
 * @since 1.0.0
 */
abstract class BaseRepository {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Full table name with prefix.
	 *
	 * @var string
	 */
	protected $table_name;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . $this->table;
	}

	/**
	 * Get all records.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_all( $args = array() ) {
		$defaults = array(
			'orderby' => 'id',
			'order'   => 'DESC',
			'limit'   => 100,
			'offset'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args['limit'],
			$args['offset']
		);

		return $this->wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a single record by ID.
	 *
	 * @param int $id Record ID.
	 * @return object|null
	 */
	public function get( $id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
	}

	/**
	 * Create a new record.
	 *
	 * @param array $data Record data.
	 * @return int|false The new record ID or false on failure.
	 */
	public function create( $data ) {
		$result = $this->wpdb->insert(
			$this->table_name,
			$data,
			$this->get_format( $data )
		);

		if ( false === $result ) {
			return false;
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Update a record.
	 *
	 * @param int   $id   Record ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public function update( $id, $data ) {
		$result = $this->wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $id ),
			$this->get_format( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a record.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete( $id ) {
		$result = $this->wpdb->delete(
			$this->table_name,
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Count records.
	 *
	 * @param array $where Optional WHERE conditions.
	 * @return int
	 */
	public function count( $where = array() ) {
		$sql = "SELECT COUNT(*) FROM {$this->table_name}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $where ) ) {
			$conditions = array();
			foreach ( $where as $column => $value ) {
				$conditions[] = $this->wpdb->prepare(
					"{$column} = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$value
				);
			}
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get format array for wpdb methods.
	 *
	 * @param array $data Data array.
	 * @return array Format specifiers.
	 */
	protected function get_format( $data ) {
		$format = array();

		foreach ( $data as $key => $value ) {
			if ( is_int( $value ) ) {
				$format[] = '%d';
			} elseif ( is_float( $value ) ) {
				$format[] = '%f';
			} else {
				$format[] = '%s';
			}
		}

		return $format;
	}

	/**
	 * Get records by column value.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value to match.
	 * @return array
	 */
	public function get_by( $column, $value ) {
		$column = sanitize_key( $column );

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$column} = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value
			)
		);
	}

	/**
	 * Check if a record exists.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function exists( $id ) {
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table_name} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return null !== $result;
	}
}
