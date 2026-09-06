<?php
/**
 * License guard with built-in trial mode for local testing.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zhaket_Guard
 *
 * Soft license gate + 30-day trial for marketplace testing.
 * Known trial key: FPS-TRIAL-2026-TEST
 */
class Zhaket_Guard {

	const LICENSE_OPTION     = 'fps_license_key';
	const STATUS_OPTION      = 'fps_license_status';
	const TRIAL_UNTIL_OPTION = 'fps_trial_until';
	const VALIDATION_TRANSIENT = 'fps_license_validation';

	/** Official trial key for WP admin testing. */
	const TRIAL_KEY = 'FPS-TRIAL-2026-TEST';

	/** Trial length in days. */
	const TRIAL_DAYS = 30;

	/**
	 * Whether license or active trial is valid.
	 *
	 * @return bool
	 */
	public static function is_valid(): bool {
		if ( defined( 'FPS_DEV_MODE' ) && FPS_DEV_MODE ) {
			return true;
		}

		$status = get_option( self::STATUS_OPTION, 'invalid' );
		if ( 'valid' === $status ) {
			// If trial, ensure not expired.
			if ( self::is_trial() ) {
				return ! self::is_trial_expired();
			}
			return true;
		}

		return false;
	}

	/**
	 * Whether current activation is trial.
	 *
	 * @return bool
	 */
	public static function is_trial(): bool {
		$key = (string) get_option( self::LICENSE_OPTION, '' );
		return ( self::TRIAL_KEY === $key ) || (bool) get_option( self::TRIAL_UNTIL_OPTION, 0 );
	}

	/**
	 * Trial end timestamp (unix) or 0.
	 *
	 * @return int
	 */
	public static function trial_until(): int {
		return (int) get_option( self::TRIAL_UNTIL_OPTION, 0 );
	}

	/**
	 * @return bool
	 */
	public static function is_trial_expired(): bool {
		$until = self::trial_until();
		if ( $until <= 0 ) {
			return false;
		}
		return time() > $until;
	}

	/**
	 * Days remaining on trial (0 if none).
	 *
	 * @return int
	 */
	public static function trial_days_left(): int {
		$until = self::trial_until();
		if ( $until <= 0 ) {
			return 0;
		}
		$left = (int) ceil( ( $until - time() ) / DAY_IN_SECONDS );
		return max( 0, $left );
	}

	/**
	 * Start or refresh trial (called on activation).
	 *
	 * @return void
	 */
	public static function start_trial(): void {
		$until = time() + ( self::TRIAL_DAYS * DAY_IN_SECONDS );
		update_option( self::LICENSE_OPTION, self::TRIAL_KEY, false );
		update_option( self::STATUS_OPTION, 'valid', false );
		update_option( self::TRIAL_UNTIL_OPTION, $until, false );
		set_transient(
			self::VALIDATION_TRANSIENT,
			array(
				'valid' => true,
				'time'  => time(),
				'trial' => true,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Get stored license key (masked).
	 *
	 * @param bool $masked Mask key.
	 * @return string
	 */
	public static function get_license_key( bool $masked = true ): string {
		$key = (string) get_option( self::LICENSE_OPTION, '' );
		if ( empty( $key ) ) {
			return '';
		}
		if ( ! $masked || self::TRIAL_KEY === $key ) {
			return $key;
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $key, -4 );
	}

	/**
	 * Activate license or trial key.
	 *
	 * @param string $license_key Key from form.
	 * @return array{success: bool, message: string}
	 */
	public static function activate( string $license_key ): array {
		$license_key = sanitize_text_field( trim( $license_key ) );

		if ( empty( $license_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'لطفاً کلید لایسنس را وارد کنید.', 'formula-price-sync' ),
			);
		}

		// Trial key – always accept for local testing.
		if ( strtoupper( $license_key ) === self::TRIAL_KEY || 'TRIAL' === strtoupper( $license_key ) ) {
			self::start_trial();
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: days */
					__( 'لایسنس آزمایشی فعال شد (%d روز).', 'formula-price-sync' ),
					self::TRIAL_DAYS
				),
			);
		}

		if ( strlen( $license_key ) < 10 ) {
			update_option( self::STATUS_OPTION, 'invalid', false );
			return array(
				'success' => false,
				'message' => __( 'کلید لایسنس نامعتبر است.', 'formula-price-sync' ),
			);
		}

		update_option( self::LICENSE_OPTION, $license_key, false );
		// Soft validation for non-trial keys (marketplace packaging without live API).
		$is_valid = true;
		/**
		 * Filter license validation result.
		 *
		 * @param bool   $is_valid    Result.
		 * @param string $license_key Key.
		 */
		$is_valid = (bool) apply_filters( 'fps_validate_license', $is_valid, $license_key );

		if ( $is_valid ) {
			update_option( self::STATUS_OPTION, 'valid', false );
			delete_option( self::TRIAL_UNTIL_OPTION );
			set_transient( self::VALIDATION_TRANSIENT, array( 'valid' => true, 'time' => time() ), DAY_IN_SECONDS );
			return array(
				'success' => true,
				'message' => __( 'لایسنس با موفقیت فعال شد.', 'formula-price-sync' ),
			);
		}

		update_option( self::STATUS_OPTION, 'invalid', false );
		return array(
			'success' => false,
			'message' => __( 'فعال‌سازی لایسنس ناموفق بود.', 'formula-price-sync' ),
		);
	}

	/**
	 * Deactivate license/trial.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		delete_option( self::LICENSE_OPTION );
		delete_option( self::TRIAL_UNTIL_OPTION );
		update_option( self::STATUS_OPTION, 'invalid', false );
		delete_transient( self::VALIDATION_TRANSIENT );
	}

	/**
	 * Admin notice when invalid.
	 *
	 * @return void
	 */
	public static function maybe_show_license_notice(): void {
		if ( self::is_valid() ) {
			// Optional trial reminder when few days left.
			if ( self::is_trial() && self::trial_days_left() <= 7 && self::trial_days_left() > 0 && current_user_can( 'manage_woocommerce' ) ) {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( $screen && false !== strpos( (string) $screen->id, 'formula-price-sync' ) ) {
					return;
				}
				echo '<div class="notice notice-info"><p>';
				echo esc_html(
					sprintf(
						/* translators: %d: days left */
						__( 'طلا ارز پرو: %d روز از دوره آزمایشی باقی مانده است.', 'formula-price-sync' ),
						self::trial_days_left()
					)
				);
				echo '</p></div>';
			}
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'formula-price-sync' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'طلا ارز پرو', 'formula-price-sync' ); ?>:</strong>
				<?php esc_html_e( 'لایسنس فعال نیست. برای تست از کلید FPS-TRIAL-2026-TEST استفاده کنید.', 'formula-price-sync' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=formula-price-sync' ) ); ?>">
					<?php esc_html_e( 'فعال‌سازی', 'formula-price-sync' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * @return bool True if features should be blocked.
	 */
	public static function should_block(): bool {
		return ! self::is_valid();
	}
}
