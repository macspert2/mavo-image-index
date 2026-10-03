<?php
/**
 * Two layers: a per-request array, and the WordPress object cache.
 *
 * Every key carries a generation number, and invalidation is one increment.
 * That is coarse on purpose. Finding every cached search that an alt edit
 * could change is the problem the generation avoids: with a persistent object
 * cache the old entries simply stop being read and expire; without one the
 * object cache lives for a single request anyway, and nothing is lost.
 *
 * No transients for per-image data: on a site without a persistent cache they
 * would be thousands of rows in wp_options.
 */

defined( 'ABSPATH' ) || exit;

class MII_Cache {

	const GROUP      = 'mavo_image_index';
	const GEN_OPTION = 'mavo_image_index_cache_gen';
	const TTL        = 6 * HOUR_IN_SECONDS;

	private static ?int $gen = null;
	private static array $local = [];

	public static function get( string $key ) {
		$key = self::key( $key );

		if ( array_key_exists( $key, self::$local ) ) {
			return self::$local[ $key ];
		}

		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found ) {
			self::$local[ $key ] = $value;
			return $value;
		}

		return null;
	}

	public static function set( string $key, $value ): void {
		$key = self::key( $key );

		self::$local[ $key ] = $value;
		wp_cache_set( $key, $value, self::GROUP, self::TTL );
	}

	/** Invalidate everything. */
	public static function bump(): void {
		self::$gen   = self::gen() + 1;
		self::$local = [];

		update_option( self::GEN_OPTION, self::$gen, true );

		/** Fires after the image index changed in a way cached answers can see. */
		do_action( 'mavo_image_index_cache_bumped', self::$gen );
	}

	private static function gen(): int {
		if ( null === self::$gen ) {
			self::$gen = max( 1, (int) get_option( self::GEN_OPTION, 1 ) );
		}

		return self::$gen;
	}

	private static function key( string $key ): string {
		return self::gen() . ':' . $key;
	}

	/** For tests. */
	public static function reset(): void {
		self::$gen   = null;
		self::$local = [];
	}
}
