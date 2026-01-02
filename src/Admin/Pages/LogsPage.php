<?php
/**
 * Logs Admin Page.
 *
 * @package WCPE\Admin\Pages
 */

namespace WCPE\Admin\Pages;

use WCPE\Services\Logger;

/**
 * Handles the Logs admin page.
 *
 * @since 1.0.0
 */
class LogsPage {

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
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		$logs = $this->logger->get_logs( 50 );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Event Logs', 'wcpe' ); ?></h1>
			<hr class="wp-header-end">

			<div class="wcpe-logs-page">
				<?php if ( empty( $logs ) ) : ?>
					<p><?php esc_html_e( 'No logs recorded yet.', 'wcpe' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" style="width: 150px;"><?php esc_html_e( 'Date', 'wcpe' ); ?></th>
								<th scope="col" style="width: 80px;"><?php esc_html_e( 'Level', 'wcpe' ); ?></th>
								<th scope="col" style="width: 150px;"><?php esc_html_e( 'Event', 'wcpe' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'wcpe' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : ?>
								<tr>
									<td><?php echo esc_html( $log->created_at ); ?></td>
									<td>
										<span class="wcpe-log-level wcpe-log-level--<?php echo esc_attr( $log->level ); ?>">
											<?php echo esc_html( ucfirst( $log->level ) ); ?>
										</span>
									</td>
									<td><code><?php echo esc_html( $log->event ); ?></code></td>
									<td><?php echo esc_html( $log->message ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
