<?php
/**
 * Which languages exist, which one is current, and which one a post is in.
 *
 * Asked of Polylang when it is there. Without it the site's three languages
 * are still assumed, deliberately: the alt text for en and de stays on the
 * attachments whether or not Polylang is active, and a rebuild run while it
 * is briefly off must not wipe two thirds of the index.
 */

defined( 'ABSPATH' ) || exit;

class MII_Lang {

	const FALLBACK  = 'fr';
	const SUPPORTED = [ 'fr', 'en', 'de' ];

	private static ?array $languages = null;

	/** @return string[] Default language first. */
	public static function languages(): array {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$list = self::SUPPORTED;

		if ( function_exists( 'pll_languages_list' ) ) {
			$pll = array_values( array_filter( array_map( 'strval', (array) pll_languages_list( [ 'fields' => 'slug' ] ) ) ) );

			if ( $pll ) {
				$list = $pll;
			}
		}

		$default = self::default_language();
		$list    = array_merge( [ $default ], array_values( array_diff( $list, [ $default ] ) ) );

		/** Languages the index reads alt text in. Default language first. */
		$list = (array) apply_filters( 'mavo_image_languages', $list );

		return self::$languages = array_values( array_unique( array_filter( array_map( 'sanitize_key', $list ) ) ) );
	}

	public static function default_language(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = (string) pll_default_language( 'slug' );

			if ( '' !== $lang ) {
				return $lang;
			}
		}

		return self::FALLBACK;
	}

	/** Polylang's current language, else the default. Never empty. */
	public static function current(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = self::normalize( (string) pll_current_language( 'slug' ) );

			if ( null !== $lang ) {
				return $lang;
			}
		}

		return self::default_language();
	}

	/** A known language slug, or null. */
	public static function normalize( ?string $lang ): ?string {
		if ( null === $lang ) {
			return null;
		}

		$lang = strtolower( trim( $lang ) );

		return in_array( $lang, self::languages(), true ) ? $lang : null;
	}

	/** $lang if known, else the current language. */
	public static function resolve( ?string $lang ): string {
		return self::normalize( $lang ) ?? self::current();
	}

	/**
	 * Post languages in one query: [ post_id => slug ].
	 *
	 * Polylang keeps a post's language as a term in the 'language' taxonomy,
	 * keyed on the post ID like any ordinary taxonomy, so a join answers for
	 * a whole batch where pll_get_post_language() would cost a call per post.
	 * Posts without a language get the default.
	 *
	 * @param int[] $post_ids
	 * @return array<int,string>
	 */
	public static function post_languages( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$default  = self::default_language();
		$out      = array_fill_keys( $post_ids, $default );

		if ( ! $post_ids ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			"SELECT tr.object_id AS post_id, t.slug AS lang
			   FROM {$wpdb->term_relationships} tr
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'language'
			   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			  WHERE tr.object_id IN (" . MII_DB::in_ints( $post_ids ) . ')',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$lang = self::normalize( (string) $row['lang'] );

			if ( null !== $lang ) {
				$out[ (int) $row['post_id'] ] = $lang;
			}
		}

		return $out;
	}

	/** For tests, and for a mavo_image_languages filter added late. */
	public static function reset(): void {
		self::$languages = null;
	}
}
