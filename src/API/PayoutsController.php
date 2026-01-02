<?php
/**
 * Payouts REST API Controller.
 *
 * @package WCPE\API
 */

namespace WCPE\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use WCPE\Database\Repositories\PayoutRepository;
use WCPE\Services\StripeService;
use WCPE\Services\Logger;

/**
 * REST API controller for payouts.
 *
 * @since 1.0.0
 */
class PayoutsController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'payouts';

	/**
	 * Payout repository.
	 *
	 * @var PayoutRepository
	 */
	private $repository;

	/**
	 * Stripe service.
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
		$this->repository     = new PayoutRepository();
		$this->stripe_service = new StripeService();
		$this->logger         = new Logger();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /payouts - List all payouts.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_api_key' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /payouts/{id} - Get a single payout.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the payout.', 'wcpe' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /payouts/summary - Get payout statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);

		// GET /payouts/ready - Get vendors ready for payout.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ready',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ready_vendors' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);

		// POST /payouts/process/{vendor_id} - Process payout for a vendor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/process/(?P<vendor_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_payout' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'vendor_id' => array(
						'description' => __( 'Vendor user ID.', 'wcpe' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// GET /payouts/vendor/{id} - Get payouts for a vendor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/vendor/(?P<vendor_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_vendor_payouts' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'vendor_id' => array(
						'description' => __( 'Vendor user ID.', 'wcpe' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Get a collection of payouts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$args = array(
			'per_page'  => $request->get_param( 'per_page' ),
			'page'      => $request->get_param( 'page' ),
			'orderby'   => $request->get_param( 'orderby' ),
			'order'     => strtoupper( $request->get_param( 'order' ) ),
			'status'    => $request->get_param( 'status' ),
			'vendor_id' => $request->get_param( 'vendor_id' ),
		);

		$result = $this->repository->get_paginated( $args );
		$items  = array();

		foreach ( $result['items'] as $payout ) {
			$items[] = $this->prepare_item_for_response( $payout, $request );
		}

		$response = rest_ensure_response( $items );
		$response = $this->add_pagination_headers( $response, $result['total'], $args['per_page'], $args['page'] );

		return $response;
	}

	/**
	 * Get a single payout.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$payout = $this->repository->get( $request->get_param( 'id' ) );

		if ( ! $payout ) {
			return new WP_Error(
				'rest_payout_not_found',
				__( 'Payout not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_item_for_response( $payout, $request ) );
	}

	/**
	 * Get payout summary statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_summary( $request ) {
		$summary = $this->repository->get_summary();

		return rest_ensure_response( $summary );
	}

	/**
	 * Get vendors ready for payout.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_ready_vendors( $request ) {
		$minimum_payout = floatval( get_option( 'wcpe_minimum_payout', 50 ) );
		$vendors        = $this->repository->get_vendors_ready_for_payout( $minimum_payout );

		$items = array();

		foreach ( $vendors as $vendor_data ) {
			$user         = get_user_by( 'id', $vendor_data->vendor_id );
			$is_connected = $this->stripe_service->is_vendor_connected( $vendor_data->vendor_id );

			$items[] = array(
				'vendor_id'        => (int) $vendor_data->vendor_id,
				'vendor_name'      => $user ? $user->display_name : '',
				'vendor_email'     => $user ? $user->user_email : '',
				'total_amount'     => (float) $vendor_data->total_amount,
				'commission_count' => (int) $vendor_data->commission_count,
				'stripe_connected' => $is_connected,
				'can_payout'       => $is_connected && $this->stripe_service->is_configured(),
			);
		}

		return rest_ensure_response(
			array(
				'minimum_payout' => $minimum_payout,
				'vendors'        => $items,
			)
		);
	}

	/**
	 * Process payout for a vendor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_payout( $request ) {
		$vendor_id = $request->get_param( 'vendor_id' );

		// Check if Stripe is configured.
		if ( ! $this->stripe_service->is_configured() ) {
			return new WP_Error(
				'rest_stripe_not_configured',
				__( 'Stripe Connect is not configured.', 'wcpe' ),
				array( 'status' => 400 )
			);
		}

		// Check if vendor has Stripe connected.
		if ( ! $this->stripe_service->is_vendor_connected( $vendor_id ) ) {
			return new WP_Error(
				'rest_vendor_not_connected',
				__( 'Vendor has not connected their Stripe account.', 'wcpe' ),
				array( 'status' => 400 )
			);
		}

		// Process the payout.
		$result = $this->stripe_service->process_vendor_payout( $vendor_id );

		if ( ! $result ) {
			return new WP_Error(
				'rest_payout_failed',
				__( 'Failed to process payout. Check logs for details.', 'wcpe' ),
				array( 'status' => 500 )
			);
		}

		$this->logger->info(
			'payout_processed_via_api',
			array(
				'vendor_id'   => $vendor_id,
				'payout_id'   => $result['payout_id'],
				'amount'      => $result['amount'],
			)
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_payout_processed', $result['payout_id'], $vendor_id, $result['amount'] );

		return rest_ensure_response(
			array(
				'success'     => true,
				'payout_id'   => $result['payout_id'],
				'transfer_id' => $result['transfer_id'],
				'amount'      => $result['amount'],
			)
		);
	}

	/**
	 * Get payouts for a specific vendor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_vendor_payouts( $request ) {
		$vendor_id = $request->get_param( 'vendor_id' );
		$payouts   = $this->repository->get_by_vendor( $vendor_id );
		$items     = array();

		foreach ( $payouts as $payout ) {
			$items[] = $this->prepare_item_for_response( $payout, $request );
		}

		// Calculate totals.
		$total_amount = array_reduce(
			$payouts,
			function ( $carry, $item ) {
				return 'completed' === $item->status ? $carry + floatval( $item->amount ) : $carry;
			},
			0
		);

		return rest_ensure_response(
			array(
				'vendor_id'    => $vendor_id,
				'total_paid'   => round( $total_amount, 2 ),
				'count'        => count( $items ),
				'payouts'      => $items,
			)
		);
	}

	/**
	 * Prepare payout for response.
	 *
	 * @param object          $payout  Payout object.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public function prepare_item_for_response( $payout, $request ) {
		$vendor = get_user_by( 'id', $payout->vendor_id );

		return array(
			'id'                 => (int) $payout->id,
			'vendor_id'          => (int) $payout->vendor_id,
			'vendor_name'        => $vendor ? $vendor->display_name : '',
			'amount'             => (float) $payout->amount,
			'fee_amount'         => (float) $payout->fee_amount,
			'net_amount'         => (float) $payout->net_amount,
			'currency'           => $payout->currency,
			'status'             => $payout->status,
			'stripe_transfer_id' => $payout->stripe_transfer_id,
			'error_message'      => $payout->error_message,
			'created_at'         => $this->format_date( $payout->created_at ),
			'processed_at'       => $this->format_date( $payout->processed_at ),
		);
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['status'] = array(
			'description'       => __( 'Filter by status.', 'wcpe' ),
			'type'              => 'string',
			'enum'              => array( 'pending', 'processing', 'completed', 'failed' ),
			'sanitize_callback' => 'sanitize_key',
		);

		$params['vendor_id'] = array(
			'description'       => __( 'Filter by vendor ID.', 'wcpe' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		$params['orderby']['default'] = 'created_at';

		return $params;
	}

	/**
	 * Get item schema.
	 *
	 * @return array
	 */
	public function get_public_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payout',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'vendor_id'          => array(
					'description' => __( 'Vendor user ID.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'vendor_name'        => array(
					'description' => __( 'Vendor name.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'amount'             => array(
					'description' => __( 'Gross payout amount.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'fee_amount'         => array(
					'description' => __( 'Fee amount.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'net_amount'         => array(
					'description' => __( 'Net payout amount.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'currency'           => array(
					'description' => __( 'Currency code.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'             => array(
					'description' => __( 'Payout status.', 'wcpe' ),
					'type'        => 'string',
					'enum'        => array( 'pending', 'processing', 'completed', 'failed' ),
					'readonly'    => true,
				),
				'stripe_transfer_id' => array(
					'description' => __( 'Stripe transfer ID.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'created_at'         => array(
					'description' => __( 'Creation date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'processed_at'       => array(
					'description' => __( 'Processing date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}
}
