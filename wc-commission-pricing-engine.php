<?php
/**
 * Plugin Name: WooCommerce Commission & Pricing Engine
 * Plugin URI: https://developer.developer.developer/wc-commission-pricing-engine
 * Description: A commission management system with Stripe Connect integration for automated vendor payouts, featuring a flexible rule engine, REST API, and webhook notifications.
 * Version: 1.0.0
 * Author: Dilip
 * Author URI: https://developer.developer.developer
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wcpe
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package WCPE
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin version.
 */
define( 'WCPE_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 */
define( 'WCPE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'WCPE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'WCPE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader.
 */
if ( file_exists( WCPE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WCPE_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function wcpe_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Display admin notice if WooCommerce is not active.
 *
 * @return void
 */
function wcpe_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'WooCommerce Commission & Pricing Engine requires WooCommerce to be installed and active.',
				'wcpe'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Plugin activation hook.
 *
 * @return void
 */
function wcpe_activate() {
	if ( ! wcpe_is_woocommerce_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__(
				'WooCommerce Commission & Pricing Engine requires WooCommerce to be installed and active.',
				'wcpe'
			)
		);
	}

	require_once WCPE_PLUGIN_DIR . 'includes/class-activator.php';
	WCPE\Includes\Activator::activate();
}

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function wcpe_deactivate() {
	require_once WCPE_PLUGIN_DIR . 'includes/class-deactivator.php';
	WCPE\Includes\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'wcpe_activate' );
register_deactivation_hook( __FILE__, 'wcpe_deactivate' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function wcpe_init() {
	if ( ! wcpe_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'wcpe_woocommerce_missing_notice' );
		return;
	}

	// Declare HPOS compatibility.
	add_action(
		'before_woocommerce_init',
		function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					__FILE__,
					true
				);
			}
		}
	);

	// Load the plugin.
	require_once WCPE_PLUGIN_DIR . 'includes/class-plugin.php';
	$plugin = new WCPE\Includes\Plugin();
	$plugin->run();
}

add_action( 'plugins_loaded', 'wcpe_init' );
