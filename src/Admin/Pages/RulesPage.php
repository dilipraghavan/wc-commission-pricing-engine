<?php
/**
 * Rules Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

use WCPE\Admin\Tables\RulesListTable;
use WCPE\Database\Repositories\RuleRepository;
use WCPE\Services\Logger;

/**
 * Handles the Rules admin page.
 *
 * @since 1.0.0
 */
class RulesPage {

	/**
	 * Rule repository instance.
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
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Handle actions.
		$this->handle_actions();

		switch ( $action ) {
			case 'new':
			case 'edit':
				$this->render_edit_form();
				break;

			default:
				$this->render_list();
				break;
		}
	}

	/**
	 * Handle page actions.
	 *
	 * @return void
	 */
	private function handle_actions() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Handle delete.
		if ( 'delete' === $action && isset( $_GET['rule_id'] ) ) {
			$rule_id = absint( $_GET['rule_id'] );
			
			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wcpe_delete_rule_' . $rule_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wcpe' ) );
			}

			if ( $this->repository->delete( $rule_id ) ) {
				$this->logger->info( 'rule_deleted', array( 'rule_id' => $rule_id ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&deleted=1' ) );
				exit;
			}
		}

		// Handle toggle status.
		if ( 'toggle' === $action && isset( $_GET['rule_id'] ) ) {
			$rule_id = absint( $_GET['rule_id'] );
			
			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wcpe_toggle_rule_' . $rule_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wcpe' ) );
			}

			if ( $this->repository->toggle_status( $rule_id ) ) {
				$this->logger->info( 'rule_toggled', array( 'rule_id' => $rule_id ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&toggled=1' ) );
				exit;
			}
		}

		// Handle bulk actions.
		if ( isset( $_POST['action'] ) && isset( $_POST['rule_ids'] ) ) {
			$this->handle_bulk_action();
		}

		// Handle form save.
		if ( isset( $_POST['wcpe_save_rule'] ) ) {
			$this->handle_save();
		}
	}

	/**
	 * Handle bulk actions.
	 *
	 * @return void
	 */
	private function handle_bulk_action() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bulk-rules' ) ) {
			return;
		}

		$action   = sanitize_key( $_POST['action'] );
		$rule_ids = array_map( 'absint', $_POST['rule_ids'] );

		if ( empty( $rule_ids ) ) {
			return;
		}

		$count = 0;

		foreach ( $rule_ids as $rule_id ) {
			switch ( $action ) {
				case 'activate':
					if ( $this->repository->update( $rule_id, array( 'status' => 'active' ) ) ) {
						++$count;
					}
					break;

				case 'deactivate':
					if ( $this->repository->update( $rule_id, array( 'status' => 'inactive' ) ) ) {
						++$count;
					}
					break;

				case 'delete':
					if ( $this->repository->delete( $rule_id ) ) {
						++$count;
					}
					break;
			}
		}

		$this->logger->info(
			'rules_bulk_action',
			array(
				'action' => $action,
				'count'  => $count,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&bulk=' . $action . '&count=' . $count ) );
		exit;
	}

	/**
	 * Handle form save.
	 *
	 * @return void
	 */
	private function handle_save() {
		if ( ! isset( $_POST['wcpe_rule_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcpe_rule_nonce'] ), 'wcpe_save_rule' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wcpe' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wcpe' ) );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;

		$data = array(
			'name'               => sanitize_text_field( $_POST['rule_name'] ?? '' ),
			'rule_type'          => sanitize_key( $_POST['rule_type'] ?? 'global' ),
			'calculation_method' => sanitize_key( $_POST['calculation_method'] ?? 'percentage' ),
			'value'              => floatval( $_POST['rule_value'] ?? 0 ),
			'target_id'          => ! empty( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : null,
			'priority'           => absint( $_POST['priority'] ?? 10 ),
			'status'             => sanitize_key( $_POST['status'] ?? 'active' ),
			'start_date'         => ! empty( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : null,
			'end_date'           => ! empty( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : null,
		);

		// Clear target_id for global rules.
		if ( 'global' === $data['rule_type'] ) {
			$data['target_id'] = null;
		}

		if ( $rule_id > 0 ) {
			// Update existing rule.
			if ( $this->repository->update( $rule_id, $data ) ) {
				$this->logger->info( 'rule_updated', array_merge( array( 'rule_id' => $rule_id ), $data ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&updated=1' ) );
				exit;
			}
		} else {
			// Create new rule.
			$new_id = $this->repository->create( $data );
			if ( $new_id ) {
				$this->logger->info( 'rule_created', array_merge( array( 'rule_id' => $new_id ), $data ) );
				wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&created=1' ) );
				exit;
			}
		}

		// If we get here, something went wrong.
		wp_safe_redirect( admin_url( 'admin.php?page=wcpe-dashboard&error=1' ) );
		exit;
	}

	/**
	 * Render the rules list.
	 *
	 * @return void
	 */
	private function render_list() {
		$list_table = new RulesListTable();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Commission Rules', 'wcpe' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-dashboard&action=new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New Rule', 'wcpe' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<form method="get">
				<input type="hidden" name="page" value="wcpe-dashboard">
				<?php
				$list_table->search_box( __( 'Search Rules', 'wcpe' ), 'rule' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit/new form.
	 *
	 * @return void
	 */
	private function render_edit_form() {
		$rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rule    = $rule_id > 0 ? $this->repository->get( $rule_id ) : null;

		$is_edit = ! empty( $rule );
		$title   = $is_edit ? __( 'Edit Rule', 'wcpe' ) : __( 'Add New Rule', 'wcpe' );

		// Default values.
		$defaults = array(
			'id'                 => 0,
			'name'               => '',
			'rule_type'          => 'global',
			'calculation_method' => 'percentage',
			'value'              => 10,
			'target_id'          => null,
			'priority'           => 10,
			'status'             => 'active',
			'start_date'         => '',
			'end_date'           => '',
		);

		$rule = $rule ? $rule : (object) $defaults;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<form method="post" action="" class="wcpe-rule-form">
				<?php wp_nonce_field( 'wcpe_save_rule', 'wcpe_rule_nonce' ); ?>
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule->id ); ?>">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="rule_name"><?php esc_html_e( 'Rule Name', 'wcpe' ); ?> <span class="required">*</span></label>
						</th>
						<td>
							<input type="text" id="rule_name" name="rule_name" 
								   value="<?php echo esc_attr( $rule->name ); ?>" 
								   class="regular-text" required>
							<p class="description"><?php esc_html_e( 'A descriptive name for this rule.', 'wcpe' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="rule_type"><?php esc_html_e( 'Rule Type', 'wcpe' ); ?></label>
						</th>
						<td>
							<select id="rule_type" name="rule_type" class="wcpe-rule-type-select">
								<option value="global" <?php selected( $rule->rule_type, 'global' ); ?>>
									<?php esc_html_e( 'Global (All Products)', 'wcpe' ); ?>
								</option>
								<option value="category" <?php selected( $rule->rule_type, 'category' ); ?>>
									<?php esc_html_e( 'Category', 'wcpe' ); ?>
								</option>
								<option value="vendor" <?php selected( $rule->rule_type, 'vendor' ); ?>>
									<?php esc_html_e( 'Vendor', 'wcpe' ); ?>
								</option>
								<option value="product" <?php selected( $rule->rule_type, 'product' ); ?>>
									<?php esc_html_e( 'Product', 'wcpe' ); ?>
								</option>
							</select>
							<p class="description"><?php esc_html_e( 'Product rules have highest priority, then Vendor, Category, and Global.', 'wcpe' ); ?></p>
						</td>
					</tr>

					<tr id="target_row" style="<?php echo 'global' === $rule->rule_type ? 'display:none;' : ''; ?>">
						<th scope="row">
							<label for="target_id"><?php esc_html_e( 'Applies To', 'wcpe' ); ?></label>
						</th>
						<td>
							<?php $this->render_target_selector( $rule ); ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="calculation_method"><?php esc_html_e( 'Commission Type', 'wcpe' ); ?></label>
						</th>
						<td>
							<select id="calculation_method" name="calculation_method">
								<option value="percentage" <?php selected( $rule->calculation_method, 'percentage' ); ?>>
									<?php esc_html_e( 'Percentage', 'wcpe' ); ?>
								</option>
								<option value="fixed" <?php selected( $rule->calculation_method, 'fixed' ); ?>>
									<?php esc_html_e( 'Fixed Amount', 'wcpe' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="rule_value"><?php esc_html_e( 'Commission Value', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="number" id="rule_value" name="rule_value" 
								   value="<?php echo esc_attr( $rule->value ); ?>" 
								   min="0" step="0.01" class="small-text">
							<span id="value_suffix">%</span>
							<p class="description"><?php esc_html_e( 'For percentage: enter 10 for 10%. For fixed: enter the amount.', 'wcpe' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="priority"><?php esc_html_e( 'Priority', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="number" id="priority" name="priority" 
								   value="<?php echo esc_attr( $rule->priority ); ?>" 
								   min="1" max="100" class="small-text">
							<p class="description"><?php esc_html_e( 'Higher numbers = higher priority (1-100). Used when multiple rules of same type match.', 'wcpe' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wcpe' ); ?></th>
						<td>
							<label>
								<input type="radio" name="status" value="active" <?php checked( $rule->status, 'active' ); ?>>
								<?php esc_html_e( 'Active', 'wcpe' ); ?>
							</label>
							<label style="margin-left: 20px;">
								<input type="radio" name="status" value="inactive" <?php checked( $rule->status, 'inactive' ); ?>>
								<?php esc_html_e( 'Inactive', 'wcpe' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="start_date"><?php esc_html_e( 'Date Range (Optional)', 'wcpe' ); ?></label>
						</th>
						<td>
							<input type="date" id="start_date" name="start_date" 
								   value="<?php echo esc_attr( $rule->start_date ? substr( $rule->start_date, 0, 10 ) : '' ); ?>">
							<span><?php esc_html_e( 'to', 'wcpe' ); ?></span>
							<input type="date" id="end_date" name="end_date" 
								   value="<?php echo esc_attr( $rule->end_date ? substr( $rule->end_date, 0, 10 ) : '' ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty for no date restriction.', 'wcpe' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="wcpe_save_rule" class="button button-primary" 
						   value="<?php echo $is_edit ? esc_attr__( 'Update Rule', 'wcpe' ) : esc_attr__( 'Create Rule', 'wcpe' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcpe-dashboard' ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'wcpe' ); ?>
					</a>
				</p>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Toggle target selector based on rule type.
			$('#rule_type').on('change', function() {
				var type = $(this).val();
				if (type === 'global') {
					$('#target_row').hide();
					$('.wcpe-target-select').hide().prop('disabled', true);
				} else {
					$('#target_row').show();
					$('.wcpe-target-select').hide().prop('disabled', true);
					$('.wcpe-target-select[data-type="' + type + '"]').show().prop('disabled', false);
				}
			});

			// Toggle value suffix based on calculation method.
			$('#calculation_method').on('change', function() {
				var method = $(this).val();
				$('#value_suffix').text(method === 'percentage' ? '%' : '<?php echo esc_js( get_woocommerce_currency_symbol() ); ?>');
			});

			// Initialize: disable hidden selects so they don't submit.
			$('.wcpe-target-select:hidden').prop('disabled', true);
		});
		</script>
		<?php
	}

	/**
	 * Render target selector based on rule type.
	 *
	 * @param object $rule Rule object.
	 * @return void
	 */
	private function render_target_selector( $rule ) {
		// Get all data upfront.
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'shop_manager', 'author', 'editor' ),
				'orderby'  => 'display_name',
			)
		);

		$products = wc_get_products(
			array(
				'limit'   => 100,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		?>
		<!-- Category Selector -->
		<select id="target_id_category" name="target_id" class="regular-text wcpe-target-select" 
				data-type="category" style="<?php echo 'category' !== $rule->rule_type ? 'display:none;' : ''; ?>">
			<option value=""><?php esc_html_e( '— Select Category —', 'wcpe' ); ?></option>
			<?php foreach ( $categories as $category ) : ?>
				<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $rule->target_id, $category->term_id ); ?>>
					<?php echo esc_html( $category->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Vendor Selector -->
		<select id="target_id_vendor" name="target_id" class="regular-text wcpe-target-select" 
				data-type="vendor" style="<?php echo 'vendor' !== $rule->rule_type ? 'display:none;' : ''; ?>">
			<option value=""><?php esc_html_e( '— Select Vendor —', 'wcpe' ); ?></option>
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $rule->target_id, $user->ID ); ?>>
					<?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_email ); ?>)
				</option>
			<?php endforeach; ?>
		</select>

		<!-- Product Selector -->
		<select id="target_id_product" name="target_id" class="regular-text wcpe-target-select" 
				data-type="product" style="<?php echo 'product' !== $rule->rule_type ? 'display:none;' : ''; ?>">
			<option value=""><?php esc_html_e( '— Select Product —', 'wcpe' ); ?></option>
			<?php foreach ( $products as $product ) : ?>
				<option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( $rule->target_id, $product->get_id() ); ?>>
					<?php echo esc_html( $product->get_name() ); ?> (#<?php echo esc_html( $product->get_id() ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule created successfully.', 'wcpe' ) . '</p></div>';
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule updated successfully.', 'wcpe' ) . '</p></div>';
		}

		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule deleted successfully.', 'wcpe' ) . '</p></div>';
		}

		if ( isset( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule status updated.', 'wcpe' ) . '</p></div>';
		}

		if ( isset( $_GET['bulk'] ) && isset( $_GET['count'] ) ) {
			$action = sanitize_key( $_GET['bulk'] );
			$count  = absint( $_GET['count'] );
			/* translators: %d: number of rules */
			$message = sprintf( _n( '%d rule updated.', '%d rules updated.', $count, 'wcpe' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'An error occurred. Please try again.', 'wcpe' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
