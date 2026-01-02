<?php
/**
 * Stripe Service.
 *
 * Handles Stripe Connect OAuth and Transfer operations.
 *
 * @package WCPE\Services
 */

namespace WCPE\Services;

use WCPE\Database\Repositories\PayoutRepository;
use WCPE\Database\Repositories\CommissionRepository;

/**
 * Stripe integration service.
 *
 * @since 1.0.0
 */
class StripeService {

	/**
	 * Stripe API version.
	 */
	const API_VERSION = '2023-10-16';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Payout repository.
	 *
	 * @var PayoutRepository
	 */
	private $payout_repository;

	/**
	 * Commission repository.
	 *
	 * @var CommissionRepository
	 */
	private $commission_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger                = new Logger();
		$this->payout_repository     = new PayoutRepository();
		$this->commission_repository = new CommissionRepository();
	}

	/**
	 * Check if Stripe is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$secret_key = $this->get_secret_key();
		$client_id  = get_option( 'wcpe_stripe_connect_client_id' );

		return ! empty( $secret_key ) && ! empty( $client_id );
	}

	/**
	 * Get the appropriate secret key based on mode.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		$mode = get_option( 'wcpe_stripe_mode', 'test' );

		if ( 'live' === $mode ) {
			return get_option( 'wcpe_stripe_live_secret_key', '' );
		}

		return get_option( 'wcpe_stripe_test_secret_key', '' );
	}

	/**
	 * Get the appropriate publishable key based on mode.
	 *
	 * @return string
	 */
	public function get_publishable_key() {
		$mode = get_option( 'wcpe_stripe_mode', 'test' );

		if ( 'live' === $mode ) {
			return get_option( 'wcpe_stripe_live_publishable_key', '' );
		}

		return get_option( 'wcpe_stripe_test_publishable_key', '' );
	}

	/**
	 * Initialize Stripe SDK.
	 *
	 * @return bool True if initialized successfully.
	 */
	private function init_stripe() {
		$secret_key = $this->get_secret_key();

		if ( empty( $secret_key ) ) {
			return false;
		}

		if ( ! class_exists( '\Stripe\Stripe' ) ) {
			// Try to load via Composer autoload.
			$autoload = WCPE_PLUGIN_DIR . 'vendor/autoload.php';
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			} else {
				$this->logger->error( 'stripe_sdk_missing', array(), 'Stripe SDK not found. Run composer install.' );
				return false;
			}
		}

		\Stripe\Stripe::setApiKey( $secret_key );
		\Stripe\Stripe::setApiVersion( self::API_VERSION );

		return true;
	}

	/**
	 * Generate OAuth authorization URL for vendor onboarding.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return string|false Authorization URL or false on failure.
	 */
	public function get_connect_url( $vendor_id ) {
		$client_id = get_option( 'wcpe_stripe_connect_client_id' );

		if ( empty( $client_id ) ) {
			return false;
		}

		$state = wp_create_nonce( 'wcpe_stripe_connect_' . $vendor_id );

		// Store state temporarily.
		set_transient( 'wcpe_stripe_state_' . $state, $vendor_id, HOUR_IN_SECONDS );

		$redirect_uri = admin_url( 'admin.php?page=wcpe-settings&tab=stripe&action=connect_callback' );

		$params = array(
			'response_type'  => 'code',
			'client_id'      => $client_id,
			'scope'          => 'read_write',
			'redirect_uri'   => $redirect_uri,
			'state'          => $state,
			'stripe_user'    => array(
				'email' => wp_get_current_user()->user_email,
			),
		);

		$url = 'https://connect.stripe.com/oauth/authorize?' . http_build_query( $params );

		$this->logger->info(
			'stripe_connect_url_generated',
			array(
				'vendor_id' => $vendor_id,
				'url'       => $url,
			)
		);

		return $url;
	}

	/**
	 * Handle OAuth callback and exchange code for access token.
	 *
	 * @param string $code  Authorization code from Stripe.
	 * @param string $state State parameter for verification.
	 * @return array|false Connected account data or false on failure.
	 */
	public function handle_connect_callback( $code, $state ) {
		// Verify state.
		$vendor_id = get_transient( 'wcpe_stripe_state_' . $state );

		if ( ! $vendor_id ) {
			$this->logger->error( 'stripe_connect_invalid_state', array( 'state' => $state ) );
			return false;
		}

		// Delete transient.
		delete_transient( 'wcpe_stripe_state_' . $state );

		if ( ! $this->init_stripe() ) {
			return false;
		}

		try {
			$response = \Stripe\OAuth::token(
				array(
					'grant_type' => 'authorization_code',
					'code'       => $code,
				)
			);

			$stripe_user_id = $response->stripe_user_id;

			// Store the connected account ID for the vendor.
			update_user_meta( $vendor_id, '_wcpe_stripe_account_id', $stripe_user_id );
			update_user_meta( $vendor_id, '_wcpe_stripe_connected_at', current_time( 'mysql' ) );

			$this->logger->info(
				'stripe_account_connected',
				array(
					'vendor_id'       => $vendor_id,
					'stripe_user_id'  => $stripe_user_id,
				),
				'Vendor connected Stripe account'
			);

			return array(
				'vendor_id'      => $vendor_id,
				'stripe_user_id' => $stripe_user_id,
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->logger->error(
				'stripe_connect_failed',
				array(
					'vendor_id' => $vendor_id,
					'error'     => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Check if a vendor has connected their Stripe account.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return bool
	 */
	public function is_vendor_connected( $vendor_id ) {
		$stripe_account_id = get_user_meta( $vendor_id, '_wcpe_stripe_account_id', true );
		return ! empty( $stripe_account_id );
	}

	/**
	 * Get vendor's Stripe account ID.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return string|false
	 */
	public function get_vendor_stripe_account( $vendor_id ) {
		return get_user_meta( $vendor_id, '_wcpe_stripe_account_id', true );
	}

	/**
	 * Disconnect a vendor's Stripe account.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return bool
	 */
	public function disconnect_vendor( $vendor_id ) {
		$stripe_account_id = $this->get_vendor_stripe_account( $vendor_id );

		if ( empty( $stripe_account_id ) ) {
			return false;
		}

		if ( ! $this->init_stripe() ) {
			return false;
		}

		try {
			// Deauthorize the connected account.
			$client_id = get_option( 'wcpe_stripe_connect_client_id' );

			\Stripe\OAuth::deauthorize(
				array(
					'client_id'       => $client_id,
					'stripe_user_id'  => $stripe_account_id,
				)
			);

			// Remove stored data.
			delete_user_meta( $vendor_id, '_wcpe_stripe_account_id' );
			delete_user_meta( $vendor_id, '_wcpe_stripe_connected_at' );

			$this->logger->info(
				'stripe_account_disconnected',
				array(
					'vendor_id'       => $vendor_id,
					'stripe_user_id'  => $stripe_account_id,
				)
			);

			return true;

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->logger->error(
				'stripe_disconnect_failed',
				array(
					'vendor_id' => $vendor_id,
					'error'     => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Create a payout (transfer) to a vendor.
	 *
	 * @param int   $vendor_id      Vendor user ID.
	 * @param float $amount         Amount to transfer.
	 * @param array $commission_ids Commission IDs being paid out.
	 * @return array|false Payout result or false on failure.
	 */
	public function create_payout( $vendor_id, $amount, $commission_ids = array() ) {
		$stripe_account_id = $this->get_vendor_stripe_account( $vendor_id );

		if ( empty( $stripe_account_id ) ) {
			$this->logger->error(
				'payout_failed_no_stripe_account',
				array( 'vendor_id' => $vendor_id )
			);
			return false;
		}

		if ( ! $this->init_stripe() ) {
			return false;
		}

		// Calculate fees.
		$fee_handling = get_option( 'wcpe_payout_fee_handling', 'platform' );
		$fee_amount   = 0;
		$net_amount   = $amount;

		// Stripe Connect has no transfer fee for Standard accounts.
		// But you might want to charge a platform fee.
		if ( 'vendor' === $fee_handling ) {
			// Deduct any platform fees from vendor.
			$platform_fee_percent = apply_filters( 'wcpe_platform_fee_percent', 0 );
			$fee_amount           = $amount * ( $platform_fee_percent / 100 );
			$net_amount           = $amount - $fee_amount;
		}

		// Create payout record first.
		$payout_id = $this->payout_repository->create(
			array(
				'vendor_id'  => $vendor_id,
				'amount'     => $amount,
				'fee_amount' => $fee_amount,
				'net_amount' => $net_amount,
				'currency'   => strtolower( get_woocommerce_currency() ),
				'status'     => 'processing',
			)
		);

		if ( ! $payout_id ) {
			$this->logger->error( 'payout_record_creation_failed', array( 'vendor_id' => $vendor_id ) );
			return false;
		}

		try {
			// Create Stripe Transfer.
			$transfer = \Stripe\Transfer::create(
				array(
					'amount'             => $this->to_stripe_amount( $net_amount ),
					'currency'           => strtolower( get_woocommerce_currency() ),
					'destination'        => $stripe_account_id,
					'transfer_group'     => 'payout_' . $payout_id,
					'metadata'           => array(
						'payout_id'      => $payout_id,
						'vendor_id'      => $vendor_id,
						'commission_ids' => implode( ',', $commission_ids ),
					),
				)
			);

			// Update payout with Stripe transfer ID.
			$this->payout_repository->update(
				$payout_id,
				array(
					'stripe_transfer_id' => $transfer->id,
					'status'             => 'completed',
					'processed_at'       => current_time( 'mysql' ),
				)
			);

			// Mark commissions as paid.
			if ( ! empty( $commission_ids ) ) {
				$this->commission_repository->mark_as_paid( $payout_id, $commission_ids );
			}

			$this->logger->info(
				'payout_completed',
				array(
					'payout_id'   => $payout_id,
					'vendor_id'   => $vendor_id,
					'amount'      => $net_amount,
					'transfer_id' => $transfer->id,
				),
				sprintf( 'Payout of %s completed', wc_price( $net_amount ) )
			);

			/**
			 * Fires after a payout is completed.
			 *
			 * @param int    $payout_id   Payout ID.
			 * @param int    $vendor_id   Vendor ID.
			 * @param float  $amount      Payout amount.
			 * @param string $transfer_id Stripe transfer ID.
			 */
			do_action( 'wcpe_payout_completed', $payout_id, $vendor_id, $net_amount, $transfer->id );

			return array(
				'payout_id'   => $payout_id,
				'transfer_id' => $transfer->id,
				'amount'      => $net_amount,
			);

		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			// Update payout as failed.
			$this->payout_repository->update_status( $payout_id, 'failed', $e->getMessage() );

			$this->logger->error(
				'payout_stripe_failed',
				array(
					'payout_id' => $payout_id,
					'vendor_id' => $vendor_id,
					'error'     => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Process payout for a vendor (gather approved commissions).
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return array|false Payout result or false on failure.
	 */
	public function process_vendor_payout( $vendor_id ) {
		// Check minimum payout.
		$minimum_payout = floatval( get_option( 'wcpe_minimum_payout', 50 ) );

		// Get approved commissions for vendor.
		$approved_commissions = $this->commission_repository->get_approved_for_vendor( $vendor_id );

		if ( empty( $approved_commissions ) ) {
			return false;
		}

		// Calculate total.
		$total_amount   = 0;
		$commission_ids = array();

		foreach ( $approved_commissions as $commission ) {
			$total_amount    += floatval( $commission->commission_amount );
			$commission_ids[] = $commission->id;
		}

		if ( $total_amount < $minimum_payout ) {
			$this->logger->info(
				'payout_below_minimum',
				array(
					'vendor_id' => $vendor_id,
					'amount'    => $total_amount,
					'minimum'   => $minimum_payout,
				)
			);
			return false;
		}

		return $this->create_payout( $vendor_id, $total_amount, $commission_ids );
	}

	/**
	 * Process all pending payouts (for scheduled cron).
	 *
	 * @return array Results of payout processing.
	 */
	public function process_scheduled_payouts() {
		$results = array(
			'processed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		$minimum_payout = floatval( get_option( 'wcpe_minimum_payout', 50 ) );

		// Get all vendors ready for payout.
		$vendors = $this->payout_repository->get_vendors_ready_for_payout( $minimum_payout );

		foreach ( $vendors as $vendor_data ) {
			// Check if vendor has Stripe connected.
			if ( ! $this->is_vendor_connected( $vendor_data->vendor_id ) ) {
				++$results['skipped'];
				continue;
			}

			$result = $this->process_vendor_payout( $vendor_data->vendor_id );

			if ( $result ) {
				++$results['processed'];
			} else {
				++$results['failed'];
			}
		}

		$this->logger->info(
			'scheduled_payouts_processed',
			$results
		);

		return $results;
	}

	/**
	 * Retrieve a Stripe account to check status.
	 *
	 * @param string $account_id Stripe account ID.
	 * @return object|false Stripe account object or false.
	 */
	public function get_stripe_account( $account_id ) {
		if ( ! $this->init_stripe() ) {
			return false;
		}

		try {
			return \Stripe\Account::retrieve( $account_id );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			$this->logger->error(
				'stripe_account_retrieve_failed',
				array(
					'account_id' => $account_id,
					'error'      => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Handle incoming Stripe webhook.
	 *
	 * @param string $payload   Raw webhook payload.
	 * @param string $signature Stripe signature header.
	 * @return bool
	 */
	public function handle_webhook( $payload, $signature ) {
		$webhook_secret = get_option( 'wcpe_stripe_webhook_secret', '' );

		if ( empty( $webhook_secret ) ) {
			$this->logger->warning( 'webhook_secret_not_configured', array() );
			return false;
		}

		if ( ! $this->init_stripe() ) {
			return false;
		}

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $signature, $webhook_secret );
		} catch ( \Stripe\Exception\SignatureVerificationException $e ) {
			$this->logger->error( 'webhook_signature_invalid', array( 'error' => $e->getMessage() ) );
			return false;
		}

		$this->logger->info(
			'webhook_received',
			array(
				'event_id'   => $event->id,
				'event_type' => $event->type,
			)
		);

		// Handle specific events.
		switch ( $event->type ) {
			case 'transfer.created':
			case 'transfer.updated':
				$this->handle_transfer_event( $event->data->object );
				break;

			case 'transfer.failed':
				$this->handle_transfer_failed( $event->data->object );
				break;

			case 'account.updated':
				$this->handle_account_updated( $event->data->object );
				break;

			case 'account.application.deauthorized':
				$this->handle_account_deauthorized( $event->data->object );
				break;
		}

		return true;
	}

	/**
	 * Handle transfer event.
	 *
	 * @param object $transfer Stripe transfer object.
	 * @return void
	 */
	private function handle_transfer_event( $transfer ) {
		$payout = $this->payout_repository->get_by_transfer_id( $transfer->id );

		if ( ! $payout ) {
			return;
		}

		// Update status based on transfer status.
		if ( 'paid' === $transfer->status || 'succeeded' === $transfer->status ) {
			$this->payout_repository->update_status( $payout->id, 'completed' );
		}
	}

	/**
	 * Handle transfer failed event.
	 *
	 * @param object $transfer Stripe transfer object.
	 * @return void
	 */
	private function handle_transfer_failed( $transfer ) {
		$payout = $this->payout_repository->get_by_transfer_id( $transfer->id );

		if ( ! $payout ) {
			return;
		}

		$error = $transfer->failure_message ?? 'Transfer failed';
		$this->payout_repository->update_status( $payout->id, 'failed', $error );

		// Revert commissions to approved status.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wcpe_commissions',
			array( 'status' => 'approved', 'payout_id' => null ),
			array( 'payout_id' => $payout->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$this->logger->error(
			'transfer_failed',
			array(
				'payout_id'   => $payout->id,
				'transfer_id' => $transfer->id,
				'error'       => $error,
			)
		);
	}

	/**
	 * Handle account updated event.
	 *
	 * @param object $account Stripe account object.
	 * @return void
	 */
	private function handle_account_updated( $account ) {
		// Find vendor by Stripe account ID.
		$users = get_users(
			array(
				'meta_key'   => '_wcpe_stripe_account_id',
				'meta_value' => $account->id,
			)
		);

		if ( empty( $users ) ) {
			return;
		}

		$vendor_id = $users[0]->ID;

		// Store account status.
		update_user_meta( $vendor_id, '_wcpe_stripe_charges_enabled', $account->charges_enabled ? 'yes' : 'no' );
		update_user_meta( $vendor_id, '_wcpe_stripe_payouts_enabled', $account->payouts_enabled ? 'yes' : 'no' );

		$this->logger->info(
			'stripe_account_updated',
			array(
				'vendor_id'       => $vendor_id,
				'charges_enabled' => $account->charges_enabled,
				'payouts_enabled' => $account->payouts_enabled,
			)
		);
	}

	/**
	 * Handle account deauthorized event.
	 *
	 * @param object $account Stripe account object.
	 * @return void
	 */
	private function handle_account_deauthorized( $account ) {
		// Find vendor by Stripe account ID.
		$users = get_users(
			array(
				'meta_key'   => '_wcpe_stripe_account_id',
				'meta_value' => $account->id,
			)
		);

		if ( empty( $users ) ) {
			return;
		}

		$vendor_id = $users[0]->ID;

		// Remove stored data.
		delete_user_meta( $vendor_id, '_wcpe_stripe_account_id' );
		delete_user_meta( $vendor_id, '_wcpe_stripe_connected_at' );
		delete_user_meta( $vendor_id, '_wcpe_stripe_charges_enabled' );
		delete_user_meta( $vendor_id, '_wcpe_stripe_payouts_enabled' );

		$this->logger->info(
			'stripe_account_deauthorized',
			array(
				'vendor_id'  => $vendor_id,
				'account_id' => $account->id,
			)
		);
	}

	/**
	 * Convert amount to Stripe's smallest currency unit (cents).
	 *
	 * @param float $amount Amount in dollars.
	 * @return int Amount in cents.
	 */
	private function to_stripe_amount( $amount ) {
		// Most currencies use 2 decimal places.
		$zero_decimal_currencies = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

		$currency = strtoupper( get_woocommerce_currency() );

		if ( in_array( $currency, $zero_decimal_currencies, true ) ) {
			return intval( $amount );
		}

		return intval( round( $amount * 100 ) );
	}
}
