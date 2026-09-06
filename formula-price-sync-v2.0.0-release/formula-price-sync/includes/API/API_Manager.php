<?php
/**
 * Central API Manager with caching, failover, stampede protection and Circuit Breaker.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API;

use FormulaPriceSync\API\Providers\TGJU;
use FormulaPriceSync\API\Providers\Navasan;
use FormulaPriceSync\API\Providers\Nobitex;
use FormulaPriceSync\API\Providers\Manual;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API_Manager
 *
 * Orchestrates rate fetching with transient cache, multi-provider failover
 * and Circuit Breaker protection.
 */
class API_Manager {

	/**
	 * Transient key for cached rates.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'fps_rates_cache';

	/**
	 * Cache duration in seconds (20 minutes – within 15-30 min requirement).
	 *
	 * @var int
	 */
	const CACHE_TTL = 20 * MINUTE_IN_SECONDS;

	/**
	 * Mutex lock key for cache stampede protection.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'fps_rates_fetch_lock';

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 10;

	/**
	 * Circuit Breaker instance.
	 *
	 * @var Circuit_Breaker
	 */
	private $circuit_breaker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->circuit_breaker = new Circuit_Breaker();
	}

	/**
	 * Get current rates (cached or freshly fetched).
	 *
	 * @param bool $force_refresh Force a new fetch ignoring cache.
	 * @return array Normalized rates array.
	 */
	public function get_rates( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return self::apply_rate_unit_conversion( $cached );
			}
		}

		// Cache stampede protection: if another request is fetching, serve stale.
		if ( ! $force_refresh && get_transient( self::LOCK_KEY ) ) {
			$stale = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
			return self::apply_rate_unit_conversion( is_array( $stale ) ? $stale : array() );
		}

		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$raw_rates = $this->fetch_with_failover();
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		if ( empty( $raw_rates ) ) {
			// Stale-while-revalidate / last accepted fallback.
			$last = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
			return self::apply_rate_unit_conversion( is_array( $last ) ? $last : array() );
		}

		// Run Circuit Breaker validation.
		$result = $this->circuit_breaker->validate( $raw_rates );

		if ( $result['accepted'] ) {
			// Cache RAW rates (before unit conversion) so changing divisor does not need API refetch.
			set_transient( self::CACHE_KEY, $result['rates'], self::CACHE_TTL );
		}

		return self::apply_rate_unit_conversion( $result['rates'] );
	}

	/**
	 * Convert API rates to store currency unit (e.g. divide by 10 for rial/toman mismatch).
	 *
	 * @param array $rates Rates.
	 * @return array
	 */
	public static function apply_rate_unit_conversion( array $rates ): array {
		if ( empty( $rates ) ) {
			return $rates;
		}

		$divisor = 1;
		if ( class_exists( '\FormulaPriceSync\Admin\Settings_API' ) ) {
			$divisor = (int) \FormulaPriceSync\Admin\Settings_API::get( 'rate_divisor', 1 );
		}
		/**
		 * Filter rate divisor applied to API rates before pricing.
		 *
		 * @param int   $divisor Divisor (1 = none, 10 = divide by ten).
		 * @param array $rates   Raw rates.
		 */
		$divisor = (int) apply_filters( 'fps_rate_unit_divisor', $divisor, $rates );

		if ( $divisor <= 1 ) {
			return $rates;
		}

		foreach ( array( 'usd', 'eur', 'gold_18k', 'gold_24k', 'coin' ) as $key ) {
			if ( isset( $rates[ $key ] ) && is_numeric( $rates[ $key ] ) ) {
				$val = (float) $rates[ $key ] / $divisor;
				$rates[ $key ] = is_finite( $val ) ? $val : 0.0;
			}
		}

		return $rates;
	}

	/**
	 * Fetch rates trying providers in priority order.
	 *
	 * @return array
	 */
	private function fetch_with_failover(): array {
		$providers = array(
			new TGJU(),
			new Navasan(),
			new Nobitex(),
			new Manual(),
		);

		/**
		 * Filter the list of rate providers.
		 *
		 * @param Fetcher_Interface[] $providers Ordered list of providers.
		 */
		$providers = apply_filters( 'fps_rate_providers', $providers );

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof Fetcher_Interface ) {
				continue;
			}

			$rates = $provider->fetch_rates();

			if ( ! empty( $rates ) && $this->is_valid_rates( $rates ) ) {
				/**
				 * Fires after a successful rate fetch from a provider.
				 *
				 * @param array             $rates    Normalized rates.
				 * @param Fetcher_Interface $provider The provider instance.
				 */
				do_action( 'fps_rates_fetched', $rates, $provider );

				return $rates;
			}
		}

		/**
		 * Fires when all providers failed.
		 *
		 * @param string[] $failed_providers List of provider class names that failed.
		 * @param string   $last_error       Last known error message (may be empty).
		 */
		$failed_names = array();
		foreach ( $providers as $p ) {
			if ( $p instanceof Fetcher_Interface ) {
				$failed_names[] = ( new \ReflectionClass( $p ) )->getShortName();
			}
		}
		do_action( 'fps_all_providers_failed', $failed_names, '' );

		return array();
	}

	/**
	 * Basic sanity check on returned rates (positive + finite).
	 *
	 * @param array $rates Rates to validate.
	 * @return bool
	 */
	private function is_valid_rates( array $rates ): bool {
		$keys = array( 'usd', 'gold_18k', 'gold_24k', 'coin' );
		foreach ( $keys as $key ) {
			if ( isset( $rates[ $key ] ) ) {
				$val = (float) $rates[ $key ];
				if ( is_finite( $val ) && $val > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Validate that a URL is safe for outbound requests (HTTPS + no private IPs).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		// Block obvious local hostnames.
		$blocked_hosts = array( 'localhost', '127.0.0.1', '0.0.0.0', '[::1]' );
		if ( in_array( strtolower( $host ), $blocked_hosts, true ) ) {
			return false;
		}

		$ip = gethostbyname( $host );
		if ( $ip === $host ) {
			// Could not resolve – allow only known public provider hosts via filter.
			$allowed = apply_filters( 'fps_allowed_api_hosts', array( 'call1.tgju.org', 'api.navasan.tech', 'api.nobitex.ir' ) );
			return in_array( strtolower( $host ), array_map( 'strtolower', (array) $allowed ), true );
		}

		$ip_long = ip2long( $ip );
		if ( false === $ip_long ) {
			return false;
		}

		$private_ranges = array(
			array( '10.0.0.0', '10.255.255.255' ),
			array( '172.16.0.0', '172.31.255.255' ),
			array( '192.168.0.0', '192.168.255.255' ),
			array( '127.0.0.0', '127.255.255.255' ),
			array( '169.254.0.0', '169.254.255.255' ),
		);

		foreach ( $private_ranges as $range ) {
			if ( $ip_long >= ip2long( $range[0] ) && $ip_long <= ip2long( $range[1] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Safe wp_remote_get wrapper with HTTPS/SSRF checks.
	 *
	 * @param string $url  Endpoint URL.
	 * @param array  $args Request args.
	 * @return array|\WP_Error
	 */
	public static function safe_remote_get( string $url, array $args = array() ) {
		if ( ! self::is_safe_url( $url ) ) {
			return new \WP_Error( 'fps_unsafe_url', 'Unsafe or non-HTTPS URL blocked.' );
		}

		$defaults = array(
			'timeout'     => 12,
			'redirection' => 2,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'FormulaPriceSync/' . ( defined( 'FPS_VERSION' ) ? FPS_VERSION : '1.0' ) . ' (+https://webgraphx.ir)',
			),
		);

		return wp_remote_get( $url, array_merge( $defaults, $args ) );
	}

	/**
	 * Force refresh rates and return the result.
	 *
	 * @return array
	 */
	public function force_refresh(): array {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::LOCK_KEY );
		return $this->get_rates( true );
	}

	/**
	 * Get the currently active source name from cache.
	 *
	 * @return string
	 */
	public function get_current_source(): string {
		$rates = $this->get_rates();
		return isset( $rates['source'] ) ? (string) $rates['source'] : 'unknown';
	}

	/**
	 * Clear the rates cache.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Fetch and cache rates, returning the primary USD rate.
	 *
	 * @return float|\WP_Error
	 */
	public static function fetch_and_cache_rate() {
		$instance = new self();
		$rates    = $instance->get_rates( true );

		if ( empty( $rates ) ) {
			return new \WP_Error( 'fps_no_rates', __( 'نرخی دریافت نشد.', 'formula-price-sync' ) );
		}

		$rate = isset( $rates['usd'] ) ? (float) $rates['usd'] : 0.0;
		if ( ! is_finite( $rate ) || $rate <= 0 ) {
			return new \WP_Error( 'fps_invalid_rate', __( 'نرخ دریافتی معتبر نیست.', 'formula-price-sync' ) );
		}

		return $rate;
	}

	/**
	 * Get the last used rate source from cache.
	 *
	 * @return string
	 */
	public static function get_last_source(): string {
		$rates = get_transient( self::CACHE_KEY );
		if ( ! is_array( $rates ) ) {
			$rates = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
		}
		return isset( $rates['source'] ) ? (string) $rates['source'] : 'unknown';
	}

	/**
	 * Get the timestamp of the last rate update.
	 *
	 * @return string
	 */
	public static function get_last_updated(): string {
		$rates = get_transient( self::CACHE_KEY );
		if ( ! is_array( $rates ) ) {
			$rates = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
		}
		return isset( $rates['timestamp'] ) ? (string) $rates['timestamp'] : '';
	}
}
