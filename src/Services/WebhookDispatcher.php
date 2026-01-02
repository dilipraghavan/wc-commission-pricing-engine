<?php
/**
 * Outgoing Webhook Dispatcher.
 *
 * @package WCPE\Services
 */

namespace WCPE\Services;

/**
 * Dispatches outgoing webhooks to registered endpoints.
 *
 * @since 1.0.0
 */
class WebhookDispatcher {

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
		$this->logger = new Logger();
	}

	/**
	 * Register hooks for outgoing webhooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Commission events.
		add_action( 'wcpe_commission_created', array( $this, 'dispatch_commission_created' ), 10, 4 );
		add_action( 'wcpe_commission_status_changed', array( $this, 'dispatch_commission_status_changed' ), 10, 3 );
		add_action( 'wcpe_commissions_bulk_approved', array( $this, 'dispatch_commissions_bulk_approved' ), 10, 1 );

		// Rule events.
		add_action( 'wcpe_rule_created', array( $this, 'dispatch_rule_created' ), 10, 2 );
		add_action( 'wcpe_rule_updated', array( $this, 'dispatch_rule_updated' ), 10, 2 );
		add_action( 'wcpe_rule_deleted', array( $this, 'dispatch_rule_deleted' ), 10, 1 );

		// Payout events.
		add_action( 'wcpe_payout_completed', array( $this, 'dispatch_payout_completed' ), 10, 4 );
		add_action( 'wcpe_payout_processed', array( $this, 'dispatch_payout_processed' ), 10, 3 );
	}

	/**
	 * Dispatch commission created webhook.
	 *
	 * @param int    $commission_id Commission ID.
	 * @param int    $order_id      Order ID.
	 * @param float  $amount        Commission amount.
	 * @param object $rule          Applied rule.
	 * @return void
	 */
	public function dispatch_commission_created( $commission_id, $order_id, $amount, $rule ) {
		$this->dispatch(
			'commission.created',
			array(
				'commission_id' => $commission_id,
				'order_id'      => $order_id,
				'amount'        => $amount,
				'rule_id'       => $rule->id ?? null,
				'rule_name'     => $rule->name ?? 'Default',
			)
		);
	}

	/**
	 * Dispatch commission status changed webhook.
	 *
	 * @param int    $commission_id Commission ID.
	 * @param string $new_status    New status.
	 * @param string $old_status    Old status.
	 * @return void
	 */
	public function dispatch_commission_status_changed( $commission_id, $new_status, $old_status ) {
		$this->dispatch(
			'commission.status_changed',
			array(
				'commission_id' => $commission_id,
				'new_status'    => $new_status,
				'old_status'    => $old_status,
			)
		);
	}

	/**
	 * Dispatch commissions bulk approved webhook.
	 *
	 * @param array $ids Commission IDs.
	 * @return void
	 */
	public function dispatch_commissions_bulk_approved( $ids ) {
		$this->dispatch(
			'commission.bulk_approved',
			array(
				'commission_ids' => $ids,
				'count'          => count( $ids ),
			)
		);
	}

	/**
	 * Dispatch rule created webhook.
	 *
	 * @param int    $rule_id Rule ID.
	 * @param object $rule    Rule object.
	 * @return void
	 */
	public function dispatch_rule_created( $rule_id, $rule ) {
		$this->dispatch(
			'rule.created',
			array(
				'rule_id'   => $rule_id,
				'name'      => $rule->name,
				'rule_type' => $rule->rule_type,
				'value'     => $rule->value,
			)
		);
	}

	/**
	 * Dispatch rule updated webhook.
	 *
	 * @param int    $rule_id Rule ID.
	 * @param object $rule    Rule object.
	 * @return void
	 */
	public function dispatch_rule_updated( $rule_id, $rule ) {
		$this->dispatch(
			'rule.updated',
			array(
				'rule_id'   => $rule_id,
				'name'      => $rule->name,
				'rule_type' => $rule->rule_type,
				'value'     => $rule->value,
				'status'    => $rule->status,
			)
		);
	}

	/**
	 * Dispatch rule deleted webhook.
	 *
	 * @param int $rule_id Rule ID.
	 * @return void
	 */
	public function dispatch_rule_deleted( $rule_id ) {
		$this->dispatch(
			'rule.deleted',
			array(
				'rule_id' => $rule_id,
			)
		);
	}

	/**
	 * Dispatch payout completed webhook.
	 *
	 * @param int    $payout_id   Payout ID.
	 * @param int    $vendor_id   Vendor ID.
	 * @param float  $amount      Payout amount.
	 * @param string $transfer_id Stripe transfer ID.
	 * @return void
	 */
	public function dispatch_payout_completed( $payout_id, $vendor_id, $amount, $transfer_id ) {
		$this->dispatch(
			'payout.completed',
			array(
				'payout_id'          => $payout_id,
				'vendor_id'          => $vendor_id,
				'amount'             => $amount,
				'stripe_transfer_id' => $transfer_id,
			)
		);
	}

	/**
	 * Dispatch payout processed webhook.
	 *
	 * @param int   $payout_id Payout ID.
	 * @param int   $vendor_id Vendor ID.
	 * @param float $amount    Payout amount.
	 * @return void
	 */
	public function dispatch_payout_processed( $payout_id, $vendor_id, $amount ) {
		$this->dispatch(
			'payout.processed',
			array(
				'payout_id' => $payout_id,
				'vendor_id' => $vendor_id,
				'amount'    => $amount,
			)
		);
	}

	/**
	 * Dispatch webhook to all registered endpoints.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 * @return void
	 */
	public function dispatch( $event, $data ) {
		$webhooks = $this->get_active_webhooks();

		if ( empty( $webhooks ) ) {
			return;
		}

		$payload = array(
			'event'      => $event,
			'data'       => $data,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'webhook_id' => wp_generate_uuid4(),
		);

		foreach ( $webhooks as $webhook ) {
			// Check if webhook is subscribed to this event.
			if ( ! $this->is_subscribed( $webhook, $event ) ) {
				continue;
			}

			// Dispatch asynchronously using WP Cron.
			wp_schedule_single_event(
				time(),
				'wcpe_dispatch_webhook',
				array( $webhook['id'], $webhook['url'], $payload, $webhook['secret'] )
			);
		}
	}

	/**
	 * Send webhook to a URL.
	 *
	 * @param int    $webhook_id Webhook ID.
	 * @param string $url        Webhook URL.
	 * @param array  $payload    Webhook payload.
	 * @param string $secret     Webhook secret for signing.
	 * @return bool
	 */
	public function send_webhook( $webhook_id, $url, $payload, $secret = '' ) {
		$body = wp_json_encode( $payload );

		$headers = array(
			'Content-Type'       => 'application/json',
			'X-WCPE-Event'       => $payload['event'],
			'X-WCPE-Webhook-ID'  => $payload['webhook_id'],
			'X-WCPE-Delivery-ID' => wp_generate_uuid4(),
		);

		// Sign the payload if secret is set.
		if ( ! empty( $secret ) ) {
			$signature            = hash_hmac( 'sha256', $body, $secret );
			$headers['X-WCPE-Signature'] = 't=' . time() . ',v1=' . $signature;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers'   => $headers,
				'body'      => $body,
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		$success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300;

		// Log the delivery.
		$this->log_delivery( $webhook_id, $payload, $response, $success );

		return $success;
	}

	/**
	 * Get active webhooks.
	 *
	 * @return array
	 */
	public function get_active_webhooks() {
		$webhooks = get_option( 'wcpe_outgoing_webhooks', array() );

		return array_filter(
			$webhooks,
			function ( $webhook ) {
				return isset( $webhook['status'] ) && 'active' === $webhook['status'];
			}
		);
	}

	/**
	 * Check if webhook is subscribed to an event.
	 *
	 * @param array  $webhook Webhook data.
	 * @param string $event   Event name.
	 * @return bool
	 */
	private function is_subscribed( $webhook, $event ) {
		// If no events specified, subscribe to all.
		if ( empty( $webhook['events'] ) ) {
			return true;
		}

		// Check for exact match.
		if ( in_array( $event, $webhook['events'], true ) ) {
			return true;
		}

		// Check for wildcard match (e.g., 'commission.*').
		$event_prefix = explode( '.', $event )[0];
		if ( in_array( $event_prefix . '.*', $webhook['events'], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Log webhook delivery.
	 *
	 * @param int   $webhook_id Webhook ID.
	 * @param array $payload    Webhook payload.
	 * @param mixed $response   Response from wp_remote_post.
	 * @param bool  $success    Whether delivery was successful.
	 * @return void
	 */
	private function log_delivery( $webhook_id, $payload, $response, $success ) {
		$log_data = array(
			'webhook_id' => $webhook_id,
			'event'      => $payload['event'],
			'success'    => $success,
		);

		if ( is_wp_error( $response ) ) {
			$log_data['error'] = $response->get_error_message();
		} else {
			$log_data['response_code'] = wp_remote_retrieve_response_code( $response );
		}

		if ( $success ) {
			$this->logger->info( 'outgoing_webhook_delivered', $log_data );
		} else {
			$this->logger->error( 'outgoing_webhook_failed', $log_data );
		}
	}

	/**
	 * Save a webhook.
	 *
	 * @param array $data Webhook data.
	 * @return int Webhook ID.
	 */
	public function save_webhook( $data ) {
		$webhooks = get_option( 'wcpe_outgoing_webhooks', array() );

		if ( isset( $data['id'] ) && $data['id'] > 0 ) {
			// Update existing.
			$id = $data['id'];
			foreach ( $webhooks as $key => $webhook ) {
				if ( $webhook['id'] === $id ) {
					$webhooks[ $key ] = array_merge( $webhook, $data );
					break;
				}
			}
		} else {
			// Create new.
			$id = count( $webhooks ) > 0 ? max( array_column( $webhooks, 'id' ) ) + 1 : 1;
			$data['id']         = $id;
			$data['created_at'] = current_time( 'mysql' );
			$webhooks[]         = $data;
		}

		update_option( 'wcpe_outgoing_webhooks', $webhooks );

		return $id;
	}

	/**
	 * Delete a webhook.
	 *
	 * @param int $id Webhook ID.
	 * @return bool
	 */
	public function delete_webhook( $id ) {
		$webhooks = get_option( 'wcpe_outgoing_webhooks', array() );

		$webhooks = array_filter(
			$webhooks,
			function ( $webhook ) use ( $id ) {
				return $webhook['id'] !== $id;
			}
		);

		update_option( 'wcpe_outgoing_webhooks', array_values( $webhooks ) );

		return true;
	}

	/**
	 * Get a webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 * @return array|null
	 */
	public function get_webhook( $id ) {
		$webhooks = get_option( 'wcpe_outgoing_webhooks', array() );

		foreach ( $webhooks as $webhook ) {
			if ( $webhook['id'] === $id ) {
				return $webhook;
			}
		}

		return null;
	}

	/**
	 * Get all webhooks.
	 *
	 * @return array
	 */
	public function get_all_webhooks() {
		return get_option( 'wcpe_outgoing_webhooks', array() );
	}

	/**
	 * Get available webhook events.
	 *
	 * @return array
	 */
	public static function get_available_events() {
		return array(
			'commission.created'        => __( 'Commission Created', 'wcpe' ),
			'commission.status_changed' => __( 'Commission Status Changed', 'wcpe' ),
			'commission.bulk_approved'  => __( 'Commissions Bulk Approved', 'wcpe' ),
			'rule.created'              => __( 'Rule Created', 'wcpe' ),
			'rule.updated'              => __( 'Rule Updated', 'wcpe' ),
			'rule.deleted'              => __( 'Rule Deleted', 'wcpe' ),
			'payout.completed'          => __( 'Payout Completed', 'wcpe' ),
			'payout.processed'          => __( 'Payout Processed', 'wcpe' ),
		);
	}
}
