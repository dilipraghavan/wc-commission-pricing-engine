<?php
/**
 * Rules List Table.
 *
 * @package WCPE\Admin\Tables
 */

namespace WCPE\Admin\Tables;

use WCPE\Database\Repositories\RuleRepository;

// Load WP_List_Table if not already loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays rules in a list table format.
 *
 * @since 1.0.0
 */
class RulesListTable extends \WP_List_Table {

	/**
	 * Rule repository instance.
	 *
	 * @var RuleRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'rule',
				'plural'   => 'rules',
				'ajax'     => false,
			)
		);

		$this->repository = new RuleRepository();
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'name'        => __( 'Name', 'wcpe' ),
			'rule_type'   => __( 'Type', 'wcpe' ),
			'target'      => __( 'Applies To', 'wcpe' ),
			'value'       => __( 'Commission', 'wcpe' ),
			'priority'    => __( 'Priority', 'wcpe' ),
			'status'      => __( 'Status', 'wcpe' ),
			'date_range'  => __( 'Date Range', 'wcpe' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'rule_type' => array( 'rule_type', false ),
			'value'     => array( 'value', false ),
			'priority'  => array( 'priority', true ),
			'status'    => array( 'status', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'activate'   => __( 'Activate', 'wcpe' ),
			'deactivate' => __( 'Deactivate', 'wcpe' ),
			'delete'     => __( 'Delete', 'wcpe' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$args = array(
			'per_page'  => $per_page,
			'page'      => $page,
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'priority', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'     => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'    => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'rule_type' => isset( $_GET['rule_type'] ) ? sanitize_key( $_GET['rule_type'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		$result = $this->repository->get_paginated( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Render checkbox column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="rule_ids[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * Render name column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_name( $item ) {
		$edit_url   = admin_url( 'admin.php?page=wcpe-dashboard&action=edit&rule_id=' . $item->id );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=wcpe-dashboard&action=delete&rule_id=' . $item->id ),
			'wcpe_delete_rule_' . $item->id
		);
		$toggle_url = wp_nonce_url(
			admin_url( 'admin.php?page=wcpe-dashboard&action=toggle&rule_id=' . $item->id ),
			'wcpe_toggle_rule_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Edit', 'wcpe' ) ),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $toggle_url ),
				'active' === $item->status ? __( 'Deactivate', 'wcpe' ) : __( 'Activate', 'wcpe' )
			),
			'delete' => sprintf(
				'<a href="%s" class="wcpe-action--delete">%s</a>',
				esc_url( $delete_url ),
				__( 'Delete', 'wcpe' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item->name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render rule type column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_rule_type( $item ) {
		$labels = array(
			'global'   => __( 'Global', 'wcpe' ),
			'category' => __( 'Category', 'wcpe' ),
			'vendor'   => __( 'Vendor', 'wcpe' ),
			'product'  => __( 'Product', 'wcpe' ),
		);

		$label = $labels[ $item->rule_type ] ?? $item->rule_type;

		return sprintf(
			'<span class="wcpe-rule-type wcpe-rule-type--%s">%s</span>',
			esc_attr( $item->rule_type ),
			esc_html( $label )
		);
	}

	/**
	 * Render target column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_target( $item ) {
		if ( 'global' === $item->rule_type || empty( $item->target_id ) ) {
			return '<em>' . esc_html__( 'All Products', 'wcpe' ) . '</em>';
		}

		switch ( $item->rule_type ) {
			case 'category':
				$term = get_term( $item->target_id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? esc_html( $term->name ) : __( 'Unknown Category', 'wcpe' );

			case 'vendor':
				$user = get_user_by( 'id', $item->target_id );
				return $user ? esc_html( $user->display_name ) : __( 'Unknown Vendor', 'wcpe' );

			case 'product':
				$product = wc_get_product( $item->target_id );
				return $product ? esc_html( $product->get_name() ) : __( 'Unknown Product', 'wcpe' );

			default:
				return '—';
		}
	}

	/**
	 * Render value column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_value( $item ) {
		if ( 'percentage' === $item->calculation_method ) {
			return esc_html( number_format( $item->value, 2 ) . '%' );
		}

		return wp_kses_post( wc_price( $item->value ) );
	}

	/**
	 * Render priority column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_priority( $item ) {
		$max_priority = 100;
		$percentage   = min( 100, ( $item->priority / $max_priority ) * 100 );

		return sprintf(
			'<div class="wcpe-priority">
				<span>%d</span>
				<div class="wcpe-priority__bar">
					<div class="wcpe-priority__fill" style="width: %d%%"></div>
				</div>
			</div>',
			(int) $item->priority,
			(int) $percentage
		);
	}

	/**
	 * Render status column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_status( $item ) {
		$label = 'active' === $item->status ? __( 'Active', 'wcpe' ) : __( 'Inactive', 'wcpe' );

		return sprintf(
			'<span class="wcpe-status wcpe-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);
	}

	/**
	 * Render date range column.
	 *
	 * @param object $item Rule object.
	 * @return string
	 */
	public function column_date_range( $item ) {
		if ( empty( $item->start_date ) && empty( $item->end_date ) ) {
			return '<em>' . esc_html__( 'Always', 'wcpe' ) . '</em>';
		}

		$start = $item->start_date ? wp_date( get_option( 'date_format' ), strtotime( $item->start_date ) ) : '—';
		$end   = $item->end_date ? wp_date( get_option( 'date_format' ), strtotime( $item->end_date ) ) : '—';

		return sprintf( '%s → %s', esc_html( $start ), esc_html( $end ) );
	}

	/**
	 * Default column handler.
	 *
	 * @param object $item        Rule object.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '—';
	}

	/**
	 * Message when no items found.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No commission rules found.', 'wcpe' );
	}

	/**
	 * Extra table navigation (filters).
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$status    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rule_type = isset( $_GET['rule_type'] ) ? sanitize_key( $_GET['rule_type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wcpe' ); ?></option>
				<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'wcpe' ); ?></option>
				<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'wcpe' ); ?></option>
			</select>

			<select name="rule_type">
				<option value=""><?php esc_html_e( 'All Types', 'wcpe' ); ?></option>
				<option value="global" <?php selected( $rule_type, 'global' ); ?>><?php esc_html_e( 'Global', 'wcpe' ); ?></option>
				<option value="category" <?php selected( $rule_type, 'category' ); ?>><?php esc_html_e( 'Category', 'wcpe' ); ?></option>
				<option value="vendor" <?php selected( $rule_type, 'vendor' ); ?>><?php esc_html_e( 'Vendor', 'wcpe' ); ?></option>
				<option value="product" <?php selected( $rule_type, 'product' ); ?>><?php esc_html_e( 'Product', 'wcpe' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'wcpe' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
