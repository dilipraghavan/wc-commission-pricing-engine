<?php
/**
 * Commissions REST API Controller.
 *
 * @package WCPE\API
 */

namespace WCPE\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use WCPE\Database\Repositories\CommissionRepository;
use WCPE\Services\Logger;

/**
 * REST API controller for commissions.
 *
 * @since 1.0.0
 */
class CommissionsController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'commissions';

	/**
	 * Commission repository.
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
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /commissions - List all commissions.
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

		// GET /commissions/{id} - Get a single commission.
		// PUT /commissions/{id} - Update commission status.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_api_key' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Unique identifier for the commission.', 'wcpe' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'id'     => array(
							'description' => __( 'Unique identifier for the commission.', 'wcpe' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'status' => array(
							'description'       => __( 'Commission status.', 'wcpe' ),
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'pending', 'approved', 'paid', 'cancelled', 'refunded' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /commissions/summary - Get commission statistics.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);

		// GET /commissions/vendor/{id} - Get commissions for a vendor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/vendor/(?P<vendor_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_vendor_commissions' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'vendor_id' => array(
						'description' => __( 'Vendor user ID.', 'wcpe' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'status'    => array(
						'description'       => __( 'Filter by status.', 'wcpe' ),
						'type'              => 'string',
						'enum'              => array( 'pending', 'approved', 'paid', 'cancelled', 'refunded' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// POST /commissions/bulk-approve - Bulk approve commissions.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk-approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_approve' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'ids' => array(
						'description' => __( 'Array of commission IDs to approve.', 'wcpe' ),
						'type'        => 'array',
						'required'    => true,
						'items'       => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Get a collection of commissions.
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

		foreach ( $result['items'] as $commission ) {
			$items[] = $this->prepare_item_for_response( $commission, $request );
		}

		$response = rest_ensure_response( $items );
		$response = $this->add_pagination_headers( $response, $result['total'], $args['per_page'], $args['page'] );

		return $response;
	}

	/**
	 * Get a single commission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$commission = $this->repository->get( $request->get_param( 'id' ) );

		if ( ! $commission ) {
			return new WP_Error(
				'rest_commission_not_found',
				__( 'Commission not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_item_for_response( $commission, $request ) );
	}

	/**
	 * Update commission status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$commission_id = $request->get_param( 'id' );
		$commission    = $this->repository->get( $commission_id );

		if ( ! $commission ) {
			return new WP_Error(
				'rest_commission_not_found',
				__( 'Commission not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		$new_status = $request->get_param( 'status' );
		$old_status = $commission->status;

		$updated = $this->repository->update_status( $commission_id, $new_status );

		if ( ! $updated ) {
			return new WP_Error(
				'rest_commission_update_failed',
				__( 'Failed to update commission.', 'wcpe' ),
				array( 'status' => 500 )
			);
		}

		$commission = $this->repository->get( $commission_id );

		$this->logger->info(
			'commission_status_updated_via_api',
			array(
				'commission_id' => $commission_id,
				'old_status'    => $old_status,
				'new_status'    => $new_status,
			)
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_commission_status_changed', $commission_id, $new_status, $old_status );

		return rest_ensure_response( $this->prepare_item_for_response( $commission, $request ) );
	}

	/**
	 * Get commission summary statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_summary( $request ) {
		$summary = $this->repository->get_summary();

		return rest_ensure_response( $summary );
	}

	/**
	 * Get commissions for a specific vendor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_vendor_commissions( $request ) {
		$vendor_id = $request->get_param( 'vendor_id' );
		$status    = $request->get_param( 'status' );

		$commissions = $this->repository->get_by_vendor( $vendor_id, $status );
		$items       = array();

		foreach ( $commissions as $commission ) {
			$items[] = $this->prepare_item_for_response( $commission, $request );
		}

		// Calculate totals.
		$total_amount = array_reduce(
			$commissions,
			function ( $carry, $item ) {
				return $carry + floatval( $item->commission_amount );
			},
			0
		);

		return rest_ensure_response(
			array(
				'vendor_id'    => $vendor_id,
				'total_amount' => round( $total_amount, 2 ),
				'count'        => count( $items ),
				'commissions'  => $items,
			)
		);
	}

	/**
	 * Bulk approve commissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function bulk_approve( $request ) {
		$ids   = $request->get_param( 'ids' );
		$count = $this->repository->bulk_update_status( $ids, 'approved' );

		$this->logger->info(
			'commissions_bulk_approved_via_api',
			array(
				'ids'   => $ids,
				'count' => $count,
			)
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_commissions_bulk_approved', $ids );

		return rest_ensure_response(
			array(
				'approved' => $count,
				'ids'      => $ids,
			)
		);
	}

	/**
	 * Prepare commission for response.
	 *
	 * @param object          $commission Commission object.
	 * @param WP_REST_Request $request    Request object.
	 * @return array
	 */
	public function prepare_item_for_response( $commission, $request ) {
		$product = wc_get_product( $commission->product_id );
		$vendor  = get_user_by( 'id', $commission->vendor_id );

		return array(
			'id'                => (int) $commission->id,
			'order_id'          => (int) $commission->order_id,
			'order_item_id'     => (int) $commission->order_item_id,
			'product_id'        => (int) $commission->product_id,
			'product_name'      => $product ? $product->get_name() : '',
			'vendor_id'         => (int) $commission->vendor_id,
			'vendor_name'       => $vendor ? $vendor->display_name : '',
			'rule_id'           => $commission->rule_id ? (int) $commission->rule_id : null,
			'order_total'       => (float) $commission->order_total,
			'commission_amount' => (float) $commission->commission_amount,
			'commission_rate'   => $commission->commission_rate ? (float) $commission->commission_rate : null,
			'status'            => $commission->status,
			'payout_id'         => $commission->payout_id ? (int) $commission->payout_id : null,
			'created_at'        => $this->format_date( $commission->created_at ),
			'updated_at'        => $this->format_date( $commission->updated_at ),
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
			'enum'              => array( 'pending', 'approved', 'paid', 'cancelled', 'refunded' ),
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
			'title'      => 'commission',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'Unique identifier.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'order_id'          => array(
					'description' => __( 'WooCommerce order ID.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'product_id'        => array(
					'description' => __( 'Product ID.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'product_name'      => array(
					'description' => __( 'Product name.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'vendor_id'         => array(
					'description' => __( 'Vendor user ID.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'vendor_name'       => array(
					'description' => __( 'Vendor name.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'order_total'       => array(
					'description' => __( 'Order line total.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'commission_amount' => array(
					'description' => __( 'Commission amount.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'commission_rate'   => array(
					'description' => __( 'Commission rate percentage.', 'wcpe' ),
					'type'        => 'number',
					'readonly'    => true,
				),
				'status'            => array(
					'description' => __( 'Commission status.', 'wcpe' ),
					'type'        => 'string',
					'enum'        => array( 'pending', 'approved', 'paid', 'cancelled', 'refunded' ),
				),
				'created_at'        => array(
					'description' => __( 'Creation date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}
}
