<?php
/**
 * Rule Engine Service.
 *
 * @package WCPE\Services
 */

namespace WCPE\Services;

use WCPE\Database\Repositories\RuleRepository;

/**
 * Handles rule matching and resolution.
 *
 * @since 1.0.0
 */
class RuleEngine {

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
	 * Priority weights for rule types.
	 *
	 * Higher number = higher priority.
	 *
	 * @var array
	 */
	private $type_weights = array(
		'global'   => 1,
		'category' => 2,
		'vendor'   => 3,
		'product'  => 4,
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new RuleRepository();
		$this->logger     = new Logger();
	}

	/**
	 * Resolve the applicable rule for a product/vendor combination.
	 *
	 * @param int $product_id Product ID.
	 * @param int $vendor_id  Vendor (user) ID.
	 * @return object|null The resolved rule or null if none found.
	 */
	public function resolve( $product_id, $vendor_id ) {
		$candidates = $this->gather_candidates( $product_id, $vendor_id );

		if ( empty( $candidates ) ) {
			// Fall back to default commission rate.
			return $this->get_default_rule();
		}

		// Sort by effective priority (type weight + rule priority).
		usort( $candidates, array( $this, 'compare_rules' ) );

		// Return highest priority rule.
		$resolved = $candidates[0];

		$this->logger->info(
			'rule_resolved',
			array(
				'product_id' => $product_id,
				'vendor_id'  => $vendor_id,
				'rule_id'    => $resolved->id,
				'rule_name'  => $resolved->name,
				'rule_type'  => $resolved->rule_type,
			),
			sprintf( 'Resolved rule "%s" for product #%d', $resolved->name, $product_id )
		);

		return $resolved;
	}

	/**
	 * Gather all candidate rules for a product/vendor.
	 *
	 * @param int $product_id Product ID.
	 * @param int $vendor_id  Vendor ID.
	 * @return array Array of rule objects.
	 */
	private function gather_candidates( $product_id, $vendor_id ) {
		$candidates = array();

		// 1. Product-specific rules (highest priority).
		$product_rules = $this->repository->get_rules_for_product( $product_id );
		$candidates    = array_merge( $candidates, $product_rules );

		// 2. Vendor-specific rules.
		$vendor_rules = $this->repository->get_rules_for_vendor( $vendor_id );
		$candidates   = array_merge( $candidates, $vendor_rules );

		// 3. Category-specific rules.
		$category_ids  = $this->get_product_categories( $product_id );
		foreach ( $category_ids as $category_id ) {
			$category_rules = $this->repository->get_rules_for_category( $category_id );
			$candidates     = array_merge( $candidates, $category_rules );
		}

		// 4. Global rules (lowest priority).
		$global_rules = $this->repository->get_global_rules();
		$candidates   = array_merge( $candidates, $global_rules );

		// Filter by date validity.
		$candidates = array_filter( $candidates, array( $this, 'is_rule_valid' ) );

		/**
		 * Filter the candidate rules.
		 *
		 * @param array $candidates  Array of rule objects.
		 * @param int   $product_id  Product ID.
		 * @param int   $vendor_id   Vendor ID.
		 */
		return apply_filters( 'wcpe_rule_candidates', $candidates, $product_id, $vendor_id );
	}

	/**
	 * Compare two rules for sorting.
	 *
	 * Higher effective priority should come first.
	 *
	 * @param object $a First rule.
	 * @param object $b Second rule.
	 * @return int Comparison result.
	 */
	private function compare_rules( $a, $b ) {
		$priority_a = $this->get_effective_priority( $a );
		$priority_b = $this->get_effective_priority( $b );

		// Higher priority first.
		if ( $priority_a !== $priority_b ) {
			return $priority_b - $priority_a;
		}

		// If same priority, newer rule wins.
		return strtotime( $b->created_at ) - strtotime( $a->created_at );
	}

	/**
	 * Get effective priority for a rule.
	 *
	 * Combines type weight and rule priority.
	 *
	 * @param object $rule Rule object.
	 * @return int Effective priority.
	 */
	private function get_effective_priority( $rule ) {
		$type_weight = $this->type_weights[ $rule->rule_type ] ?? 0;

		// Type weight is multiplied by 1000 to ensure it takes precedence.
		// Then rule priority is added for fine-tuning within the same type.
		$effective = ( $type_weight * 1000 ) + $rule->priority;

		/**
		 * Filter the effective priority for a rule.
		 *
		 * @param int    $effective Effective priority.
		 * @param object $rule      Rule object.
		 */
		return apply_filters( 'wcpe_rule_effective_priority', $effective, $rule );
	}

	/**
	 * Check if a rule is currently valid (within date range).
	 *
	 * @param object $rule Rule object.
	 * @return bool
	 */
	private function is_rule_valid( $rule ) {
		$now = current_time( 'timestamp' );

		// Check start date.
		if ( ! empty( $rule->start_date ) ) {
			$start = strtotime( $rule->start_date );
			if ( $now < $start ) {
				return false;
			}
		}

		// Check end date.
		if ( ! empty( $rule->end_date ) ) {
			$end = strtotime( $rule->end_date );
			if ( $now > $end ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get product category IDs.
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of category IDs.
	 */
	private function get_product_categories( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'term_id' );
	}

	/**
	 * Get default rule based on settings.
	 *
	 * @return object Pseudo-rule object.
	 */
	private function get_default_rule() {
		$default_rate = get_option( 'wcpe_default_commission_rate', 10 );

		return (object) array(
			'id'                 => 0,
			'name'               => __( 'Default Commission', 'wcpe' ),
			'rule_type'          => 'global',
			'calculation_method' => 'percentage',
			'value'              => $default_rate,
			'target_id'          => null,
			'priority'           => 0,
			'status'             => 'active',
			'start_date'         => null,
			'end_date'           => null,
		);
	}

	/**
	 * Calculate commission amount based on rule.
	 *
	 * @param float  $amount Order line item amount.
	 * @param object $rule   Rule object.
	 * @return float Commission amount.
	 */
	public function calculate_commission( $amount, $rule ) {
		if ( 'percentage' === $rule->calculation_method ) {
			$commission = $amount * ( $rule->value / 100 );
		} else {
			// Fixed amount.
			$commission = $rule->value;
		}

		/**
		 * Filter the calculated commission amount.
		 *
		 * @param float  $commission Calculated commission.
		 * @param float  $amount     Original order amount.
		 * @param object $rule       Applied rule.
		 */
		return apply_filters( 'wcpe_calculated_commission', $commission, $amount, $rule );
	}

	/**
	 * Preview what rule would apply for a product.
	 *
	 * Useful for admin UI preview.
	 *
	 * @param int $product_id Product ID.
	 * @return array Preview data.
	 */
	public function preview( $product_id ) {
		$product   = wc_get_product( $product_id );
		$vendor_id = get_post_field( 'post_author', $product_id );

		if ( ! $product ) {
			return array(
				'error' => __( 'Product not found', 'wcpe' ),
			);
		}

		$rule = $this->resolve( $product_id, $vendor_id );

		$sample_price      = $product->get_price();
		$commission_amount = $this->calculate_commission( $sample_price, $rule );

		return array(
			'product'           => array(
				'id'    => $product_id,
				'name'  => $product->get_name(),
				'price' => $sample_price,
			),
			'vendor'            => array(
				'id'   => $vendor_id,
				'name' => get_the_author_meta( 'display_name', $vendor_id ),
			),
			'rule'              => array(
				'id'     => $rule->id,
				'name'   => $rule->name,
				'type'   => $rule->rule_type,
				'method' => $rule->calculation_method,
				'value'  => $rule->value,
			),
			'commission_amount' => $commission_amount,
			'commission_rate'   => 'percentage' === $rule->calculation_method ? $rule->value . '%' : wc_price( $rule->value ),
		);
	}

	/**
	 * Get all rules summary for reporting.
	 *
	 * @return array
	 */
	public function get_rules_summary() {
		return array(
			'total'           => $this->repository->count(),
			'active'          => $this->repository->count( array( 'status' => 'active' ) ),
			'inactive'        => $this->repository->count( array( 'status' => 'inactive' ) ),
			'by_type'         => array(
				'global'   => $this->repository->count( array( 'rule_type' => 'global' ) ),
				'category' => $this->repository->count( array( 'rule_type' => 'category' ) ),
				'vendor'   => $this->repository->count( array( 'rule_type' => 'vendor' ) ),
				'product'  => $this->repository->count( array( 'rule_type' => 'product' ) ),
			),
		);
	}
}
