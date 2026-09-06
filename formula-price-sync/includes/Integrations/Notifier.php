<?php
/**
 * Notification system for Formula Price Sync.
 *
 * Sends alerts via Telegram, SMS, or other configured channels.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSyncIntegrations;

use FormulaPriceSyncCoreLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification handler class.
 *
 * Supports multiple notification channels (Telegram, SMS) with
 * configurable settings and rate limiting.
 */
class Notifier {

	/**
	 * Option names for settings.
	 */
	const OPTION_TELEGRAM_BOT_TOKEN = 'fps_telegram_bot_token';
	const OPTION_TELEGRAM_CHAT_ID  = 'fps_telegram_chat_id';
	const OPTION_SMS_PROVIDER       = 'fps_sms_provider';
	const OPTION_SMS_API_KEY        = 'fps_sms_api_key';
	const OPTION_SMS_LINE_NUMBER    = 'fps_sms_line_number';
	const OPTION_SMS_RECIPIENT      = 'fps_sms_recipient';

	/**
	 * Rate limit transient key.
	 */
	const RATE_LIMIT_KEY = 'fps_notifier_rate_limit';

	/**
	 * Rate limit duration in seconds (1 hour).
	 */
	const RATE_LIMIT_DURATION = 3600;

	/**
	 * Maximum alerts per hour.
	 */
	const MAX_ALERTS_PER_HOUR = 10;

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		
		// Register event listeners with standardized contracts
		add_action( 'fps_circuit_breaker_triggered', array( self::class, 'send_alert_on_circuit_breaker' ), 10, 1 );
		add_action( 'fps_all_providers_failed', array( self::class, 'send_provider_failure_alert' ), 10, 2 );
		add_action( 'fps_chunk_processed', array( self::class, 'send_bulk_sync_complete_alert' ), 10, 3 );
		add_action( 'fps_bulk_sync_failed', array( self::class, 'send_bulk_sync_failed_alert' ), 10, 1 );
		add_action( 'fps_cron_failure', array( self::class, 'send_cron_failure_alert' ), 10, 1 );
	}

	/**
	 * Register notification settings in the Settings API.
	 */
	public static function register_settings() {
		register_setting( 'fps_options_group', self::OPTION_TELEGRAM_BOT_TOKEN, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_TELEGRAM_CHAT_ID, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_PROVIDER, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_API_KEY, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_LINE_NUMBER, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_RECIPIENT, 'sanitize_text_field' );

		add_settings_section(
			'fps_section_notifications',
			esc_html__( 'Notifications (Telegram / SMS)', 'formula-price-sync' ),
			array( self::class, 'section_description' ),
			'formula-price-sync'
		);

		// Telegram settings
		add_settings_field(
			'fps_telegram_bot_token',
			esc_html__( 'Telegram Bot Token', 'formula-price-sync' ),
			array( self::class, 'telegram_bot_token_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_telegram_chat_id',
			esc_html__( 'Telegram Chat ID', 'formula-price-sync' ),
			array( self::class, 'telegram_chat_id_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		// SMS settings
		add_settings_field(
			'fps_sms_provider',
			esc_html__( 'SMS Provider', 'formula-price-sync' ),
			array( self::class, 'sms_provider_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_api_key',
			esc_html__( 'SMS API Key', 'formula-price-sync' ),
			array( self::class, 'sms_api_key_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_line_number',
			esc_html__( 'SMS Line Number', 'formula-price-sync' ),
			array( self::class, 'sms_line_number_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_recipient',
			esc_html__( 'SMS Recipient', 'formula-price-sync' ),
			array( self::class, 'sms_recipient_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);
	}

	/**
	 * Section description.
	 */
	public static function section_description() {
		esc_html_e( 'Configure notification channels for important events like circuit breaker triggers and sync failures.', 'formula-price-sync' );
	}

	/**
	 * Field callbacks for settings.
	 */
	public static function telegram_bot_token_field_cb() {
		$value = get_option( self::OPTION_TELEGRAM_BOT_TOKEN, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_TELEGRAM_BOT_TOKEN ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="e.g., 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">';
	}

	public static function telegram_chat_id_field_cb() {
		$value = get_option( self::OPTION_TELEGRAM_CHAT_ID, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_TELEGRAM_CHAT_ID ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="e.g., -1001234567890">';
	}

	public static function sms_provider_field_cb() {
		$value = get_option( self::OPTION_SMS_PROVIDER, '' );
		$providers = array(
			'kavenegar' => 'Kavenegar',
			'melipayamak' => 'Melipayamak',
			'other' => 'Other',
		);
		echo '<select name="' . esc_attr( self::OPTION_SMS_PROVIDER ) . '">';
		foreach ( $providers as $key => $label ) {
			$selected = selected( $value, $key, false );
			echo '<option value="' . esc_attr( $key ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function sms_api_key_field_cb() {
		$value = get_option( self::OPTION_SMS_API_KEY, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_SMS_API_KEY ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public static function sms_line_number_field_cb() {
		$value = get_option( self::OPTION_SMS_LINE_NUMBER, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_SMS_LINE_NUMBER ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="e.g., 10001234567890">';
	}

	public static function sms_recipient_field_cb() {
		$value = get_option( self::OPTION_SMS_RECIPIENT, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_SMS_RECIPIENT ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="e.g., 09123456789">';
	}

	/**
	 * Send alert for circuit breaker trigger.
	 *
	 * Standardized event contract:
	 * array(
	 *     'message'   => string,
	 *     'provider'  => string,
	 *     'reason'    => string,
	 *     'rates'     => array,
	 *     'timestamp' => string,
	 * )
	 *
	 * @param array $event_data Standardized event data.
	 */
	public static function send_alert_on_circuit_breaker( array $event_data ): void {
		// Extract data from standardized contract
		$message = $event_data['message'] ?? '';
		$provider = $event_data['provider'] ?? '';
		$reason = $event_data['reason'] ?? 'unknown';
		$rates = $event_data['rates'] ?? array();
		$timestamp = $event_data['timestamp'] ?? current_time( 'mysql' );

		// Build notification text
		$text = '⚠️ ' . __( 'Circuit Breaker', 'formula-price-sync' ) . ': ' . $message;
		
		if ( ! empty( $reason ) ) {
			$text .= '
' . __( 'Reason', 'formula-price-sync' ) . ': ' . $reason;
		}
		
		if ( ! empty( $provider ) ) {
			$text .= '
' . __( 'Provider', 'formula-price-sync' ) . ': ' . $provider;
		}
		
		if ( ! empty( $timestamp ) ) {
			$text .= '
' . __( 'Time', 'formula-price-sync' ) . ': ' . $timestamp;
		}

		self::dispatch( $text, 'circuit_breaker' );
	}

	/**
	 * Send alert when all API providers have failed.
	 *
	 * @param string[] $failed_providers List of provider short names that failed.
	 * @param string   $last_error       Optional last error detail.
	 * @return void
	 */
	public static function send_provider_failure_alert( $failed_providers = array(), string $last_error = '' ): void {
		if ( ! is_array( $failed_providers ) ) {
			$failed_providers = array();
		}

		$provider_list = ! empty( $failed_providers ) 
			? implode( ', ', array_map( 'strval', $failed_providers ) )
			: __( 'Unknown providers', 'formula-price-sync' );

		$text = '❌ ' . __( 'Provider Failure', 'formula-price-sync' ) . ': ' . $provider_list;

		if ( ! empty( $last_error ) ) {
			$text .= '
' . $last_error;
		}

		self::dispatch( $text, 'provider_failure' );
	}

	/**
	 * Send alert when bulk sync completes.
	 *
	 * @param int    $total_chunks   Total number of chunks.
	 * @param int    $completed_chunks Number of completed chunks.
	 * @param int    $failed_chunks   Number of failed chunks.
	 * @return void
	 */
	public static function send_bulk_sync_complete_alert( int $total_chunks, int $completed_chunks, int $failed_chunks ): void {
		$text = '✅ ' . __( 'Bulk Sync Complete', 'formula-price-sync' ) . ': ';
		$text .= sprintf(
			/* translators: %1$d: completed, %2$d: failed, %3$d: total */
			__( '%1$d of %3$d chunks completed, %2$d failed.', 'formula-price-sync' ),
			$completed_chunks,
			$failed_chunks,
			$total_chunks
		);

		self::dispatch( $text, 'bulk_sync_complete' );
	}

	/**
	 * Send alert when bulk sync fails.
	 *
	 * @param array $event_data Event data containing error information.
	 */
	public static function send_bulk_sync_failed_alert( array $event_data ): void {
		$message = $event_data['message'] ?? '';
		$text = '❌ ' . __( 'Bulk Sync Failed', 'formula-price-sync' ) . ': ' . $message;

		self::dispatch( $text, 'bulk_sync_failed' );
	}

	/**
	 * Send alert when cron fails.
	 *
	 * @param array $event_data Event data containing error information.
	 */
	public static function send_cron_failure_alert( array $event_data ): void {
		$message = $event_data['message'] ?? '';
		$text = '❌ ' . __( 'Cron Failure', 'formula-price-sync' ) . ': ' . $message;

		self::dispatch( $text, 'cron_failure' );
	}

	/**
	 * Dispatch notification to all configured channels.
	 *
	 * @param string $text    Notification text.
	 * @param string $channel Channel identifier.
	 */
	private static function dispatch( string $text, string $channel ): void {
		// Check rate limiting
		if ( self::is_rate_limited() ) {
			return;
		}

		// Log the notification
		$logger = Logger::get_instance();
		$logger->info( 'Notification dispatched: ' . $channel, array( 'text' => $text ) );

		// Send to Telegram
		self::send_to_telegram( $text );

		// Send to SMS
		self::send_to_sms( $text );

		// Increment rate limit counter
		self::increment_rate_limit();
	}

	/**
	 * Send notification to Telegram.
	 *
	 * @param string $text Message text.
	 */
	private static function send_to_telegram( string $text ): void {
		$bot_token = get_option( self::OPTION_TELEGRAM_BOT_TOKEN, '' );
		$chat_id   = get_option( self::OPTION_TELEGRAM_CHAT_ID, '' );

		if ( empty( $bot_token ) || empty( $chat_id ) ) {
			return;
		}

		$api_url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
		$args = array(
			'body' => array(
				'chat_id' => $chat_id,
				'text'    => $text,
			),
		);

		// Use WordPress HTTP API to avoid cURL requirements
		$response = wp_remote_post( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			$logger = Logger::get_instance();
			$logger->error( 'Telegram notification failed: ' . $response->get_error_message() );
		}
	}

	/**
	 * Send notification to SMS.
	 *
	 * @param string $text Message text.
	 */
	private static function send_to_sms( string $text ): void {
		$provider = get_option( self::OPTION_SMS_PROVIDER, '' );
		$api_key  = get_option( self::OPTION_SMS_API_KEY, '' );
		$line_num = get_option( self::OPTION_SMS_LINE_NUMBER, '' );
		$recipient = get_option( self::OPTION_SMS_RECIPIENT, '' );

		if ( empty( $provider ) || empty( $api_key ) || empty( $recipient ) ) {
			return;
		}

		// For now, just log SMS notifications
		// Full SMS implementation would require provider-specific APIs
		$logger = Logger::get_instance();
		$logger->info( 'SMS notification would be sent: ' . $text );
	}

	/**
	 * Check if rate limited.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$count = get_transient( self::RATE_LIMIT_KEY );
		return false !== $count && $count >= self::MAX_ALERTS_PER_HOUR;
	}

	/**
	 * Increment rate limit counter.
	 */
	private static function increment_rate_limit(): void {
		$count = get_transient( self::RATE_LIMIT_KEY );
		if ( false === $count ) {
			$count = 0;
		}
		$count++;
		set_transient( self::RATE_LIMIT_KEY, $count, self::RATE_LIMIT_DURATION );
	}

}