<?php
/**
 * Main Plugin Class.
 *
 * @package WCPE\Includes
 */

namespace WCPE\Includes;

use WCPE\Admin\AdminMenu;
use WCPE\Services\Logger;
use WCPE\Services\CommissionCalculator;
use WCPE\Services\StripeService;
use WCPE\Services\WebhookDispatcher;
use WCPE\API\StripeWebhook;
use WCPE\API\RulesController;
use WCPE\API\CommissionsController;
use WCPE\API\PayoutsController;

/**
 * Main plugin orchestrator.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Commission calculator instance.
	 *
	 * @var CommissionCalculator
	 */
	private $commission_calculator;

	/**
	 * Stripe service instance.
	 *
	 * @var StripeService
	 */
	private $stripe_service;

	/**
	 * Webhook dispatcher instance.
	 *
	 * @var WebhookDispatcher
	 */
	private $webhook_dispatcher;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger                = new Logger();
		$this->commission_calculator = new CommissionCalculator();
		$this->stripe_service        = new StripeService();
		$this->webhook_dispatcher    = new WebhookDispatcher();
	}

	/**
	 * Run the plugin.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Load required dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		// Dependencies are autoloaded via Composer.
	}

	/**
	 * Register hooks for admin and frontend.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// Admin hooks.
		if ( is_admin() ) {
			$admin_menu = new AdminMenu();
			add_action( 'admin_menu', array( $admin_menu, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

			// Hook CSV export early before any output.
			add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		}

		// Commission calculator hooks.
		$this->commission_calculator->register_hooks();

		// Register Stripe webhook endpoint.
		$webhook = new StripeWebhook();
		$webhook->register();

		// Register outgoing webhook dispatcher.
		$this->webhook_dispatcher->register_hooks();

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Schedule cron events.
		add_action( 'init', array( $this, 'schedule_cron_events' ) );

		// Log cleanup cron.
		add_action( 'wcpe_cleanup_old_logs', array( $this, 'cleanup_old_logs' ) );

		// Scheduled payouts cron.
		add_action( 'wcpe_process_scheduled_payouts', array( $this, 'process_scheduled_payouts' ) );

		// Outgoing webhook dispatch cron.
		add_action( 'wcpe_dispatch_webhook', array( $this, 'dispatch_webhook' ), 10, 4 );
	}

	/**
	 * Maybe export CSV (called early via admin_init).
	 *
	 * @return void
	 */
	public function maybe_export_csv() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'wcpe-commissions' !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['export'] ) || 'csv' !== $_GET['export'] ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wcpe' ) );
		}

		$repository = new \WCPE\Database\Repositories\CommissionRepository();

		$args = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			$args['status'] = sanitize_key( $_GET['status'] );
		}

		if ( ! empty( $_GET['vendor_id'] ) ) {
			$args['vendor_id'] = absint( $_GET['vendor_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$commissions = $repository->get_for_export( $args );

		// Clear any output buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=commissions-' . gmdate( 'Y-m-d' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// Add UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv(
			$output,
			array(
				__( 'ID', 'wcpe' ),
				__( 'Order ID', 'wcpe' ),
				__( 'Product', 'wcpe' ),
				__( 'Vendor', 'wcpe' ),
				__( 'Order Amount', 'wcpe' ),
				__( 'Commission Amount', 'wcpe' ),
				__( 'Rate (%)', 'wcpe' ),
				__( 'Status', 'wcpe' ),
				__( 'Date', 'wcpe' ),
			)
		);

		// Data rows.
		foreach ( $commissions as $commission ) {
			$product      = wc_get_product( $commission->product_id );
			$vendor       = get_user_by( 'id', $commission->vendor_id );
			$product_name = $product ? $product->get_name() : '#' . $commission->product_id;
			$vendor_name  = $vendor ? $vendor->display_name : '#' . $commission->vendor_id;

			fputcsv(
				$output,
				array(
					$commission->id,
					$commission->order_id,
					$product_name,
					$vendor_name,
					$commission->order_total,
					$commission->commission_amount,
					$commission->commission_rate,
					$commission->status,
					$commission->created_at,
				)
			);
		}

		fclose( $output );

		$this->logger->info(
			'commissions_exported',
			array(
				'count'  => count( $commissions ),
				'status' => $args['status'] ?? 'all',
			)
		);

		exit;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, 'wcpe' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'wcpe-admin',
			WCPE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WCPE_VERSION
		);

		wp_enqueue_script(
			'wcpe-admin',
			WCPE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WCPE_VERSION,
			true
		);

		wp_localize_script(
			'wcpe-admin',
			'wcpeAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcpe_admin_nonce' ),
				'strings' => array(
					'confirmDelete'  => __( 'Are you sure you want to delete this?', 'wcpe' ),
					'confirmPayout'  => __( 'Are you sure you want to process this payout?', 'wcpe' ),
					'processing'     => __( 'Processing...', 'wcpe' ),
					'success'        => __( 'Success!', 'wcpe' ),
					'error'          => __( 'An error occurred.', 'wcpe' ),
				),
			)
		);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		// Check if API is enabled.
		if ( 'yes' !== get_option( 'wcpe_api_enabled', 'yes' ) ) {
			return;
		}

		// Register Rules endpoints.
		$rules_controller = new RulesController();
		$rules_controller->register_routes();

		// Register Commissions endpoints.
		$commissions_controller = new CommissionsController();
		$commissions_controller->register_routes();

		// Register Payouts endpoints.
		$payouts_controller = new PayoutsController();
		$payouts_controller->register_routes();
	}

	/**
	 * Dispatch outgoing webhook (called via cron).
	 *
	 * @param int    $webhook_id Webhook ID.
	 * @param string $url        Webhook URL.
	 * @param array  $payload    Webhook payload.
	 * @param string $secret     Webhook secret.
	 * @return void
	 */
	public function dispatch_webhook( $webhook_id, $url, $payload, $secret ) {
		$this->webhook_dispatcher->send_webhook( $webhook_id, $url, $payload, $secret );
	}

	/**
	 * Schedule cron events.
	 *
	 * @return void
	 */
	public function schedule_cron_events() {
		// Schedule payout processing.
		if ( ! wp_next_scheduled( 'wcpe_process_scheduled_payouts' ) ) {
			wp_schedule_event( time(), 'daily', 'wcpe_process_scheduled_payouts' );
		}

		// Schedule log cleanup.
		if ( ! wp_next_scheduled( 'wcpe_cleanup_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'wcpe_cleanup_old_logs' );
		}
	}

	/**
	 * Cleanup old logs.
	 *
	 * @return void
	 */
	public function cleanup_old_logs() {
		$retention_days = get_option( 'wcpe_log_retention_days', 30 );
		$this->logger->cleanup( $retention_days );
	}

	/**
	 * Process scheduled payouts.
	 *
	 * @return void
	 */
	public function process_scheduled_payouts() {
		$schedule = get_option( 'wcpe_auto_payout_schedule', 'disabled' );

		if ( 'disabled' === $schedule ) {
			return;
		}

		$results = $this->stripe_service->process_scheduled_payouts();

		$this->logger->info(
			'cron_payouts_processed',
			$results
		);
	}

	/**
	 * Get logger instance.
	 *
	 * @return Logger
	 */
	public function get_logger() {
		return $this->logger;
	}

	/**
	 * Get commission calculator instance.
	 *
	 * @return CommissionCalculator
	 */
	public function get_commission_calculator() {
		return $this->commission_calculator;
	}

	/**
	 * Get Stripe service instance.
	 *
	 * @return StripeService
	 */
	public function get_stripe_service() {
		return $this->stripe_service;
	}

	/**
	 * Get webhook dispatcher instance.
	 *
	 * @return WebhookDispatcher
	 */
	public function get_webhook_dispatcher() {
		return $this->webhook_dispatcher;
	}
}
