<?php
/**
 * Base REST API Controller.
 *
 * @package WCPE\API
 */

namespace WCPE\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Base controller for REST API endpoints.
 *
 * @since 1.0.0
 */
abstract class BaseController extends WP_REST_Controller {

	/**
	 * Namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpe/v1';

	/**
	 * Check if API is enabled.
	 *
	 * @return bool
	 */
	protected function is_api_enabled() {
		return 'yes' === get_option( 'wcpe_api_enabled', 'yes' );
	}

	/**
	 * Verify API key authentication.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_api_key( $request ) {
		// Check if API is enabled.
		if ( ! $this->is_api_enabled() ) {
			return new WP_Error(
				'rest_api_disabled',
				__( 'The Commission Engine API is currently disabled.', 'wcpe' ),
				array( 'status' => 403 )
			);
		}

		// Allow if user is logged in with proper capabilities.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Check API key in header.
		$api_key = $request->get_header( 'X-WCPE-API-Key' );

		if ( empty( $api_key ) ) {
			// Also check query parameter as fallback.
			$api_key = $request->get_param( 'api_key' );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'rest_missing_api_key',
				__( 'API key is required. Include it in the X-WCPE-API-Key header.', 'wcpe' ),
				array( 'status' => 401 )
			);
		}

		$stored_key = get_option( 'wcpe_api_key', '' );

		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $api_key ) ) {
			return new WP_Error(
				'rest_invalid_api_key',
				__( 'Invalid API key.', 'wcpe' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Check if user can manage commissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_admin_permission( $request ) {
		$api_check = $this->check_api_key( $request );

		if ( is_wp_error( $api_check ) ) {
			return $api_check;
		}

		return true;
	}

	/**
	 * Prepare pagination data for response.
	 *
	 * @param int $total    Total items.
	 * @param int $per_page Items per page.
	 * @param int $page     Current page.
	 * @return array
	 */
	protected function get_pagination_data( $total, $per_page, $page ) {
		return array(
			'total'       => $total,
			'per_page'    => $per_page,
			'current_page'=> $page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/**
	 * Add pagination headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param int              $total    Total items.
	 * @param int              $per_page Items per page.
	 * @param int              $page     Current page.
	 * @return WP_REST_Response
	 */
	protected function add_pagination_headers( $response, $total, $per_page, $page ) {
		$total_pages = ceil( $total / $per_page );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	/**
	 * Format date for API response.
	 *
	 * @param string $date Date string.
	 * @return string|null ISO 8601 formatted date or null.
	 */
	protected function format_date( $date ) {
		if ( empty( $date ) ) {
			return null;
		}

		$timestamp = strtotime( $date );
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Get standard collection parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'wcpe' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items per page.', 'wcpe' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'orderby'  => array(
				'description'       => __( 'Sort collection by attribute.', 'wcpe' ),
				'type'              => 'string',
				'default'           => 'id',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'description'       => __( 'Order sort attribute ascending or descending.', 'wcpe' ),
				'type'              => 'string',
				'default'           => 'desc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
