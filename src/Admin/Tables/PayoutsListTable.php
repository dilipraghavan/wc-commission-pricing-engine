<?php
/**
 * Payouts List Table.
 *
 * @package WCPE\Admin\Tables
 */

namespace WCPE\Admin\Tables;

use WCPE\Database\Repositories\PayoutRepository;
use WCPE\Services\StripeService;

// Load WP_List_Table if not already loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays payouts in a list table format.
 *
 * @since 1.0.0
 */
class PayoutsListTable extends \WP_List_Table {

	/**
	 * Payout repository instance.
	 *
	 * @var PayoutRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payout',
				'plural'   => 'payouts',
				'ajax'     => false,
			)
		);

		$this->repository = new PayoutRepository();
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'Payout #', 'wcpe' ),
			'vendor'       => __( 'Vendor', 'wcpe' ),
			'amount'       => __( 'Amount', 'wcpe' ),
			'net_amount'   => __( 'Net Amount', 'wcpe' ),
			'status'       => __( 'Status', 'wcpe' ),
			'transfer_id'  => __( 'Stripe Transfer', 'wcpe' ),
			'created_at'   => __( 'Created', 'wcpe' ),
			'processed_at' => __( 'Processed', 'wcpe' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'id'           => array( 'id', true ),
			'amount'       => array( 'amount', false ),
			'status'       => array( 'status', false ),
			'created_at'   => array( 'created_at', true ),
			'processed_at' => array( 'processed_at', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'retry' => __( 'Retry Failed', 'wcpe' ),
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
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="payout_ids[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * Render ID column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_id( $item ) {
		return sprintf( '<strong>#%d</strong>', $item->id );
	}

	/**
	 * Render vendor column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_vendor( $item ) {
		$user = get_user_by( 'id', $item->vendor_id );

		if ( ! $user ) {
			return sprintf( '#%d (deleted)', $item->vendor_id );
		}

		$stripe_service = new StripeService();
		$is_connected   = $stripe_service->is_vendor_connected( $item->vendor_id );

		$output = sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_user_link( $item->vendor_id ) ),
			esc_html( $user->display_name )
		);

		if ( $is_connected ) {
			$output .= ' <span class="wcpe-badge wcpe-badge--success" title="' . esc_attr__( 'Stripe Connected', 'wcpe' ) . '">✓</span>';
		}

		return $output;
	}

	/**
	 * Render amount column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_amount( $item ) {
		return wp_kses_post( wc_price( $item->amount ) );
	}

	/**
	 * Render net amount column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_net_amount( $item ) {
		$output = wp_kses_post( wc_price( $item->net_amount ) );

		if ( $item->fee_amount > 0 ) {
			$output .= '<br><small>' . sprintf(
				/* translators: %s: fee amount */
				esc_html__( 'Fee: %s', 'wcpe' ),
				wp_kses_post( wc_price( $item->fee_amount ) )
			) . '</small>';
		}

		return $output;
	}

	/**
	 * Render status column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = array(
			'pending'    => __( 'Pending', 'wcpe' ),
			'processing' => __( 'Processing', 'wcpe' ),
			'completed'  => __( 'Completed', 'wcpe' ),
			'failed'     => __( 'Failed', 'wcpe' ),
		);

		$label = $labels[ $item->status ] ?? $item->status;

		$output = sprintf(
			'<span class="wcpe-status wcpe-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);

		if ( 'failed' === $item->status && ! empty( $item->error_message ) ) {
			$output .= sprintf(
				'<br><small class="wcpe-error-message" title="%s">%s</small>',
				esc_attr( $item->error_message ),
				esc_html( wp_trim_words( $item->error_message, 5 ) )
			);
		}

		return $output;
	}

	/**
	 * Render transfer ID column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_transfer_id( $item ) {
		if ( empty( $item->stripe_transfer_id ) ) {
			return '—';
		}

		$mode        = get_option( 'wcpe_stripe_mode', 'test' );
		$dashboard   = 'live' === $mode ? 'https://dashboard.stripe.com' : 'https://dashboard.stripe.com/test';
		$transfer_url = $dashboard . '/connect/transfers/' . $item->stripe_transfer_id;

		return sprintf(
			'<a href="%s" target="_blank" class="wcpe-stripe-link">%s</a>',
			esc_url( $transfer_url ),
			esc_html( substr( $item->stripe_transfer_id, 0, 15 ) . '...' )
		);
	}

	/**
	 * Render created at column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$date = strtotime( $item->created_at );

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( wp_date( 'Y-m-d H:i:s', $date ) ),
			esc_html( wp_date( get_option( 'date_format' ), $date ) )
		);
	}

	/**
	 * Render processed at column.
	 *
	 * @param object $item Payout object.
	 * @return string
	 */
	public function column_processed_at( $item ) {
		if ( empty( $item->processed_at ) ) {
			return '—';
		}

		$date = strtotime( $item->processed_at );

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( wp_date( 'Y-m-d H:i:s', $date ) ),
			esc_html( wp_date( get_option( 'date_format' ), $date ) )
		);
	}

	/**
	 * Default column handler.
	 *
	 * @param object $item        Payout object.
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
		esc_html_e( 'No payouts found.', 'wcpe' );
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

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'wcpe' ); ?></option>
				<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'wcpe' ); ?></option>
				<option value="processing" <?php selected( $status, 'processing' ); ?>><?php esc_html_e( 'Processing', 'wcpe' ); ?></option>
				<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'wcpe' ); ?></option>
				<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wcpe' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'wcpe' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
