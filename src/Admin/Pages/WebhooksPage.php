<?php
/**
 * Webhooks Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

use WCPE\Services\Logger;
use WCPE\Services\WebhookDispatcher;

/**
 * Handles the Webhooks admin page.
 *
 * @since 1.0.0
 */
class WebhooksPage {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Webhook dispatcher instance.
	 *
	 * @var WebhookDispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger     = new Logger();
		$this->dispatcher = new WebhookDispatcher();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		// Handle form submissions.
		$this->handle_actions();

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'incoming'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Webhooks', 'wcpe' ); ?></h1>

			<?php if ( 'outgoing' === $current_tab ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&action=new' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Add Webhook', 'wcpe' ); ?>
				</a>
			<?php endif; ?>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=incoming' ) ); ?>"
				   class="nav-tab <?php echo 'incoming' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Incoming (Stripe)', 'wcpe' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing' ) ); ?>"
				   class="nav-tab <?php echo 'outgoing' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Outgoing', 'wcpe' ); ?>
				</a>
			</nav>

			<div class="wcpe-webhooks-content">
				<?php
				if ( 'outgoing' === $current_tab ) {
					$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

					if ( 'new' === $action || 'edit' === $action ) {
						$this->render_outgoing_form();
					} else {
						$this->render_outgoing_list();
					}
				} else {
					$this->render_incoming_tab();
				}
				?>
			</div>
		</div>

		<style>
			.wcpe-webhooks-content {
				margin-top: 20px;
			}
			.wcpe-webhook-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				margin-bottom: 20px;
			}
			.wcpe-webhook-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.wcpe-setup-steps {
				margin-left: 20px;
			}
			.wcpe-setup-steps li {
				margin-bottom: 10px;
				line-height: 1.6;
			}
			.wcpe-event-list {
				margin: 10px 0 0 20px;
				list-style: disc;
			}
			.wcpe-event-list li {
				margin-bottom: 5px;
			}
			#wcpe-webhook-url {
				padding: 8px 12px;
				background: #f0f0f1;
				display: inline-block;
				margin-right: 10px;
				word-break: break-all;
			}
			.wcpe-events-checkboxes {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
				gap: 10px;
				margin-top: 10px;
			}
			.wcpe-events-checkboxes label {
				display: flex;
				align-items: center;
				gap: 5px;
			}
		</style>
		<?php
	}

	/**
	 * Handle form actions.
	 *
	 * @return void
	 */
	private function handle_actions() {
		// Delete webhook.
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['webhook_id'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wcpe_delete_webhook' ) ) {
				return;
			}

			$this->dispatcher->delete_webhook( absint( $_GET['webhook_id'] ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&deleted=1' ) );
			exit;
		}

		// Save webhook.
		if ( isset( $_POST['wcpe_save_webhook'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_POST['wcpe_webhook_nonce'] ?? '' ), 'wcpe_save_webhook' ) ) {
				return;
			}

			$data = array(
				'id'     => isset( $_POST['webhook_id'] ) ? absint( $_POST['webhook_id'] ) : 0,
				'name'   => sanitize_text_field( $_POST['webhook_name'] ?? '' ),
				'url'    => esc_url_raw( $_POST['webhook_url'] ?? '' ),
				'secret' => sanitize_text_field( $_POST['webhook_secret'] ?? '' ),
				'status' => sanitize_key( $_POST['webhook_status'] ?? 'active' ),
				'events' => isset( $_POST['webhook_events'] ) ? array_map( 'sanitize_key', $_POST['webhook_events'] ) : array(),
			);

			$this->dispatcher->save_webhook( $data );
			wp_safe_redirect( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&saved=1' ) );
			exit;
		}
	}

	/**
	 * Render notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Webhook saved.', 'wcpe' ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Webhook deleted.', 'wcpe' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Render incoming webhooks tab (Stripe).
	 *
	 * @return void
	 */
	private function render_incoming_tab() {
		$webhook_url     = rest_url( 'wcpe/v1/stripe/webhook' );
		$webhook_secret  = get_option( 'wcpe_stripe_webhook_secret', '' );
		$is_configured   = ! empty( $webhook_secret );
		$recent_webhooks = $this->get_recent_webhook_events();
		?>

		<!-- Webhook Endpoint Info -->
		<div class="wcpe-webhook-section">
			<h2><?php esc_html_e( 'Stripe Webhook Endpoint', 'wcpe' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure this URL in your Stripe Dashboard to receive webhook events for payout status updates.', 'wcpe' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'wcpe' ); ?></th>
					<td>
						<code id="wcpe-webhook-url"><?php echo esc_html( $webhook_url ); ?></code>
						<button type="button" class="button wcpe-copy-btn" data-target="wcpe-webhook-url">
							<?php esc_html_e( 'Copy', 'wcpe' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wcpe' ); ?></th>
					<td>
						<?php if ( $is_configured ) : ?>
							<span class="wcpe-status wcpe-status--active">
								<?php esc_html_e( 'Configured', 'wcpe' ); ?>
							</span>
						<?php else : ?>
							<span class="wcpe-status wcpe-status--inactive">
								<?php esc_html_e( 'Not Configured', 'wcpe' ); ?>
							</span>
							<p class="description">
								<?php
								printf(
									/* translators: %s: settings page link */
									esc_html__( 'Add your Webhook Signing Secret in %s to verify incoming webhooks.', 'wcpe' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wcpe-settings&tab=stripe' ) ) . '">' . esc_html__( 'Settings', 'wcpe' ) . '</a>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<!-- Setup Instructions -->
		<div class="wcpe-webhook-section">
			<h2><?php esc_html_e( 'Setup Instructions', 'wcpe' ); ?></h2>
			<ol class="wcpe-setup-steps">
				<li>
					<?php
					printf(
						/* translators: %s: Stripe Dashboard link */
						esc_html__( 'Go to %s in your Stripe Dashboard', 'wcpe' ),
						'<a href="https://dashboard.stripe.com/webhooks" target="_blank">Developers → Webhooks</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Click "Add endpoint"', 'wcpe' ); ?></li>
				<li><?php esc_html_e( 'Paste the Webhook URL shown above', 'wcpe' ); ?></li>
				<li>
					<?php esc_html_e( 'Select the following events to listen for:', 'wcpe' ); ?>
					<ul class="wcpe-event-list">
						<li><code>transfer.created</code></li>
						<li><code>transfer.updated</code></li>
						<li><code>transfer.failed</code></li>
						<li><code>account.updated</code></li>
						<li><code>account.application.deauthorized</code></li>
					</ul>
				</li>
				<li><?php esc_html_e( 'Click "Add endpoint" to save', 'wcpe' ); ?></li>
				<li><?php esc_html_e( 'Copy the "Signing secret" and add it to Settings → Stripe → Webhook Secret', 'wcpe' ); ?></li>
			</ol>
		</div>

		<!-- Recent Webhook Events -->
		<div class="wcpe-webhook-section">
			<h2><?php esc_html_e( 'Recent Webhook Events', 'wcpe' ); ?></h2>

			<?php if ( empty( $recent_webhooks ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No webhook events received yet. Events will appear here once Stripe sends webhooks to your endpoint.', 'wcpe' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Event', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wcpe' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_webhooks as $event ) : ?>
							<?php
							$context    = json_decode( $event->context, true );
							$event_type = $context['event_type'] ?? 'unknown';
							$event_id   = $context['event_id'] ?? '';
							?>
							<tr>
								<td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $event->created_at ) ) ); ?></td>
								<td><code><?php echo esc_html( $event_type ); ?></code></td>
								<td>
									<?php if ( 'info' === $event->level ) : ?>
										<span class="wcpe-status wcpe-status--active"><?php esc_html_e( 'Success', 'wcpe' ); ?></span>
									<?php elseif ( 'error' === $event->level ) : ?>
										<span class="wcpe-status wcpe-status--failed"><?php esc_html_e( 'Failed', 'wcpe' ); ?></span>
									<?php else : ?>
										<span class="wcpe-status wcpe-status--pending"><?php esc_html_e( 'Warning', 'wcpe' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<small><?php echo esc_html( $event_id ?: $event->message ); ?></small>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('.wcpe-copy-btn').on('click', function(e) {
				e.preventDefault();
				var target = $(this).data('target');
				var text = $('#' + target).text();
				
				if (navigator.clipboard) {
					navigator.clipboard.writeText(text).then(function() {
						alert('<?php echo esc_js( __( 'Copied to clipboard!', 'wcpe' ) ); ?>');
					});
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Render outgoing webhooks list.
	 *
	 * @return void
	 */
	private function render_outgoing_list() {
		$webhooks = $this->dispatcher->get_all_webhooks();
		?>
		<div class="wcpe-webhook-section">
			<h2><?php esc_html_e( 'Outgoing Webhooks', 'wcpe' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure webhooks to notify external systems when events occur (e.g., commission created, payout completed).', 'wcpe' ); ?>
			</p>

			<?php if ( empty( $webhooks ) ) : ?>
				<p><?php esc_html_e( 'No outgoing webhooks configured yet.', 'wcpe' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&action=new' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Add Your First Webhook', 'wcpe' ); ?>
				</a>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'URL', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Events', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wcpe' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wcpe' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $webhooks as $webhook ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $webhook['name'] ); ?></strong></td>
								<td><code><?php echo esc_html( $webhook['url'] ); ?></code></td>
								<td>
									<?php
									if ( empty( $webhook['events'] ) ) {
										esc_html_e( 'All events', 'wcpe' );
									} else {
										echo esc_html( count( $webhook['events'] ) . ' ' . _n( 'event', 'events', count( $webhook['events'] ), 'wcpe' ) );
									}
									?>
								</td>
								<td>
									<span class="wcpe-status wcpe-status--<?php echo 'active' === $webhook['status'] ? 'active' : 'inactive'; ?>">
										<?php echo 'active' === $webhook['status'] ? esc_html__( 'Active', 'wcpe' ) : esc_html__( 'Inactive', 'wcpe' ); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&action=edit&webhook_id=' . $webhook['id'] ) ); ?>">
										<?php esc_html_e( 'Edit', 'wcpe' ); ?>
									</a>
									|
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing&action=delete&webhook_id=' . $webhook['id'] ), 'wcpe_delete_webhook' ) ); ?>"
									   class="wcpe-action--delete"
									   onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this webhook?', 'wcpe' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'wcpe' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Available Events Reference -->
		<div class="wcpe-webhook-section">
			<h2><?php esc_html_e( 'Available Events', 'wcpe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Your outgoing webhooks can subscribe to these events:', 'wcpe' ); ?></p>
			<ul class="wcpe-event-list">
				<?php foreach ( WebhookDispatcher::get_available_events() as $event => $label ) : ?>
					<li><code><?php echo esc_html( $event ); ?></code> - <?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render outgoing webhook form.
	 *
	 * @return void
	 */
	private function render_outgoing_form() {
		$webhook_id = isset( $_GET['webhook_id'] ) ? absint( $_GET['webhook_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$webhook    = $webhook_id > 0 ? $this->dispatcher->get_webhook( $webhook_id ) : null;

		$name   = $webhook['name'] ?? '';
		$url    = $webhook['url'] ?? '';
		$secret = $webhook['secret'] ?? '';
		$status = $webhook['status'] ?? 'active';
		$events = $webhook['events'] ?? array();
		?>
		<div class="wcpe-webhook-section">
			<h2><?php echo $webhook_id > 0 ? esc_html__( 'Edit Webhook', 'wcpe' ) : esc_html__( 'Add Webhook', 'wcpe' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'wcpe_save_webhook', 'wcpe_webhook_nonce' ); ?>
				<input type="hidden" name="webhook_id" value="<?php echo esc_attr( $webhook_id ); ?>">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="webhook_name"><?php esc_html_e( 'Name', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="text" id="webhook_name" name="webhook_name"
								   value="<?php echo esc_attr( $name ); ?>" class="regular-text" required>
							<p class="description"><?php esc_html_e( 'A friendly name to identify this webhook.', 'wcpe' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="webhook_url"><?php esc_html_e( 'Payload URL', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="url" id="webhook_url" name="webhook_url"
								   value="<?php echo esc_attr( $url ); ?>" class="large-text" required
								   placeholder="https://example.com/webhooks/wcpe">
							<p class="description"><?php esc_html_e( 'The URL where webhook payloads will be sent (POST requests).', 'wcpe' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="webhook_secret"><?php esc_html_e( 'Secret', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="text" id="webhook_secret" name="webhook_secret"
								   value="<?php echo esc_attr( $secret ); ?>" class="regular-text"
								   placeholder="<?php esc_attr_e( 'Optional', 'wcpe' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Used to sign payloads. The signature will be included in the X-WCPE-Signature header.', 'wcpe' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wcpe' ); ?></th>
						<td>
							<select name="webhook_status" id="webhook_status">
								<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'wcpe' ); ?></option>
								<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'wcpe' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Events', 'wcpe' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Select which events should trigger this webhook. Leave all unchecked to receive all events.', 'wcpe' ); ?></p>
							<div class="wcpe-events-checkboxes">
								<?php foreach ( WebhookDispatcher::get_available_events() as $event => $label ) : ?>
									<label>
										<input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr( $event ); ?>"
											<?php checked( in_array( $event, $events, true ) ); ?>>
										<?php echo esc_html( $label ); ?>
										<code><?php echo esc_html( $event ); ?></code>
									</label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="wcpe_save_webhook" class="button button-primary">
						<?php esc_html_e( 'Save Webhook', 'wcpe' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-webhooks&tab=outgoing' ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'wcpe' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Get recent webhook events from logs.
	 *
	 * @return array
	 */
	private function get_recent_webhook_events() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wcpe_logs';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} 
				WHERE event LIKE %s 
				ORDER BY created_at DESC 
				LIMIT 10",
				'%webhook%'
			)
		);
	}
}
