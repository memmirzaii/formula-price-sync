<?php
/**
 * Server-side cron manager for Formula Price Sync.
 *
 * Provides a secure query-var endpoint that server-level crontabs
 * (curl / wget) can hit to trigger background price synchronization,
 * independent of WordPress WP-Cron and user traffic.
 *
 * Example crontab entry (run every hour):
 *   0 * * * * curl -s "https://yoursite.com/?fps_action=cron_sync&token=YOUR_SECRET_TOKEN" > /dev/null 2>&1
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Core\Cache_Purger;
use FormulaPriceSync\Core\Logger;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron_Manager {

	const OPTION_KEY       = 'fps_cron_token';
	const QUERY_VAR        = 'fps_action';
	const QUERY_VALUE      = 'cron_sync';
	const RATE_LIMIT_KEY   = 'fps_cron_rate_limit';
	const RATE_LIMIT_SECS  = 300;
	const LOCK_KEY         = 'fps_cron_lock';
	const LOCK_TIMEOUT     = 300;

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		add_action( 'parse_request', array( self::class, 'handle_request' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Register the custom query variable.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Handle incoming request when the query var is present.
	 *
	 * @param \WP $wp WordPress object.
	 * @return void
	 */
	public static function handle_request( \WP $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		if ( self::QUERY_VALUE !== $wp->query_vars[ self::QUERY_VAR ] ) {
			return;
		}

		self::handle_cron_request();
	}

	/**
	 * Process a server-cron sync request.
	 */
	public static function handle_cron_request() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $token ) ) {
			self::respond( 401, 'Unauthorized - token missing.' );
			return;
		}

		$stored_token = get_option( self::OPTION_KEY, '' );

		if ( empty( $stored_token ) ) {
			self::respond( 401, 'Unauthorized - cron token not configured. Set it in Settings -> Cron.' );
			return;
		}

		if ( ! hash_equals( $stored_token, $token ) ) {
			self::respond( 401, 'Unauthorized - invalid token.' );
			return;
		}

		// Rate limit: only allow one run per RATE_LIMIT_SECS seconds.
		if ( self::is_rate_limited() ) {
			self::respond( 429, 'Too Many Requests - sync already running. Wait ' . self::RATE_LIMIT_SECS . ' seconds.' );
			return;
		}

		self::set_rate_limit();

		// Acquire lock with ownership validation.
		if ( ! self::acquire_lock() ) {
			self::respond( 429, 'Sync already running by another process.' );
			return;
		}

		try {
			$result = self::run_sync();

			if ( is_wp_error( $result ) ) {
				self::respond( 500, 'Sync failed: ' . $result->get_error_message() );
				return;
			}

			$msg = sprintf(
				'OK - %d chunk(s) scheduled. Timestamp: %s',
				absint( $result['chunks'] ),
				current_time( 'mysql' )
			);
			self::respond( 200, $msg );
		} finally {
			// Always release lock, even on failure.
			self::release_lock();
		}
	}

	/**
	 * Acquire a lock with ownership.
	 *
	 * @return bool True if lock acquired, false if already locked.
	 */
	private static function acquire_lock(): bool {
		$lock_data = self::get_lock_data();
		
		// If lock exists and is still valid, reject.
		if ( false !== $lock_data && $lock_data['expires_at'] > time() ) {
			return false;
		}

		// Create new lock with ownership.
		$new_lock = array(
			'owner'      => self::get_owner_id(),
			'created_at' => time(),
			'expires_at' => time() + self::LOCK_TIMEOUT,
		);

		set_transient( self::LOCK_KEY, $new_lock, self::LOCK_TIMEOUT );
		
		return true;
	}

	/**
	 * Release the current lock.
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Get current lock data.
	 *
	 * @return array|false Lock data or false if not set.
	 */
	private static function get_lock_data() {
		return get_transient( self::LOCK_KEY );
	}

	/**
	 * Generate a unique owner ID for the current request.
	 *
	 * @return string Owner identifier.
	 */
	private static function get_owner_id(): string {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return md5( $remote_addr . '_' . microtime( true ) );
	}

	/**
	 * Perform the actual rate fetch + price sync.
	 *
	 * @return array|WP_Error
	 */
	public static function run_sync() {
		$logger      = Logger::get_instance();
		$api_manager = new API_Manager();

		// Attempt rate refresh.
		$rate = $api_manager->force_refresh();

		if ( is_wp_error( $rate ) ) {
			$logger->error( 'Rate fetch failed: ' . $rate->get_error_message(), array(
				'source' => 'cron_manager',
			) );
			return new \WP_Error(
				'fps_rate_fetch_failed',
				$rate->get_error_message()
			);
		}

		// Trigger the background queue.
		$chunks = Action_Scheduler_Handler::on_rates_updated( 'scheduled' );

		$logger->info( 'Sync complete. Chunks scheduled: ' . $chunks, array(
			'source'  => 'cron_manager',
			'chunks'  => $chunks,
		) );

		// Purge caches after sync.
		Cache_Purger::purge_all();

		return array(
			'chunks'    => $chunks,
			'rate'      => $rate,
			'timestamp' => current_time( 'mysql' ),
		);
	}

	/**
	 * Check whether we are rate-limited.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$last = get_transient( self::RATE_LIMIT_KEY );
		if ( false === $last ) {
			return false;
		}
		return ( time() - (int) $last ) < self::RATE_LIMIT_SECS;
	}

	/**
	 * Record a sync timestamp for rate limiting.
	 */
	private static function set_rate_limit() {
		set_transient( self::RATE_LIMIT_KEY, time(), DAY_IN_SECONDS );
	}

	/**
	 * Send a plain-text HTTP response and exit.
	 *
	 * @param int    $code  HTTP status code.
	 * @param string $body  Response body.
	 * @return void
	 */
	private static function respond( int $code, string $body ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $body );
		exit;
	}

	/**
	 * Register the cron token setting field in the Settings API.
	 */
	public static function register_settings() {
		add_settings_section(
			'fps_section_cron',
			esc_html__( 'Cron (Server Cron)', 'formula-price-sync' ),
			array( self::class, 'section_description' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_cron_token',
			esc_html__( 'Security Token', 'formula-price-sync' ),
			array( self::class, 'token_field_cb' ),
			'formula-price-sync',
			'fps_section_cron'
		);
	}

	/**
	 * Section description.
	 */
	public static function section_description() {
		$sample_url = home_url( '/?fps_action=cron_sync&token=' . esc_html__( 'YOUR_TOKEN_HERE', 'formula-price-sync' ) );
		?>
		<p class="fps-desc">
			<?php esc_html_e( 'This endpoint can be called with Linux crontab or any server scheduling tool.', 'formula-price-sync' ); ?>
		</p>
		<p class="fps-desc">
			<strong><?php esc_html_e( 'Crontab Example:', 'formula-price-sync' ); ?></strong><br>
			<code>0 * * * * curl -s "<?php echo esc_url( $sample_url ); ?>" > /dev/null 2>&1</code>
		</p>
		<p class="fps-desc">
			<?php esc_html_e( 'After saving settings, replace the token above with your security token. Minimum execution interval is 5 minutes.', 'formula-price-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Token field callback.
	 */
	public static function token_field_cb() {
		$token = get_option( self::OPTION_KEY, '' );
		?>
		<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
			value="<?php echo esc_attr( $token ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Enter a random token (e.g., aB3xK9mQ2)', 'formula-price-sync' ); ?>"
			autocomplete="off">
		<p class="fps-desc">
			<?php esc_html_e( 'This token is used in the cron URL. Keep it secure.', 'formula-price-sync' ); ?>
		</p>
		<?php if ( ! empty( $token ) ) : ?>
			<p class="fps-desc" style="color:var(--fps-color-success);">
				<?php
				$sample_url = home_url( '/?fps_action=cron_sync&token=' . esc_html( $token ) );
				printf(
					/* translators: %s: cron URL */
					esc_html__( 'Active URL: %s', 'formula-price-sync' ),
					'<code>' . esc_url( $sample_url ) . '</code>'
				);
				?>
			</p>
		<?php endif;
	}

	/**
	 * Register the cron token option so it survives settings save.
	 */
	public static function register_option() {
		register_setting(
			'fps_options_group',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_token' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitize the cron token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( string $token ): string {
		$token = sanitize_text_field( $token );
		if ( strlen( $token ) < 16 && '' !== $token ) {
			$token = '';
		}
		return $token;
	}

	/**
	 * Get the stored cron token (empty string if not set).
	 *
	 * @return string
	 */
	public static function get_token(): string {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Generate a secure random token.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

}
