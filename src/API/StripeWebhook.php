<?php
/**
 * Stripe Webhook Handler.
 *
 * @package WCPE\API
 */

namespace WCPE\API;

use WCPE\Services\StripeService;
use WCPE\Services\Logger;

/**
 * Handles incoming Stripe webhooks.
 *
 * @since 1.0.0
 */
class StripeWebhook {

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
		$this->stripe_service = new StripeService();
		$this->logger         = new Logger();
	}

	/**
	 * Register webhook endpoint.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Also register a standalone endpoint for webhooks.
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_action( 'template_redirect', array( $this, 'handle_rewrite' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'wcpe/v1',
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Webhooks are verified via signature.
			)
		);
	}

	/**
	 * Register rewrite rule for webhook endpoint.
	 *
	 * @return void
	 */
	public function register_rewrite() {
		add_rewrite_rule(
			'^wcpe-stripe-webhook/?$',
			'index.php?wcpe_stripe_webhook=1',
			'top'
		);

		add_rewrite_tag( '%wcpe_stripe_webhook%', '([0-9]+)' );
	}

	/**
	 * Handle rewrite endpoint.
	 *
	 * @return void
	 */
	public function handle_rewrite() {
		global $wp_query;

		if ( ! isset( $wp_query->query_vars['wcpe_stripe_webhook'] ) ) {
			return;
		}

		$this->process_webhook();
		exit;
	}

	/**
	 * Handle webhook via REST API.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$result = $this->process_webhook();

		if ( $result ) {
			return new \WP_REST_Response( array( 'success' => true ), 200 );
		}

		return new \WP_REST_Response( array( 'error' => 'Webhook processing failed' ), 400 );
	}

	/**
	 * Process incoming webhook.
	 *
	 * @return bool
	 */
	private function process_webhook() {
		$payload   = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		if ( empty( $payload ) || empty( $signature ) ) {
			$this->logger->warning( 'webhook_empty_payload_or_signature', array() );
			return false;
		}

		return $this->stripe_service->handle_webhook( $payload, $signature );
	}
}
