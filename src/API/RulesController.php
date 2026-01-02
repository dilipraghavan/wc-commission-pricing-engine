<?php
/**
 * Rules REST API Controller.
 *
 * @package WCPE\API
 */

namespace WCPE\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use WCPE\Database\Repositories\RuleRepository;
use WCPE\Services\Logger;

/**
 * REST API controller for commission rules.
 *
 * @since 1.0.0
 */
class RulesController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'rules';

	/**
	 * Rule repository.
	 *
	 * @var RuleRepository
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
		$this->repository = new RuleRepository();
		$this->logger     = new Logger();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /rules - List all rules.
		// POST /rules - Create a new rule.
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
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => $this->get_create_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// GET /rules/{id} - Get a single rule.
		// PUT /rules/{id} - Update a rule.
		// DELETE /rules/{id} - Delete a rule.
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
							'description' => __( 'Unique identifier for the rule.', 'wcpe' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => $this->get_update_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get a collection of rules.
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
			'rule_type' => $request->get_param( 'rule_type' ),
		);

		$result = $this->repository->get_paginated( $args );
		$items  = array();

		foreach ( $result['items'] as $rule ) {
			$items[] = $this->prepare_item_for_response( $rule, $request );
		}

		$response = rest_ensure_response( $items );
		$response = $this->add_pagination_headers( $response, $result['total'], $args['per_page'], $args['page'] );

		return $response;
	}

	/**
	 * Get a single rule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$rule = $this->repository->get( $request->get_param( 'id' ) );

		if ( ! $rule ) {
			return new WP_Error(
				'rest_rule_not_found',
				__( 'Rule not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_item_for_response( $rule, $request ) );
	}

	/**
	 * Create a new rule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = array(
			'name'               => $request->get_param( 'name' ),
			'rule_type'          => $request->get_param( 'rule_type' ),
			'target_id'          => $request->get_param( 'target_id' ),
			'calculation_method' => $request->get_param( 'calculation_method' ),
			'value'              => $request->get_param( 'value' ),
			'priority'           => $request->get_param( 'priority' ),
			'status'             => $request->get_param( 'status' ),
			'start_date'         => $request->get_param( 'start_date' ),
			'end_date'           => $request->get_param( 'end_date' ),
		);

		$rule_id = $this->repository->create( $data );

		if ( ! $rule_id ) {
			return new WP_Error(
				'rest_rule_creation_failed',
				__( 'Failed to create rule.', 'wcpe' ),
				array( 'status' => 500 )
			);
		}

		$rule = $this->repository->get( $rule_id );

		$this->logger->info(
			'rule_created_via_api',
			array( 'rule_id' => $rule_id, 'name' => $data['name'] )
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_rule_created', $rule_id, $rule );

		$response = rest_ensure_response( $this->prepare_item_for_response( $rule, $request ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a rule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$rule_id = $request->get_param( 'id' );
		$rule    = $this->repository->get( $rule_id );

		if ( ! $rule ) {
			return new WP_Error(
				'rest_rule_not_found',
				__( 'Rule not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		$data = array();

		$fields = array( 'name', 'rule_type', 'target_id', 'calculation_method', 'value', 'priority', 'status', 'start_date', 'end_date' );

		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error(
				'rest_no_fields_to_update',
				__( 'No fields to update.', 'wcpe' ),
				array( 'status' => 400 )
			);
		}

		$updated = $this->repository->update( $rule_id, $data );

		if ( ! $updated ) {
			return new WP_Error(
				'rest_rule_update_failed',
				__( 'Failed to update rule.', 'wcpe' ),
				array( 'status' => 500 )
			);
		}

		$rule = $this->repository->get( $rule_id );

		$this->logger->info(
			'rule_updated_via_api',
			array( 'rule_id' => $rule_id )
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_rule_updated', $rule_id, $rule );

		return rest_ensure_response( $this->prepare_item_for_response( $rule, $request ) );
	}

	/**
	 * Delete a rule.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$rule_id = $request->get_param( 'id' );
		$rule    = $this->repository->get( $rule_id );

		if ( ! $rule ) {
			return new WP_Error(
				'rest_rule_not_found',
				__( 'Rule not found.', 'wcpe' ),
				array( 'status' => 404 )
			);
		}

		$deleted = $this->repository->delete( $rule_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'rest_rule_delete_failed',
				__( 'Failed to delete rule.', 'wcpe' ),
				array( 'status' => 500 )
			);
		}

		$this->logger->info(
			'rule_deleted_via_api',
			array( 'rule_id' => $rule_id )
		);

		// Trigger outgoing webhook.
		do_action( 'wcpe_rule_deleted', $rule_id );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $rule_id ) );
	}

	/**
	 * Prepare rule for response.
	 *
	 * @param object          $rule    Rule object.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public function prepare_item_for_response( $rule, $request ) {
		$target_name = '';

		switch ( $rule->rule_type ) {
			case 'category':
				$term = get_term( $rule->target_id, 'product_cat' );
				$target_name = $term ? $term->name : '';
				break;
			case 'vendor':
				$user = get_user_by( 'id', $rule->target_id );
				$target_name = $user ? $user->display_name : '';
				break;
			case 'product':
				$target_name = get_the_title( $rule->target_id );
				break;
		}

		return array(
			'id'                 => (int) $rule->id,
			'name'               => $rule->name,
			'rule_type'          => $rule->rule_type,
			'target_id'          => (int) $rule->target_id,
			'target_name'        => $target_name,
			'calculation_method' => $rule->calculation_method,
			'value'              => (float) $rule->value,
			'priority'           => (int) $rule->priority,
			'status'             => $rule->status,
			'start_date'         => $this->format_date( $rule->start_date ),
			'end_date'           => $this->format_date( $rule->end_date ),
			'created_at'         => $this->format_date( $rule->created_at ),
			'updated_at'         => $this->format_date( $rule->updated_at ),
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
			'enum'              => array( 'active', 'inactive' ),
			'sanitize_callback' => 'sanitize_key',
		);

		$params['rule_type'] = array(
			'description'       => __( 'Filter by rule type.', 'wcpe' ),
			'type'              => 'string',
			'enum'              => array( 'global', 'category', 'vendor', 'product' ),
			'sanitize_callback' => 'sanitize_key',
		);

		return $params;
	}

	/**
	 * Get create parameters.
	 *
	 * @return array
	 */
	private function get_create_params() {
		return array(
			'name'               => array(
				'description'       => __( 'Rule name.', 'wcpe' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'rule_type'          => array(
				'description'       => __( 'Rule type.', 'wcpe' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array( 'global', 'category', 'vendor', 'product' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'target_id'          => array(
				'description'       => __( 'Target ID (category, vendor, or product ID).', 'wcpe' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'calculation_method' => array(
				'description'       => __( 'Calculation method.', 'wcpe' ),
				'type'              => 'string',
				'default'           => 'percentage',
				'enum'              => array( 'percentage', 'fixed' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'value'              => array(
				'description'       => __( 'Commission value.', 'wcpe' ),
				'type'              => 'number',
				'required'          => true,
				'sanitize_callback' => 'floatval',
			),
			'priority'           => array(
				'description'       => __( 'Rule priority (higher = more important).', 'wcpe' ),
				'type'              => 'integer',
				'default'           => 10,
				'sanitize_callback' => 'absint',
			),
			'status'             => array(
				'description'       => __( 'Rule status.', 'wcpe' ),
				'type'              => 'string',
				'default'           => 'active',
				'enum'              => array( 'active', 'inactive' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'start_date'         => array(
				'description'       => __( 'Start date (YYYY-MM-DD).', 'wcpe' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'end_date'           => array(
				'description'       => __( 'End date (YYYY-MM-DD).', 'wcpe' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get update parameters.
	 *
	 * @return array
	 */
	private function get_update_params() {
		$params = $this->get_create_params();

		// Make all fields optional for update.
		foreach ( $params as $key => $param ) {
			$params[ $key ]['required'] = false;
		}

		$params['id'] = array(
			'description' => __( 'Unique identifier for the rule.', 'wcpe' ),
			'type'        => 'integer',
			'required'    => true,
		);

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
			'title'      => 'rule',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier.', 'wcpe' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'               => array(
					'description' => __( 'Rule name.', 'wcpe' ),
					'type'        => 'string',
				),
				'rule_type'          => array(
					'description' => __( 'Rule type.', 'wcpe' ),
					'type'        => 'string',
					'enum'        => array( 'global', 'category', 'vendor', 'product' ),
				),
				'target_id'          => array(
					'description' => __( 'Target ID.', 'wcpe' ),
					'type'        => 'integer',
				),
				'target_name'        => array(
					'description' => __( 'Target name.', 'wcpe' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'calculation_method' => array(
					'description' => __( 'Calculation method.', 'wcpe' ),
					'type'        => 'string',
					'enum'        => array( 'percentage', 'fixed' ),
				),
				'value'              => array(
					'description' => __( 'Commission value.', 'wcpe' ),
					'type'        => 'number',
				),
				'priority'           => array(
					'description' => __( 'Rule priority.', 'wcpe' ),
					'type'        => 'integer',
				),
				'status'             => array(
					'description' => __( 'Rule status.', 'wcpe' ),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
				),
				'start_date'         => array(
					'description' => __( 'Start date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'end_date'           => array(
					'description' => __( 'End date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'created_at'         => array(
					'description' => __( 'Creation date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'updated_at'         => array(
					'description' => __( 'Last update date.', 'wcpe' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);
	}
}
