<?php
/**
 * Payouts Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

use WCPE\Admin\Tables\PayoutsListTable;
use WCPE\Database\Repositories\PayoutRepository;
use WCPE\Database\Repositories\CommissionRepository;
use WCPE\Services\StripeService;
use WCPE\Services\Logger;

/**
 * Handles the Payouts admin page.
 *
 * @since 1.0.0
 */
class PayoutsPage {

	/**
	 * Payout repository instance.
	 *
	 * @var PayoutRepository
	 */
	private $payout_repository;

	/**
	 * Commission repository instance.
	 *
	 * @var CommissionRepository
	 */
	private $commission_repository;

	/**
	 * Stripe service instance.
	 *
	 * @var StripeService
	 */
	private $stripe_service;

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
		$this->payout_repository     = new PayoutRepository();
		$this->commission_repository = new CommissionRepository();
		$this->stripe_service        = new StripeService();
		$this->logger                = new Logger();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		// Handle actions.
		$this->handle_actions();

		// Get summary stats.
		$summary = $this->payout_repository->get_summary();

		// Get vendors ready for payout.
		$minimum_payout  = floatval( get_option( 'wcpe_minimum_payout', 50 ) );
		$vendors_ready   = $this->payout_repository->get_vendors_ready_for_payout( $minimum_payout );
		$stripe_configured = $this->stripe_service->is_configured();

		$list_table = new PayoutsListTable();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payouts', 'wcpe' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( ! $stripe_configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Stripe Connect not configured.', 'wcpe' ); ?></strong>
						<?php esc_html_e( 'Please configure your Stripe API keys and Connect Client ID in Settings to enable payouts.', 'wcpe' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-settings&tab=stripe' ) ); ?>">
							<?php esc_html_e( 'Configure Stripe →', 'wcpe' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<!-- Stats Cards -->
			<div class="wcpe-stats-row">
				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Pending', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--warning">
						<?php echo wp_kses_post( wc_price( $summary['pending_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d payout', '%d payouts', $summary['pending_count'], 'wcpe' ), $summary['pending_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Processing', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--info">
						<?php echo wp_kses_post( wc_price( $summary['processing_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d payout', '%d payouts', $summary['processing_count'], 'wcpe' ), $summary['processing_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Completed', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--success">
						<?php echo wp_kses_post( wc_price( $summary['completed_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d payout', '%d payouts', $summary['completed_count'], 'wcpe' ), $summary['completed_count'] ) ); ?></small>
				</div>

				<div class="wcpe-stat-card">
					<div class="wcpe-stat-card__label"><?php esc_html_e( 'Failed', 'wcpe' ); ?></div>
					<div class="wcpe-stat-card__value wcpe-stat-card__value--error">
						<?php echo wp_kses_post( wc_price( $summary['failed_amount'] ) ); ?>
					</div>
					<small><?php echo esc_html( sprintf( _n( '%d payout', '%d payouts', $summary['failed_count'], 'wcpe' ), $summary['failed_count'] ) ); ?></small>
				</div>
			</div>

			<?php if ( ! empty( $vendors_ready ) ) : ?>
				<!-- Vendors Ready for Payout -->
				<div class="wcpe-ready-payouts">
					<h2><?php esc_html_e( 'Ready for Payout', 'wcpe' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %s: minimum payout amount */
							esc_html__( 'Vendors with approved commissions above the minimum payout threshold (%s).', 'wcpe' ),
							wp_kses_post( wc_price( $minimum_payout ) )
						);
						?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Vendor', 'wcpe' ); ?></th>
								<th><?php esc_html_e( 'Commissions', 'wcpe' ); ?></th>
								<th><?php esc_html_e( 'Total Amount', 'wcpe' ); ?></th>
								<th><?php esc_html_e( 'Stripe Status', 'wcpe' ); ?></th>
								<th><?php esc_html_e( 'Action', 'wcpe' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $vendors_ready as $vendor_data ) : ?>
								<?php
								$user         = get_user_by( 'id', $vendor_data->vendor_id );
								$is_connected = $this->stripe_service->is_vendor_connected( $vendor_data->vendor_id );
								?>
								<tr>
									<td>
										<?php echo $user ? esc_html( $user->display_name ) : '#' . esc_html( $vendor_data->vendor_id ); ?>
									</td>
									<td>
										<?php echo esc_html( $vendor_data->commission_count ); ?>
									</td>
									<td>
										<strong><?php echo wp_kses_post( wc_price( $vendor_data->total_amount ) ); ?></strong>
									</td>
									<td>
										<?php if ( $is_connected ) : ?>
											<span class="wcpe-status wcpe-status--active"><?php esc_html_e( 'Connected', 'wcpe' ); ?></span>
										<?php else : ?>
											<span class="wcpe-status wcpe-status--inactive"><?php esc_html_e( 'Not Connected', 'wcpe' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $is_connected && $stripe_configured ) : ?>
											<?php
											$payout_url = wp_nonce_url(
												add_query_arg(
													array(
														'page'      => 'wcpe-payouts',
														'action'    => 'process_payout',
														'vendor_id' => $vendor_data->vendor_id,
													),
													admin_url( 'admin.php' )
												),
												'wcpe_process_payout_' . $vendor_data->vendor_id
											);
											?>
											<a href="<?php echo esc_url( $payout_url ); ?>" class="button button-primary button-small wcpe-confirm-payout">
												<?php esc_html_e( 'Process Payout', 'wcpe' ); ?>
											</a>
										<?php elseif ( ! $is_connected ) : ?>
											<span class="description"><?php esc_html_e( 'Vendor needs to connect Stripe', 'wcpe' ); ?></span>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'Configure Stripe first', 'wcpe' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Payout History', 'wcpe' ); ?></h2>

			<form method="get">
				<input type="hidden" name="page" value="wcpe-payouts">
				<?php $list_table->display(); ?>
			</form>
		</div>

		<style>
			.wcpe-ready-payouts {
				background: #fff;
				padding: 20px;
				margin: 20px 0;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
			}
			.wcpe-ready-payouts h2 {
				margin-top: 0;
			}
			.wcpe-ready-payouts table {
				margin-top: 15px;
			}
		</style>
		<?php
	}

	/**
	 * Handle page actions.
	 *
	 * @return void
	 */
	private function handle_actions() {
		// Process single payout.
		if ( isset( $_GET['action'] ) && 'process_payout' === $_GET['action'] && isset( $_GET['vendor_id'] ) ) {
			$vendor_id = absint( $_GET['vendor_id'] );

			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wcpe_process_payout_' . $vendor_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wcpe' ) );
			}

			$result = $this->stripe_service->process_vendor_payout( $vendor_id );

			if ( $result ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-payouts&payout_processed=1&amount=' . $result['amount'] ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-payouts&payout_failed=1' ) );
			}
			exit;
		}
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['payout_processed'] ) ) {
			$amount = isset( $_GET['amount'] ) ? floatval( $_GET['amount'] ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: payout amount */
				esc_html__( 'Payout of %s processed successfully!', 'wcpe' ),
				wp_kses_post( wc_price( $amount ) )
			);
			echo '</p></div>';
		}

		if ( isset( $_GET['payout_failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			esc_html_e( 'Payout processing failed. Please check the logs for details.', 'wcpe' );
			echo '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
