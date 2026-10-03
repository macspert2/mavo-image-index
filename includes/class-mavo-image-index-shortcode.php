<?php
/**
 * [mavo_image_more concept="turquoise_water" text="Voir d’autres plages aux eaux turquoise"]
 *
 * One plain, crawlable link to wherever "more images like this" lives. First
 * answer wins:
 *
 *   1. url="…" on the shortcode
 *   2. Tools → Image Index → link targets: the concept, for this language or
 *      for any language
 *   3. the mavo_image_more_url filter (sees the answer so far)
 *   4. the generic destination in the same settings ('*'), which may use
 *      {concept}, {label} and {lang}
 *   5. the results page (MII_Results), when one is configured — normally
 *      the only setting needed
 *
 * A target can be a page ID instead of a URL, which is the better choice on a
 * multilingual site: one entry serves every language, through Polylang's
 * translation of that page. A page with no translation in the link's
 * language gives no link rather than a link into another language.
 *
 * No target, an unknown concept, or a target that does not resolve: the
 * shortcode prints nothing. Never a dead link, never an error on the page.
 *
 * The output is identical for every visitor, so full-page caching is safe.
 */

defined( 'ABSPATH' ) || exit;

class MII_Shortcode {

	const TAG            = 'mavo_image_more';
	const TARGETS_OPTION = 'mavo_image_index_more_targets';

	/** Per-language text, per the project's convention rather than gettext. */
	const DEFAULT_TEXT = [
		'fr' => 'Voir d’autres images similaires',
		'en' => 'See more like this',
		'de' => 'Ähnliche Bilder entdecken',
	];

	public static function init(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'render' ] );
	}

	public static function render( $atts ): string {
		$atts = shortcode_atts( [
			'concept'  => '',
			'text'     => '',
			'lang'     => '',
			'post_id'  => 0,
			'image_id' => 0,
			'class'    => '',
			'url'      => '',
		], (array) $atts, self::TAG );

		$concept = MII_Concepts::sanitize_slug( (string) $atts['concept'] );

		if ( '' === $concept || ! MII_Concepts::exists( $concept ) ) {
			return '';
		}

		$lang     = MII_Lang::resolve( (string) $atts['lang'] );
		$image_id = absint( $atts['image_id'] );
		$context  = [
			'post_id'  => absint( $atts['post_id'] ) ?: (int) get_the_ID(),
			'image_id' => $image_id,
		];

		/**
		 * Whether an explicit image_id must actually carry the concept for the
		 * link to render. Off by default: an author who wrote both has said
		 * what the image shows, and alt text can lag behind.
		 */
		if ( $image_id && apply_filters( 'mavo_image_more_strict', false, $concept, $image_id, $lang )
			&& ! mavo_image_has_concept( $image_id, $concept )
		) {
			return '';
		}

		$url = self::target_url( $concept, $lang, (string) $atts['url'], $context );

		if ( '' === $url ) {
			return '';
		}

		$text = trim( (string) $atts['text'] );

		if ( '' === $text ) {
			$text = self::default_text( $concept, $lang );
		}

		$classes = array_merge(
			[ 'mavo-image-more', 'mavo-image-more--' . str_replace( '_', '-', $concept ) ],
			array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) )
		);

		return sprintf(
			'<a class="%s" href="%s">%s</a>',
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_url( $url ),
			esc_html( $text )
		);
	}

	/** '' when nothing resolves. */
	public static function target_url( string $concept, string $lang, string $explicit = '', array $context = [] ): string {
		$url = '' !== trim( $explicit ) ? esc_url_raw( trim( $explicit ) ) : '';

		if ( '' === $url ) {
			$url = self::resolve_target( self::mapped( $concept, $lang ), $concept, $lang );
		}

		/** The link target for one concept, or '' for none. */
		$url = (string) apply_filters( 'mavo_image_more_url', $url, $concept, $lang, $context );

		if ( '' === $url ) {
			$url = self::resolve_target( self::mapped( '*', $lang ), $concept, $lang );
		}

		// The plugin's own results page, when one is set up.
		if ( '' === $url ) {
			$url = MII_Results::url( $concept, $lang );
		}

		return $url;
	}

	/**
	 * Stored targets: [ key => target ], key "concept", "concept@lang", "*" or
	 * "*@lang"; target a page ID or a URL.
	 *
	 * @return array<string,string>
	 */
	public static function targets(): array {
		return array_map( 'strval', (array) get_option( self::TARGETS_OPTION, [] ) );
	}

	/** Parse the admin textarea: one "key = target" per line. */
	public static function parse_targets( string $text ): array {
		$out = [];

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) || false === strpos( $line, '=' ) ) {
				continue;
			}

			[ $key, $target ] = array_map( 'trim', explode( '=', $line, 2 ) );

			$lang = '';
			if ( str_contains( $key, '@' ) ) {
				[ $key, $lang ] = array_map( 'trim', explode( '@', $key, 2 ) );
				$lang = sanitize_key( $lang );
			}

			$key = '*' === $key ? '*' : MII_Concepts::sanitize_slug( $key );

			if ( '' === $key || '' === $target ) {
				continue;
			}

			$target = ctype_digit( $target ) ? (string) absint( $target ) : esc_url_raw( $target );

			if ( '' !== $target ) {
				$out[ $key . ( '' !== $lang ? '@' . $lang : '' ) ] = $target;
			}
		}

		return $out;
	}

	public static function default_text( string $concept, string $lang ): string {
		$def  = MII_Concepts::get( $concept );
		$text = $def['more_text'][ $lang ] ?? self::DEFAULT_TEXT[ $lang ] ?? self::DEFAULT_TEXT[ MII_Lang::FALLBACK ];

		/** Link text when the shortcode gives none. */
		return (string) apply_filters( 'mavo_image_more_text', $text, $concept, $lang );
	}

	/* -------------------------------------------------------------- private */

	private static function mapped( string $key, string $lang ): string {
		$targets = self::targets();

		return $targets[ $key . '@' . $lang ] ?? $targets[ $key ] ?? '';
	}

	private static function resolve_target( string $target, string $concept, string $lang ): string {
		if ( '' === $target ) {
			return '';
		}

		if ( ctype_digit( $target ) ) {
			$page = (int) $target;

			if ( function_exists( 'pll_get_post' ) ) {
				$page = (int) pll_get_post( $page, $lang );
			}

			if ( ! $page || 'publish' !== get_post_status( $page ) ) {
				return '';
			}

			$url = (string) get_permalink( $page );
		} else {
			$url = $target;
		}

		$url = strtr( $url, [
			'{concept}' => rawurlencode( $concept ),
			'{label}'   => rawurlencode( MII_Concepts::label( $concept, $lang ) ),
			'{lang}'    => rawurlencode( $lang ),
		] );

		return esc_url_raw( $url );
	}
}
