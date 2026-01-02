<?php
/**
 * Commission Calculator Service.
 *
 * @package WCPE\Services
 */

namespace WCPE\Services;

use WCPE\Database\Repositories\CommissionRepository;

/**
 * Handles commission calculation and creation.
 *
 * @since 1.0.0
 */
class CommissionCalculator {

	/**
	 * Commission repository instance.
	 *
	 * @var CommissionRepository
	 */
	private $repository;

	/**
	 * Rule engine instance.
	 *
	 * @var RuleEngine
	 */
	private $rule_engine;

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
		$this->repository  = new CommissionRepository();
		$this->rule_engine = new RuleEngine();
		$this->logger      = new Logger();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Get trigger status from settings.
		$trigger_status = get_option( 'wcpe_commission_trigger_status', 'completed' );

		// Hook into order status change.
		add_action( 'woocommerce_order_status_' . $trigger_status, array( $this, 'calculate_order_commissions' ), 10, 2 );

		// Handle refunds.
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_refund' ), 10, 2 );

		// Handle order status change to cancelled.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_cancellation' ), 10, 2 );
	}

	/**
	 * Calculate commissions for an order.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object (optional).
	 * @return array Array of created commission IDs.
	 */
	public function calculate_order_commissions( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			$this->logger->error(
				'commission_calculation_failed',
				array( 'order_id' => $order_id ),
				'Order not found'
			);
			return array();
		}

		// Check if commissions already calculated for this order.
		$existing = $this->repository->get_by_order( $order_id );
		if ( ! empty( $existing ) ) {
			$this->logger->info(
				'commission_already_calculated',
				array( 'order_id' => $order_id ),
				'Commissions already exist for this order'
			);
			return array();
		}

		$commission_ids = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$commission_id = $this->calculate_item_commission( $order, $item_id, $item );

			if ( $commission_id ) {
				$commission_ids[] = $commission_id;
			}
		}

		if ( ! empty( $commission_ids ) ) {
			$this->logger->info(
				'commissions_calculated',
				array(
					'order_id'       => $order_id,
					'commission_ids' => $commission_ids,
					'count'          => count( $commission_ids ),
				),
				sprintf( 'Created %d commission(s) for order #%d', count( $commission_ids ), $order_id )
			);

			/**
			 * Fires after commissions are calculated for an order.
			 *
			 * @param int   $order_id       Order ID.
			 * @param array $commission_ids Array of created commission IDs.
			 */
			do_action( 'wcpe_commissions_calculated', $order_id, $commission_ids );
		}

		return $commission_ids;
	}

	/**
	 * Calculate commission for a single order item.
	 *
	 * @param \WC_Order      $order   Order object.
	 * @param int            $item_id Order item ID.
	 * @param \WC_Order_Item $item    Order item object.
	 * @return int|false Commission ID or false on failure.
	 */
	private function calculate_item_commission( $order, $item_id, $item ) {
		// Only process product items.
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return false;
		}

		$product_id = $item->get_product_id();
		$product    = $item->get_product();

		if ( ! $product ) {
			return false;
		}

		// Get vendor (product author).
		$vendor_id = get_post_field( 'post_author', $product_id );

		if ( ! $vendor_id ) {
			$this->logger->warning(
				'vendor_not_found',
				array(
					'order_id'   => $order->get_id(),
					'product_id' => $product_id,
				),
				'Could not determine vendor for product'
			);
			return false;
		}

		// Skip if vendor is the store owner (optional - can be filtered).
		$skip_admin = apply_filters( 'wcpe_skip_admin_commissions', true );
		if ( $skip_admin && user_can( $vendor_id, 'manage_woocommerce' ) ) {
			// Check if this is the only admin or if there are other vendors.
			// For demo purposes, we'll calculate commissions for all users.
			// In production, you might want to skip store owner commissions.
		}

		// Check if commission already exists for this item.
		if ( $this->repository->exists_for_order_item( $order->get_id(), $item_id ) ) {
			return false;
		}

		// Get applicable rule.
		$rule = $this->rule_engine->resolve( $product_id, $vendor_id );

		// Calculate commission amount.
		$line_total        = floatval( $item->get_total() );
		$commission_amount = $this->rule_engine->calculate_commission( $line_total, $rule );

		// Allow filtering of commission amount.
		$commission_amount = apply_filters(
			'wcpe_commission_amount',
			$commission_amount,
			$item,
			$rule,
			$order
		);

		// Skip if commission is zero or negative.
		if ( $commission_amount <= 0 ) {
			return false;
		}

		// Create commission record.
		$commission_data = array(
			'order_id'          => $order->get_id(),
			'order_item_id'     => $item_id,
			'product_id'        => $product_id,
			'vendor_id'         => $vendor_id,
			'rule_id'           => $rule->id ?? null,
			'order_total'       => $line_total,
			'commission_amount' => round( $commission_amount, 2 ),
			'commission_rate'   => $rule->value ?? null,
			'status'            => 'pending',
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		$commission_id = $this->repository->create( $commission_data );

		if ( $commission_id ) {
			$this->logger->info(
				'commission_created',
				array(
					'commission_id' => $commission_id,
					'order_id'      => $order->get_id(),
					'product_id'    => $product_id,
					'vendor_id'     => $vendor_id,
					'amount'        => $commission_amount,
					'rule_id'       => $rule->id ?? 0,
					'rule_name'     => $rule->name ?? 'Default',
				),
				sprintf( 'Commission of %s created', wc_price( $commission_amount ) )
			);

			/**
			 * Fires after a commission is created.
			 *
			 * @param int    $commission_id Commission ID.
			 * @param int    $order_id      Order ID.
			 * @param float  $amount        Commission amount.
			 * @param object $rule          Applied rule.
			 */
			do_action( 'wcpe_commission_created', $commission_id, $order->get_id(), $commission_amount, $rule );
		}

		return $commission_id;
	}

	/**
	 * Handle order refund.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function handle_refund( $order_id, $refund_id ) {
		$commissions = $this->repository->get_by_order( $order_id );

		if ( empty( $commissions ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );

		if ( ! $refund ) {
			return;
		}

		// For full refunds, mark all commissions as refunded.
		$order        = wc_get_order( $order_id );
		$order_total  = floatval( $order->get_total() );
		$refund_total = abs( floatval( $refund->get_total() ) );

		// If refund is more than 90% of order total, consider it a full refund.
		if ( $refund_total >= ( $order_total * 0.9 ) ) {
			foreach ( $commissions as $commission ) {
				if ( 'paid' !== $commission->status ) {
					$this->repository->update_status( $commission->id, 'refunded' );

					$this->logger->info(
						'commission_refunded',
						array(
							'commission_id' => $commission->id,
							'order_id'      => $order_id,
							'refund_id'     => $refund_id,
						),
						'Commission marked as refunded'
					);

					do_action( 'wcpe_commission_refunded', $commission->id, $order_id );
				}
			}
		}
	}

	/**
	 * Handle order cancellation.
	 *
	 * @param int       $order_id Order ID.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	public function handle_cancellation( $order_id, $order = null ) {
		$commissions = $this->repository->get_by_order( $order_id );

		if ( empty( $commissions ) ) {
			return;
		}

		foreach ( $commissions as $commission ) {
			// Only cancel pending or approved commissions.
			if ( in_array( $commission->status, array( 'pending', 'approved' ), true ) ) {
				$this->repository->update_status( $commission->id, 'cancelled' );

				$this->logger->info(
					'commission_cancelled',
					array(
						'commission_id' => $commission->id,
						'order_id'      => $order_id,
					),
					'Commission cancelled due to order cancellation'
				);

				do_action( 'wcpe_commission_cancelled', $commission->id, $order_id );
			}
		}
	}

	/**
	 * Manually recalculate commissions for an order.
	 *
	 * Useful for admin actions or testing.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $force    Force recalculation even if commissions exist.
	 * @return array Array of commission IDs.
	 */
	public function recalculate( $order_id, $force = false ) {
		if ( $force ) {
			// Delete existing commissions.
			$existing = $this->repository->get_by_order( $order_id );
			foreach ( $existing as $commission ) {
				$this->repository->delete( $commission->id );
			}
		}

		return $this->calculate_order_commissions( $order_id );
	}

	/**
	 * Get commission preview for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array Preview data.
	 */
	public function preview_order_commissions( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'error' => 'Order not found' );
		}

		$preview = array(
			'order_id' => $order_id,
			'items'    => array(),
			'total'    => 0,
		);

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();
			$vendor_id  = get_post_field( 'post_author', $product_id );
			$line_total = floatval( $item->get_total() );

			$rule              = $this->rule_engine->resolve( $product_id, $vendor_id );
			$commission_amount = $this->rule_engine->calculate_commission( $line_total, $rule );

			$preview['items'][] = array(
				'item_id'    => $item_id,
				'product'    => $item->get_name(),
				'product_id' => $product_id,
				'vendor'     => get_the_author_meta( 'display_name', $vendor_id ),
				'vendor_id'  => $vendor_id,
				'line_total' => $line_total,
				'rule'       => $rule->name ?? 'Default',
				'rate'       => $rule->value . ( 'percentage' === ( $rule->calculation_method ?? 'percentage' ) ? '%' : '' ),
				'commission' => round( $commission_amount, 2 ),
			);

			$preview['total'] += $commission_amount;
		}

		$preview['total'] = round( $preview['total'], 2 );

		return $preview;
	}
}
