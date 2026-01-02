<?php
/**
 * Commissions Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

use WCPE\Admin\Tables\CommissionsListTable;
use WCPE\Database\Repositories\CommissionRepository;
use WCPE\Services\Logger;

/**
 * Handles the Commissions admin page.
 *
 * @since 1.0.0
 */
class CommissionsPage {

	/**
	 * Commission repository instance.
	 *
	 * @var CommissionRepository
	 */
	private $repository;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new CommissionRepository();
		$this->logger     = new Logger();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		// Handle actions first.
		$this->handle_actions();

		// Get summary stats.
		$summary = $this->repository->get_summary();

		$list_table = new CommissionsListTable();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Commissions', 'wcpe' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<!-- Stats Cards -->
			<div class="wcpe-stats-row">
				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Pending', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--warning">
						<?php echo wp_kses_post( wc_price( $summary['pending_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d commission', '%d commissions', $summary['pending_count'], 'wcpe' ), $summary['pending_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Approved (Ready for Payout)', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--success">
						<?php echo wp_kses_post( wc_price( $summary['approved_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d commission', '%d commissions', $summary['approved_count'], 'wcpe' ), $summary['approved_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Paid', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value">
						<?php echo wp_kses_post( wc_price( $summary['paid_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d commission', '%d commissions', $summary['paid_count'], 'wcpe' ), $summary['paid_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Total', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value">
						<?php echo wp_kses_post( wc_price( $summary['total_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d commission', '%d commissions', $summary['total_count'], 'wcpe' ), $summary['total_count'] ) ); ?></small>
				</div>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="wcpe-commissions">
				<?php
				$list_table->search_box( __( 'Search Order #', 'wcpe' ), 'commission' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle page actions.
	 *
	 * @return void
	 */
	private function handle_actions() {
		// Single approve action.
		if ( isset( $_GET['action'] ) && 'approve' === $_GET['action'] && isset( $_GET['commission_id'] ) ) {
			$commission_id = absint( $_GET['commission_id'] );

			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wcpe_approve_commission_' . $commission_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wcpe' ) );
			}

			if ( $this->repository->update_status( $commission_id, 'approved' ) ) {
				$this->logger->info( 'commission_approved', array( 'commission_id' => $commission_id ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-commissions&approved=1' ) );
				exit;
			}
		}

		// Bulk actions.
		if ( isset( $_POST['action'] ) && isset( $_POST['commission_ids'] ) ) {
			$this->handle_bulk_action();
		}
	}

	/**
	 * Handle bulk actions.
	 *
	 * @return void
	 */
	private function handle_bulk_action() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bulk-commissions' ) ) {
			return;
		}

		$action         = sanitize_key( $_POST['action'] );
		$commission_ids = array_map( 'absint', $_POST['commission_ids'] );

		if ( empty( $commission_ids ) ) {
			return;
		}

		$count = 0;

		switch ( $action ) {
			case 'approve':
				$count = $this->repository->bulk_update_status( $commission_ids, 'approved' );
				break;

			case 'pending':
				$count = $this->repository->bulk_update_status( $commission_ids, 'pending' );
				break;

			case 'delete':
				foreach ( $commission_ids as $id ) {
					if ( $this->repository->delete( $id ) ) {
						++$count;
					}
				}
				break;
		}

		$this->logger->info(
			'commissions_bulk_action',
			array(
				'action' => $action,
				'count'  => $count,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wcpe-commissions&bulk=' . $action . '&count=' . $count ) );
		exit;
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['approved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Commission approved.', 'wcpe' ) . '</p></div>';
		}

		if ( isset( $_GET['bulk'] ) && isset( $_GET['count'] ) ) {
			$action = sanitize_key( $_GET['bulk'] );
			$count  = absint( $_GET['count'] );
			/* translators: %d: number of commissions */
			$message = sprintf( _n( '%d commission updated.', '%d commissions updated.', $count, 'wcpe' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
