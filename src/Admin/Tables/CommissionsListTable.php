<?php
/**
 * Commissions List Table.
 *
 * @package WCPE\Admin\Tables
 */

namespace WCPE\Admin\Tables;

use WCPE\Database\Repositories\CommissionRepository;

// Load WP_List_Table if not already loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays commissions in a list table format.
 *
 * @since 1.0.0
 */
class CommissionsListTable extends \WP_List_Table {

	/**
	 * Commission repository instance.
	 *
	 * @var CommissionRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'commission',
				'plural'   => 'commissions',
				'ajax'     => false,
			)
		);

		$this->repository = new CommissionRepository();
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'                => '<input type="checkbox" />',
			'order'             => __( 'Order', 'wcpe' ),
			'product'           => __( 'Product', 'wcpe' ),
			'vendor'            => __( 'Vendor', 'wcpe' ),
			'order_total'       => __( 'Order Amount', 'wcpe' ),
			'commission_amount' => __( 'Commission', 'wcpe' ),
			'status'            => __( 'Status', 'wcpe' ),
			'created_at'        => __( 'Date', 'wcpe' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'order'             => array( 'order_id', false ),
			'commission_amount' => array( 'commission_amount', false ),
			'status'            => array( 'status', false ),
			'created_at'        => array( 'created_at', true ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'approve' => __( 'Approve', 'wcpe' ),
			'pending' => __( 'Mark Pending', 'wcpe' ),
			'delete'  => __( 'Delete', 'wcpe' ),
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
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'     => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'    => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'vendor_id' => isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="commission_ids[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * Render order column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_order( $item ) {
		$order = wc_get_order( $item->order_id );

		if ( ! $order ) {
			return sprintf( '#%d (deleted)', $item->order_id );
		}

		$edit_url = $order->get_edit_order_url();

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'View Order', 'wcpe' )
			),
		);

		return sprintf(
			'<strong><a href="%s">#%d</a></strong>%s',
			esc_url( $edit_url ),
			$item->order_id,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render product column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_product( $item ) {
		$product = wc_get_product( $item->product_id );

		if ( ! $product ) {
			return sprintf( '#%d (deleted)', $item->product_id );
		}

		$edit_url = get_edit_post_link( $item->product_id );

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_url ),
			esc_html( $product->get_name() )
		);
	}

	/**
	 * Render vendor column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_vendor( $item ) {
		$user = get_user_by( 'id', $item->vendor_id );

		if ( ! $user ) {
			return sprintf( '#%d (deleted)', $item->vendor_id );
		}

		$edit_url   = get_edit_user_link( $item->vendor_id );
		$filter_url = add_query_arg(
			array(
				'page'      => 'wcpe-commissions',
				'vendor_id' => $item->vendor_id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<a href="%s">%s</a><br><small><a href="%s">%s</a></small>',
			esc_url( $edit_url ),
			esc_html( $user->display_name ),
			esc_url( $filter_url ),
			__( 'View all commissions', 'wcpe' )
		);
	}

	/**
	 * Render order total column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_order_total( $item ) {
		return wp_kses_post( wc_price( $item->order_total ) );
	}

	/**
	 * Render commission amount column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_commission_amount( $item ) {
		$output = '<span class="wcpe-amount wcpe-amount--positive">' . wp_kses_post( wc_price( $item->commission_amount ) ) . '</span>';

		if ( $item->commission_rate ) {
			$output .= '<br><small>' . esc_html( number_format( $item->commission_rate, 2 ) . '%' ) . '</small>';
		}

		return $output;
	}

	/**
	 * Render status column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = array(
			'pending'   => __( 'Pending', 'wcpe' ),
			'approved'  => __( 'Approved', 'wcpe' ),
			'paid'      => __( 'Paid', 'wcpe' ),
			'cancelled' => __( 'Cancelled', 'wcpe' ),
			'refunded'  => __( 'Refunded', 'wcpe' ),
		);

		$label = $labels[ $item->status ] ?? $item->status;

		$output = sprintf(
			'<span class="wcpe-status wcpe-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);

		// Add quick actions for pending commissions.
		if ( 'pending' === $item->status ) {
			$approve_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => 'wcpe-commissions',
						'action'        => 'approve',
						'commission_id' => $item->id,
					),
					admin_url( 'admin.php' )
				),
				'wcpe_approve_commission_' . $item->id
			);

			$output .= sprintf(
				'<br><small><a href="%s">%s</a></small>',
				esc_url( $approve_url ),
				__( 'Approve', 'wcpe' )
			);
		}

		return $output;
	}

	/**
	 * Render date column.
	 *
	 * @param object $item Commission object.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$date = strtotime( $item->created_at );

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( wp_date( 'Y-m-d H:i:s', $date ) ),
			esc_html( human_time_diff( $date, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wcpe' ) )
		);
	}

	/**
	 * Default column handler.
	 *
	 * @param object $item        Commission object.
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
		esc_html_e( 'No commissions found.', 'wcpe' );
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
		$vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Get vendors with commissions.
		global $wpdb;
		$vendors = $wpdb->get_results(
			"SELECT DISTINCT vendor_id FROM {$wpdb->prefix}wcpe_commissions"
		);
		?>
		<div class="alignleft actions">
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wcpe' ); ?></option>
				<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'wcpe' ); ?></option>
				<option value="approved" <?php selected( $status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'wcpe' ); ?></option>
				<option value="paid" <?php selected( $status, 'paid' ); ?>><?php esc_html_e( 'Paid', 'wcpe' ); ?></option>
				<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'wcpe' ); ?></option>
				<option value="refunded" <?php selected( $status, 'refunded' ); ?>><?php esc_html_e( 'Refunded', 'wcpe' ); ?></option>
			</select>

			<select name="vendor_id">
				<option value=""><?php esc_html_e( 'All Vendors', 'wcpe' ); ?></option>
				<?php foreach ( $vendors as $vendor ) : ?>
					<?php $user = get_user_by( 'id', $vendor->vendor_id ); ?>
					<?php if ( $user ) : ?>
						<option value="<?php echo esc_attr( $vendor->vendor_id ); ?>" <?php selected( $vendor_id, $vendor->vendor_id ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'wcpe' ), '', 'filter_action', false ); ?>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-commissions&export=csv' ) ); ?>" class="button">
				<?php esc_html_e( 'Export CSV', 'wcpe' ); ?>
			</a>
		</div>
		<?php
	}
}
