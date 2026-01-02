<?php
/**
 * Admin Menu Registration.
 *
 * @package WCPE\Admin
 */

namespace WCPE\Admin;

use WCPE\Admin\Pages\RulesPage;
use WCPE\Admin\Pages\CommissionsPage;
use WCPE\Admin\Pages\PayoutsPage;
use WCPE\Admin\Pages\WebhooksPage;
use WCPE\Admin\Pages\LogsPage;
use WCPE\Admin\Pages\SettingsPage;

/**
 * Handles admin menu registration.
 *
 * @since 1.0.0
 */
class AdminMenu {

	/**
	 * Register admin menu items.
	 *
	 * @return void
	 */
	public function register_menu() {
		// Top-level menu for Commission Engine.
		add_menu_page(
			__( 'Commission Engine', 'wcpe' ),
			__( 'Commissions', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-dashboard',
			array( $this, 'render_rules_page' ),
			'dashicons-money-alt',
			56 // After WooCommerce.
		);

		// Commissions.
		add_submenu_page(
			'wcpe-dashboard',
			__( 'Commissions', 'wcpe' ),
			__( 'Commissions', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-commissions',
			array( new CommissionsPage(), 'render' )
		);

		// Payouts.
		add_submenu_page(
			'wcpe-dashboard',
			__( 'Payouts', 'wcpe' ),
			__( 'Payouts', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-payouts',
			array( new PayoutsPage(), 'render' )
		);

		// Webhooks.
		add_submenu_page(
			'wcpe-dashboard',
			__( 'Webhooks', 'wcpe' ),
			__( 'Webhooks', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-webhooks',
			array( new WebhooksPage(), 'render' )
		);

		// Logs.
		add_submenu_page(
			'wcpe-dashboard',
			__( 'Logs', 'wcpe' ),
			__( 'Logs', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-logs',
			array( new LogsPage(), 'render' )
		);

		// Settings.
		add_submenu_page(
			'wcpe-dashboard',
			__( 'Commission Settings', 'wcpe' ),
			__( 'Settings', 'wcpe' ),
			'manage_woocommerce',
			'wcpe-settings',
			array( new SettingsPage(), 'render' )
		);

		// Rename first submenu from "Commissions" to "Rules".
		global $submenu;
		if ( isset( $submenu['wcpe-dashboard'] ) ) {
			$submenu['wcpe-dashboard'][0][0] = __( 'Rules', 'wcpe' );
		}
	}

	/**
	 * Render the rules page.
	 *
	 * @return void
	 */
	public function render_rules_page() {
		$page = new RulesPage();
		$page->render();
	}
}
