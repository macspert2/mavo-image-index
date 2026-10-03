<?php
/**
 * The concept registry: data/concepts.php, plus whatever other plugins add.
 *
 * Other plugins extend it in either of two ways, both without touching this
 * one:
 *
 *   add_action( 'mavo_image_register_concepts', function () {
 *       mavo_register_image_concept( 'street_art', [ 'labels' => …, 'synonyms' => … ] );
 *   } );
 *
 *   add_filter( 'mavo_image_concept_definitions', function ( $defs ) { … } );
 *
 * Registering a slug that already exists EXTENDS it — synonyms and except
 * phrases are appended, labels replaced per language — so a plugin can teach
 * "beach" a word without restating the definition.
 *
 * Every definition contributes to version(), so a dictionary change marks
 * every semantics row stale and a "rebuild stale" re-matches them.
 */

defined( 'ABSPATH' ) || exit;

class MII_Concepts {

	const VERSION_OPTION = 'mavo_image_index_concept_version';

	private static array $registered = [];
	private static ?array $definitions = null;
	private static ?string $version = null;
	private static bool $collecting = false;

	public static function register( string $slug, array $definition ): void {
		$slug = self::sanitize_slug( $slug );

		if ( '' === $slug ) {
			return;
		}

		self::$registered[ $slug ] = isset( self::$registered[ $slug ] )
			? self::extend( self::$registered[ $slug ], $definition )
			: $definition;

		// Registered after the registry was built: rebuild it on next use. Not
		// while collecting, which would loop.
		if ( ! self::$collecting ) {
			self::$definitions = null;
			self::$version     = null;
		}
	}

	/** @return array<string,array> slug => normalized definition */
	public static function all(): array {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		$defs = (array) include MII_PLUGIN_DIR . 'data/concepts.php';

		self::$collecting = true;
		/** Register concepts with mavo_register_image_concept() here. */
		do_action( 'mavo_image_register_concepts' );
		self::$collecting = false;

		foreach ( self::$registered as $slug => $definition ) {
			$defs[ $slug ] = isset( $defs[ $slug ] ) ? self::extend( $defs[ $slug ], $definition ) : $definition;
		}

		/** The whole dictionary, after registration. */
		$defs = (array) apply_filters( 'mavo_image_concept_definitions', $defs );

		$out = [];

		foreach ( $defs as $slug => $definition ) {
			$slug = self::sanitize_slug( (string) $slug );

			if ( '' !== $slug && is_array( $definition ) ) {
				$out[ $slug ] = self::normalize( $definition );
			}
		}

		ksort( $out );

		return self::$definitions = $out;
	}

	public static function exists( string $slug ): bool {
		return isset( self::all()[ $slug ] );
	}

	public static function get( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	/** @return string[] */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * A concept's label in a language: that language, then the default, then
	 * the slug made readable — never empty, so a consumer can always print it.
	 */
	public static function label( string $slug, ?string $lang = null ): string {
		$lang   = MII_Lang::resolve( $lang );
		$labels = self::get( $slug )['labels'] ?? [];
		$label  = $labels[ $lang ] ?? $labels[ MII_Lang::default_language() ] ?? ucfirst( str_replace( '_', ' ', $slug ) );

		return (string) apply_filters( 'mavo_image_concept_label', $label, $slug, $lang );
	}

	/**
	 * A short hash of the dictionary and the matcher's own rules.
	 *
	 * Stored per semantics row; a row with a different version is stale.
	 */
	public static function version(): string {
		if ( null === self::$version ) {
			self::$version = substr( md5( MII_Matcher::VERSION . '|' . serialize( self::all() ) ), 0, 12 );
		}

		return self::$version;
	}

	/**
	 * Record the current version in the option agent.md asks for, so the admin
	 * screen can say "the dictionary changed". Called from the admin and the
	 * indexer, never on the front end.
	 */
	public static function sync_version_option(): string {
		$version = self::version();

		if ( get_option( self::VERSION_OPTION ) !== $version ) {
			update_option( self::VERSION_OPTION, $version, false );
		}

		return $version;
	}

	public static function sanitize_slug( string $slug ): string {
		return substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( str_replace( '-', '_', $slug ) ) ), 0, 64 );
	}

	/** For tests: forget registrations and the built registry. */
	public static function reset(): void {
		self::$registered  = [];
		self::$definitions = null;
		self::$version     = null;
	}

	/* -------------------------------------------------------------- private */

	private static function normalize( array $def ): array {
		$out = [
			'group'     => sanitize_key( (string) ( $def['group'] ?? 'other' ) ),
			'labels'    => [],
			'synonyms'  => [],
			'except'    => [],
			'implies'   => [],
			'more_text' => [],
		];

		foreach ( [ 'labels', 'more_text' ] as $key ) {
			foreach ( (array) ( $def[ $key ] ?? [] ) as $lang => $text ) {
				$out[ $key ][ sanitize_key( (string) $lang ) ] = (string) $text;
			}
		}

		foreach ( [ 'synonyms', 'except' ] as $key ) {
			foreach ( (array) ( $def[ $key ] ?? [] ) as $lang => $phrases ) {
				$out[ $key ][ sanitize_key( (string) $lang ) ] = array_values( (array) $phrases );
			}
		}

		foreach ( (array) ( $def['implies'] ?? [] ) as $implied ) {
			$implied = self::sanitize_slug( (string) $implied );

			if ( '' !== $implied ) {
				$out['implies'][] = $implied;
			}
		}

		return $out;
	}

	/** Merge an extension onto a definition: lists append, maps replace. */
	private static function extend( array $base, array $extra ): array {
		foreach ( $extra as $key => $value ) {
			if ( in_array( $key, [ 'synonyms', 'except' ], true ) ) {
				foreach ( (array) $value as $lang => $phrases ) {
					$base[ $key ][ $lang ] = array_merge( (array) ( $base[ $key ][ $lang ] ?? [] ), (array) $phrases );
				}
			} elseif ( in_array( $key, [ 'labels', 'more_text' ], true ) ) {
				$base[ $key ] = array_merge( (array) ( $base[ $key ] ?? [] ), (array) $value );
			} elseif ( 'implies' === $key ) {
				$base[ $key ] = array_values( array_unique( array_merge( (array) ( $base[ $key ] ?? [] ), (array) $value ) ) );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}
}
