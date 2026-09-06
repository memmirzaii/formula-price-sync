<?php
/**
 * Circuit breaker implementation for Formula Price Sync.
 *
 * Monitors rate changes and prevents anomalous updates from propagating.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSyncAPI;

use FormulaPriceSyncCoreLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit Breaker state machine.
 */
class Circuit_Breaker {

	/**
	 * Maximum allowed deviation percentage (25%).
	 */
	const MAX_DEVIATION_PERCENT = 25;

	/**
	 * Transient key for storing warnings.
	 */
	const WARNING_TRANSIENT = 'fps_circuit_breaker_warning';

	/**
	 * Previous accepted rates cache.
	 *
	 * @var array|null
	 */
	private $previous_rates;

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
		$this->logger = Logger::get_instance();
		$this->previous_rates = null;
	}

	/**
	 * Check new rates against circuit breaker rules.
	 *
	 * @param array $new_rates New rates from providers.
	 * @return array Result with accepted rates or error.
	 */
	public function check( array $new_rates ): array {
		if ( empty( $new_rates ) ) {
			return array(
				'accepted' => false,
				'rates'    => array(),
				'message'  => esc_html__( 'No rates provided.', 'formula-price-sync' ),
			);
		}

		// First run: accept all rates.
		if ( null === $this->previous_rates ) {
			$this->previous_rates = $new_rates;
			$this->accept( $new_rates );

			return array(
				'accepted' => true,
				'rates'    => $new_rates,
				'message'  => esc_html__( 'Initial rates accepted.', 'formula-price-sync' ),
			);
		}

		// Calculate deviation for each currency.
		$deviations = $this->calculate_deviations( $new_rates );

		// Check for any anomalous deviation.
		$anomalous = false;
		$rejected_keys = array();

		foreach ( $deviations as $currency => $deviation ) {
			if ( abs( $deviation ) > self::MAX_DEVIATION_PERCENT ) {
				$anomalous = true;
				$rejected_keys[] = $currency;
			}
		}

		if ( $anomalous ) {
			$message = sprintf(
				/* translators: %s: list of currencies */
				__( 'Circuit Breaker activated! Anomalous rate change detected (>25%%): %s. Update rejected to protect store pricing.', 'formula-price-sync' ),
				implode( ', ', $rejected_keys )
			);

			$this->log_error( $message );
			$this->trigger_event( $message, $this->previous_rates, 'anomalous_rate_change' );

			return array(
				'accepted' => false,
				'rates'    => $this->previous_rates, // Keep previous safe rates.
				'message'  => $message,
			);
		}

		// All rates within safe range - accept.
		$this->accept( $new_rates );

		return array(
			'accepted' => true,
			'rates'    => $new_rates,
			'message'  => esc_html__( 'Rates accepted - within safe deviation threshold.', 'formula-price-sync' ),
		);
	}

	/**
	 * Calculate percentage deviations from previous rates.
	 *
	 * @param array $new_rates New rates.
	 * @return array Deviations per currency.
	 */
	private function calculate_deviations( array $new_rates ): array {
		$deviations = array();

		foreach ( $new_rates as $currency => $new_rate ) {
			if ( isset( $this->previous_rates[ $currency ] ) && 0 !== (float) $this->previous_rates[ $currency ] ) {
				$previous = (float) $this->previous_rates[ $currency ];
				$current  = (float) $new_rate;
				$deviation = ( ( $current - $previous ) / $previous ) * 100;
				$deviations[ $currency ] = $deviation;
			}
		}

		return $deviations;
	}

	/**
	 * Accept new rates and update previous cache.
	 *
	 * @param array $rates Accepted rates.
	 */
	private function accept( array $rates ): void {
		$this->previous_rates = $rates;
		$this->clear_warning();
		$this->log_info( 'Circuit Breaker: Rates accepted.' );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Error message.
	 */
	private function log_error( string $message ): void {
		$this->logger->error( $message, array( 'source' => 'circuit_breaker' ) );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Info message.
	 */
	private function log_info( string $message ): void {
		$this->logger->info( $message, array( 'source' => 'circuit_breaker' ) );
	}

	/**
	 * Clear the warning transient.
	 */
	private function clear_warning(): void {
		delete_transient( self::WARNING_TRANSIENT );
	}

	/**
	 * Trigger circuit breaker event with standardized contract.
	 *
	 * @param string $message Event message.
	 * @param array  $rates   Rates data.
	 * @param string $reason  Reason code.
	 */
	private function trigger_event( string $message, array $rates, string $reason = 'unknown' ): void {
		// Standardized event contract
		$event_data = array(
			'message'   => $message,
			'provider'  => '', // Can be populated if provider-specific
			'reason'    => $reason,
			'rates'     => $rates,
			'timestamp' => current_time( 'mysql' ),
		);

		// Trigger the event
		do_action( 'fps_circuit_breaker_triggered', $event_data );

		// Also trigger admin warning for backward compatibility
		$this->trigger_admin_warning( $message, $rates );
	}

	/**
	 * Trigger admin warning (backward compatibility).
	 *
	 * @param string $message Warning message.
	 * @param array  $previous Previous rates.
	 */
	private function trigger_admin_warning( string $message, array $previous ): void {
		set_transient(
			self::WARNING_TRANSIENT,
			array(
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			),
			HOUR_IN_SECONDS * 6
		);

		// Trigger with standardized data for backward compatibility
		$event_data = array(
			'message'   => $message,
			'provider'  => '',
			'reason'    => 'anomalous_rate_change',
			'rates'     => $previous,
			'timestamp' => current_time( 'mysql' ),
		);
		do_action( 'fps_circuit_breaker_triggered', $event_data );
	}

	/**
	 * Display admin notice if a circuit breaker warning exists.
	 * Should be hooked to admin_notices.
	 *
	 * @return void
	 */
	public static function maybe_show_admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$warning = get_transient( self::WARNING_TRANSIENT );

		if ( false !== $warning && isset( $warning['message'] ) ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Circuit Breaker Warning', 'formula-price-sync' ); ?>:</strong>
					<?php echo esc_html( $warning['message'] ); ?>
				</p>
				<p>
					<small><?php echo esc_html( $warning['time'] ?? '' ); ?></small>
				</p>
			</div>
			<?php
			// Clear the transient after displaying
			delete_transient( self::WARNING_TRANSIENT );
		}
	}

	/**
	 * Reset circuit breaker state (for testing).
	 */
	public function reset(): void {
		$this->previous_rates = null;
		$this->clear_warning();
	}

}