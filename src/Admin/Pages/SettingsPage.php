<?php
/**
 * Settings Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

/**
 * Handles the Settings admin page.
 *
 * @since 1.0.0
 */
class SettingsPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		// General settings.
		register_setting( 'wcpe_general_settings', 'wcpe_default_commission_rate' );
		register_setting( 'wcpe_general_settings', 'wcpe_commission_trigger_status' );

		// Stripe settings.
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_mode' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_test_secret_key' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_test_publishable_key' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_live_secret_key' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_live_publishable_key' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_connect_client_id' );
		register_setting( 'wcpe_stripe_settings', 'wcpe_stripe_webhook_secret' );

		// Payout settings.
		register_setting( 'wcpe_payout_settings', 'wcpe_minimum_payout' );
		register_setting( 'wcpe_payout_settings', 'wcpe_payout_fee_handling' );
		register_setting( 'wcpe_payout_settings', 'wcpe_auto_payout_schedule' );

		// API settings.
		register_setting( 'wcpe_api_settings', 'wcpe_api_enabled' );
		register_setting( 'wcpe_api_settings', 'wcpe_api_key' );

		// Advanced settings.
		register_setting( 'wcpe_advanced_settings', 'wcpe_log_retention_days' );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		// Handle settings save.
		$this->handle_save();

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs        = $this->get_tabs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Commission Engine Settings', 'wcpe' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_id => $tab_name ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-settings&tab=' . $tab_id ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_name ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wcpe-settings-content">
				<?php
				switch ( $current_tab ) {
					case 'stripe':
						$this->render_stripe_tab();
						break;
					case 'payouts':
						$this->render_payouts_tab();
						break;
					case 'api':
						$this->render_api_tab();
						break;
					case 'advanced':
						$this->render_advanced_tab();
						break;
					default:
						$this->render_general_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get settings tabs.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'general'  => __( 'General', 'wcpe' ),
			'stripe'   => __( 'Stripe', 'wcpe' ),
			'payouts'  => __( 'Payouts', 'wcpe' ),
			'api'      => __( 'API', 'wcpe' ),
			'advanced' => __( 'Advanced', 'wcpe' ),
		);
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	private function handle_save() {
		if ( ! isset( $_POST['wcpe_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['wcpe_settings_nonce'] ), 'wcpe_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_POST['wcpe_settings_tab'] ) ? sanitize_key( $_POST['wcpe_settings_tab'] ) : 'general';

		switch ( $tab ) {
			case 'general':
				update_option( 'wcpe_default_commission_rate', floatval( $_POST['wcpe_default_commission_rate'] ?? 10 ) );
				update_option( 'wcpe_commission_trigger_status', sanitize_key( $_POST['wcpe_commission_trigger_status'] ?? 'completed' ) );
				break;

			case 'stripe':
				update_option( 'wcpe_stripe_mode', sanitize_key( $_POST['wcpe_stripe_mode'] ?? 'test' ) );
				update_option( 'wcpe_stripe_test_secret_key', sanitize_text_field( $_POST['wcpe_stripe_test_secret_key'] ?? '' ) );
				update_option( 'wcpe_stripe_test_publishable_key', sanitize_text_field( $_POST['wcpe_stripe_test_publishable_key'] ?? '' ) );
				update_option( 'wcpe_stripe_live_secret_key', sanitize_text_field( $_POST['wcpe_stripe_live_secret_key'] ?? '' ) );
				update_option( 'wcpe_stripe_live_publishable_key', sanitize_text_field( $_POST['wcpe_stripe_live_publishable_key'] ?? '' ) );
				update_option( 'wcpe_stripe_connect_client_id', sanitize_text_field( $_POST['wcpe_stripe_connect_client_id'] ?? '' ) );
				update_option( 'wcpe_stripe_webhook_secret', sanitize_text_field( $_POST['wcpe_stripe_webhook_secret'] ?? '' ) );
				break;

			case 'payouts':
				update_option( 'wcpe_minimum_payout', floatval( $_POST['wcpe_minimum_payout'] ?? 50 ) );
				update_option( 'wcpe_payout_fee_handling', sanitize_key( $_POST['wcpe_payout_fee_handling'] ?? 'platform' ) );
				update_option( 'wcpe_auto_payout_schedule', sanitize_key( $_POST['wcpe_auto_payout_schedule'] ?? 'disabled' ) );
				break;

			case 'api':
				update_option( 'wcpe_api_enabled', isset( $_POST['wcpe_api_enabled'] ) ? 'yes' : 'no' );
				break;

			case 'advanced':
				update_option( 'wcpe_log_retention_days', absint( $_POST['wcpe_log_retention_days'] ?? 30 ) );
				break;
		}

		add_settings_error( 'wcpe_settings', 'settings_saved', __( 'Settings saved.', 'wcpe' ), 'success' );
	}

	/**
	 * Render general settings tab.
	 *
	 * @return void
	 */
	private function render_general_tab() {
		$commission_rate  = get_option( 'wcpe_default_commission_rate', 10 );
		$trigger_status   = get_option( 'wcpe_commission_trigger_status', 'completed' );
		$order_statuses   = wc_get_order_statuses();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcpe_save_settings', 'wcpe_settings_nonce' ); ?>
			<input type="hidden" name="wcpe_settings_tab" value="general">

			<?php settings_errors( 'wcpe_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_default_commission_rate">
							<?php esc_html_e( 'Default Commission Rate (%)', 'wcpe' ); ?>
						</label>
					</th>
					<td>
						<input type="number" id="wcpe_default_commission_rate" name="wcpe_default_commission_rate"
							   value="<?php echo esc_attr( $commission_rate ); ?>"
							   min="0" max="100" step="0.01" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Default commission rate when no specific rule applies.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wcpe_commission_trigger_status">
							<?php esc_html_e( 'Calculate Commission On', 'wcpe' ); ?>
						</label>
					</th>
					<td>
						<select id="wcpe_commission_trigger_status" name="wcpe_commission_trigger_status">
							<?php foreach ( $order_statuses as $status => $label ) : ?>
								<option value="<?php echo esc_attr( str_replace( 'wc-', '', $status ) ); ?>"
									<?php selected( $trigger_status, str_replace( 'wc-', '', $status ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Commission will be calculated when order reaches this status.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render Stripe settings tab.
	 *
	 * @return void
	 */
	private function render_stripe_tab() {
		$mode           = get_option( 'wcpe_stripe_mode', 'test' );
		$test_secret    = get_option( 'wcpe_stripe_test_secret_key', '' );
		$test_pub       = get_option( 'wcpe_stripe_test_publishable_key', '' );
		$live_secret    = get_option( 'wcpe_stripe_live_secret_key', '' );
		$live_pub       = get_option( 'wcpe_stripe_live_publishable_key', '' );
		$client_id      = get_option( 'wcpe_stripe_connect_client_id', '' );
		$webhook_secret = get_option( 'wcpe_stripe_webhook_secret', '' );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcpe_save_settings', 'wcpe_settings_nonce' ); ?>
			<input type="hidden" name="wcpe_settings_tab" value="stripe">

			<?php settings_errors( 'wcpe_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Environment', 'wcpe' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="wcpe_stripe_mode" value="test"
									<?php checked( $mode, 'test' ); ?>>
								<?php esc_html_e( 'Test Mode', 'wcpe' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="wcpe_stripe_mode" value="live"
									<?php checked( $mode, 'live' ); ?>>
								<?php esc_html_e( 'Live Mode', 'wcpe' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Test Credentials', 'wcpe' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_test_secret_key"><?php esc_html_e( 'Secret Key', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="password" id="wcpe_stripe_test_secret_key" name="wcpe_stripe_test_secret_key"
							   value="<?php echo esc_attr( $test_secret ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_test_publishable_key"><?php esc_html_e( 'Publishable Key', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="text" id="wcpe_stripe_test_publishable_key" name="wcpe_stripe_test_publishable_key"
							   value="<?php echo esc_attr( $test_pub ); ?>" class="regular-text">
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Live Credentials', 'wcpe' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_live_secret_key"><?php esc_html_e( 'Secret Key', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="password" id="wcpe_stripe_live_secret_key" name="wcpe_stripe_live_secret_key"
							   value="<?php echo esc_attr( $live_secret ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_live_publishable_key"><?php esc_html_e( 'Publishable Key', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="text" id="wcpe_stripe_live_publishable_key" name="wcpe_stripe_live_publishable_key"
							   value="<?php echo esc_attr( $live_pub ); ?>" class="regular-text">
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Stripe Connect', 'wcpe' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_connect_client_id"><?php esc_html_e( 'Client ID', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="text" id="wcpe_stripe_connect_client_id" name="wcpe_stripe_connect_client_id"
							   value="<?php echo esc_attr( $client_id ); ?>" class="regular-text">
						<p class="description">
							<?php esc_html_e( 'Found in Stripe Dashboard → Connect → Settings', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wcpe_stripe_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="password" id="wcpe_stripe_webhook_secret" name="wcpe_stripe_webhook_secret"
							   value="<?php echo esc_attr( $webhook_secret ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'wcpe' ); ?></th>
					<td>
						<code><?php echo esc_url( rest_url( 'wcpe/v1/stripe/webhook' ) ); ?></code>
						<button type="button" class="button button-secondary wcpe-copy-webhook-url">
							<?php esc_html_e( 'Copy', 'wcpe' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Add this URL in your Stripe Dashboard webhook settings.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render payouts settings tab.
	 *
	 * @return void
	 */
	private function render_payouts_tab() {
		$minimum      = get_option( 'wcpe_minimum_payout', 50 );
		$fee_handling = get_option( 'wcpe_payout_fee_handling', 'platform' );
		$schedule     = get_option( 'wcpe_auto_payout_schedule', 'disabled' );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcpe_save_settings', 'wcpe_settings_nonce' ); ?>
			<input type="hidden" name="wcpe_settings_tab" value="payouts">

			<?php settings_errors( 'wcpe_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_minimum_payout"><?php esc_html_e( 'Minimum Payout Amount', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="number" id="wcpe_minimum_payout" name="wcpe_minimum_payout"
							   value="<?php echo esc_attr( $minimum ); ?>" min="0" step="0.01" class="small-text">
						<span><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Vendors must have at least this amount to receive a payout.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Transaction Fee Handling', 'wcpe' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="wcpe_payout_fee_handling" value="platform"
									<?php checked( $fee_handling, 'platform' ); ?>>
								<?php esc_html_e( 'Platform absorbs fees', 'wcpe' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="wcpe_payout_fee_handling" value="vendor"
									<?php checked( $fee_handling, 'vendor' ); ?>>
								<?php esc_html_e( 'Deduct from vendor payout', 'wcpe' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic Payouts', 'wcpe' ); ?></th>
					<td>
						<select name="wcpe_auto_payout_schedule">
							<option value="disabled" <?php selected( $schedule, 'disabled' ); ?>>
								<?php esc_html_e( 'Disabled (Manual only)', 'wcpe' ); ?>
							</option>
							<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>>
								<?php esc_html_e( 'Weekly', 'wcpe' ); ?>
							</option>
							<option value="monthly" <?php selected( $schedule, 'monthly' ); ?>>
								<?php esc_html_e( 'Monthly', 'wcpe' ); ?>
							</option>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render API settings tab.
	 *
	 * @return void
	 */
	private function render_api_tab() {
		$api_enabled = get_option( 'wcpe_api_enabled', 'yes' );
		$api_key     = get_option( 'wcpe_api_key', '' );

		// Generate API key if not exists.
		if ( empty( $api_key ) ) {
			$api_key = wp_generate_password( 32, false );
			update_option( 'wcpe_api_key', $api_key );
		}
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcpe_save_settings', 'wcpe_settings_nonce' ); ?>
			<input type="hidden" name="wcpe_settings_tab" value="api">

			<?php settings_errors( 'wcpe_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable REST API', 'wcpe' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wcpe_api_enabled" value="yes"
								<?php checked( $api_enabled, 'yes' ); ?>>
							<?php esc_html_e( 'Allow external applications to access commission data via API', 'wcpe' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'wcpe' ); ?></th>
					<td>
						<code><?php echo esc_html( $api_key ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Use this key in the X-WCPE-API-Key header for API requests.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Base URL', 'wcpe' ); ?></th>
					<td>
						<code><?php echo esc_url( rest_url( 'wcpe/v1/' ) ); ?></code>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render advanced settings tab.
	 *
	 * @return void
	 */
	private function render_advanced_tab() {
		$log_retention = get_option( 'wcpe_log_retention_days', 30 );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'wcpe_save_settings', 'wcpe_settings_nonce' ); ?>
			<input type="hidden" name="wcpe_settings_tab" value="advanced">

			<?php settings_errors( 'wcpe_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wcpe_log_retention_days"><?php esc_html_e( 'Log Retention', 'wcpe' ); ?></label>
					</th>
					<td>
						<input type="number" id="wcpe_log_retention_days" name="wcpe_log_retention_days"
							   value="<?php echo esc_attr( $log_retention ); ?>" min="1" max="365" class="small-text">
						<?php esc_html_e( 'days', 'wcpe' ); ?>
						<p class="description">
							<?php esc_html_e( 'Logs older than this will be automatically deleted.', 'wcpe' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
