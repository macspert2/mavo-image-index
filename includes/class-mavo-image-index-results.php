<?php
/**
 * The results page: where [mavo_image_more] links lead.
 *
 * One ordinary page, chosen in Tools → Image Index, with its Polylang
 * translations. Each concept gets its own URL below that page, in each
 * language's words:
 *
 *   /images/eaux-turquoise/            fr
 *   /en/images/turquoise-water/        en
 *   /de/bilder/tuerkisfarbenes-wasser/  de
 *   …/eaux-turquoise/2/                page 2
 *
 * The page itself, without a concept, lists every concept that has images
 * in that language.
 *
 * Real paths rather than ?concept=, because the site sits behind Swift
 * Performance full-page caching: a path is cached per concept with no
 * assumption about how the cache treats query strings, and each concept page
 * is a distinct, crawlable URL. ?concept= is still read, as a fallback for
 * the moments before rewrite rules are flushed.
 *
 * Rewrite rules are built from each translation's actual permalink path,
 * /en/ prefix included, so they do not depend on how Polylang rewrites
 * rules. The paths are stored in an option when they change, so a normal
 * request registers them without computing a permalink.
 *
 * Tiles, grid and badges are the theme's (mv-tiles.css, loaded on every
 * page), in the markup its card-post.php uses, so these pages look like
 * every other list of posts on the site. results.css only adds layout glue.
 *
 * Output is server-rendered and identical for every visitor. Rendered by
 * [mavo_image_results], or appended to the page's content automatically
 * when the page does not contain the shortcode.
 */

defined( 'ABSPATH' ) || exit;

class MII_Results {

	const TAG          = 'mavo_image_results';
	const PAGE_OPTION  = 'mavo_image_index_results_page';
	const PATHS_OPTION = 'mavo_image_index_results_paths';
	const FLUSH_OPTION = 'mavo_image_index_results_flush';

	const ROWS_OPTION  = 'mavo_image_index_browse_rows';

	const PER_PAGE     = 24;
	const PER_ARTICLE  = 2;

	/** A browse row: up to this many articles… */
	const ROW_TILES    = 12;

	/** …and none at all with fewer than this many, in that language. */
	const ROW_MIN      = 4;

	/** Visitor-facing text, per language (the project's convention, not gettext). */
	const TEXT = [
		'count'    => [ 'fr' => '%d photos', 'en' => '%d photos', 'de' => '%d Fotos' ],
		'one'      => [ 'fr' => '1 photo', 'en' => '1 photo', 'de' => '1 Foto' ],
		'unit'     => [ 'fr' => 'photos', 'en' => 'photos', 'de' => 'Fotos' ],
		'unit_one' => [ 'fr' => 'photo', 'en' => 'photo', 'de' => 'Foto' ],
		'from'     => [ 'fr' => 'Article :', 'en' => 'From:', 'de' => 'Artikel:' ],
		'all'      => [ 'fr' => 'Toutes les thématiques', 'en' => 'All themes', 'de' => 'Alle Themen' ],
		'related'  => [ 'fr' => 'Voir aussi', 'en' => 'See also', 'de' => 'Siehe auch' ],
		'more'     => [ 'fr' => 'Tout voir', 'en' => 'See all', 'de' => 'Alle ansehen' ],
		'prev_row' => [ 'fr' => 'Précédent', 'en' => 'Previous', 'de' => 'Zurück' ],
		'next_row' => [ 'fr' => 'Suivant', 'en' => 'Next', 'de' => 'Weiter' ],
		'empty'    => [ 'fr' => 'Aucune photo pour le moment.', 'en' => 'No photos yet.', 'de' => 'Noch keine Fotos.' ],
		'prev'     => [ 'fr' => '← Page précédente', 'en' => '← Previous page', 'de' => '← Vorherige Seite' ],
		'next'     => [ 'fr' => 'Page suivante →', 'en' => 'Next page →', 'de' => 'Nächste Seite →' ],
		'page'     => [ 'fr' => 'Page %1$d sur %2$d', 'en' => 'Page %1$d of %2$d', 'de' => 'Seite %1$d von %2$d' ],
		'metadesc' => [ 'fr' => 'Nos photos de voyage en famille : %s.', 'en' => 'Our family travel photos: %s.', 'de' => 'Unsere Familienreise-Fotos: %s.' ],
	];

	private static ?array $current = null;

	public static function init(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'shortcode' ] );

		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'init', [ __CLASS__, 'register_rules' ], 20 );
		add_action( 'save_post_page', [ __CLASS__, 'on_save_page' ] );
		add_action( 'template_redirect', [ __CLASS__, 'template_redirect' ] );
		add_filter( 'the_content', [ __CLASS__, 'append_to_page' ], 20 );
		add_filter( 'redirect_canonical', [ __CLASS__, 'redirect_canonical' ] );

		add_filter( 'document_title_parts', [ __CLASS__, 'title_parts' ] );
		add_filter( 'wpseo_title', [ __CLASS__, 'seo_title' ] );
		add_filter( 'wpseo_metadesc', [ __CLASS__, 'seo_description' ] );
		add_filter( 'wpseo_canonical', [ __CLASS__, 'canonical' ] );
		add_filter( 'wpseo_opengraph_url', [ __CLASS__, 'canonical' ] );
		add_filter( 'get_canonical_url', [ __CLASS__, 'canonical' ] );
		add_filter( 'pll_translation_url', [ __CLASS__, 'translation_url' ], 10, 2 );

		// mavo-for-you's "Pour vous" block appends itself to eligible pages;
		// this page is a browse page of its own, not reading material.
		add_filter( 'mavo_for_you_show_block', [ __CLASS__, 'hide_for_you' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ URLs */

	/** The configured page in one language, or 0. */
	public static function page_id( string $lang ): int {
		$page = (int) get_option( self::PAGE_OPTION, 0 );

		if ( ! $page ) {
			return 0;
		}

		if ( function_exists( 'pll_get_post' ) ) {
			$page = (int) pll_get_post( $page, $lang );
		}

		return $page && 'publish' === get_post_status( $page ) ? $page : 0;
	}

	/**
	 * The URL for a concept (or, with '', the index) in a language. '' when no
	 * results page exists in that language.
	 */
	public static function url( string $concept, string $lang, int $page = 1 ): string {
		$page_id = self::page_id( $lang );

		if ( ! $page_id ) {
			return '';
		}

		$base = (string) get_permalink( $page_id );

		if ( '' === $concept ) {
			return $base;
		}

		$slug  = self::slug( $concept, $lang );
		$paths = (array) get_option( self::PATHS_OPTION, [] );

		// Before rules exist for this page, a query string still works.
		if ( ! isset( $paths[ $page_id ] ) ) {
			return add_query_arg( array_filter( [ 'concept' => $slug, 'pg' => $page > 1 ? $page : null ] ), $base );
		}

		return trailingslashit( $base ) . $slug . '/' . ( $page > 1 ? $page . '/' : '' );
	}

	/**
	 * A concept's URL slug in a language: its label, transliterated the German
	 * way (ü → ue) so it never depends on the request's locale.
	 */
	public static function slug( string $concept, string $lang ): string {
		$label = mb_strtolower( MII_Concepts::label( $concept, $lang ), 'UTF-8' );
		$label = strtr( $label, [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae' ] );
		$slug  = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', remove_accents( $label ) ), '-' );

		if ( '' === $slug ) {
			$slug = str_replace( '_', '-', $concept );
		}

		return (string) apply_filters( 'mavo_image_results_slug', $slug, $concept, $lang );
	}

	/** The concept a URL slug names, in its language's words or as the slug itself. */
	public static function concept_from_slug( string $slug, string $lang ): ?string {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return null;
		}

		foreach ( MII_Concepts::slugs() as $concept ) {
			if ( self::slug( $concept, $lang ) === $slug || str_replace( '_', '-', $concept ) === $slug ) {
				return $concept;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------- routing */

	public static function query_vars( array $vars ): array {
		$vars[] = 'mii_concept';
		$vars[] = 'mii_page';

		return $vars;
	}

	/**
	 * On every request: rules from the stored paths, no permalink lookups.
	 * After the setting or one of the pages changed, the paths are recomputed
	 * first and the rules flushed once.
	 */
	public static function register_rules(): void {
		$changed = get_option( self::FLUSH_OPTION ) ? self::refresh_paths() : false;

		foreach ( (array) get_option( self::PATHS_OPTION, [] ) as $page_id => $info ) {
			$query = 'index.php?page_id=' . (int) $page_id . '&mii_concept=$matches[1]&mii_page=$matches[2]';

			if ( ! empty( $info['lang'] ) && function_exists( 'pll_languages_list' ) ) {
				$query .= '&lang=' . rawurlencode( (string) $info['lang'] );
			}

			add_rewrite_rule( '^' . preg_quote( (string) $info['path'], '#' ) . '/([^/]+)(?:/([0-9]+))?/?$', $query, 'top' );
		}

		if ( $changed ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Recompute each translation's permalink path.
	 *
	 * @return bool Whether the paths changed.
	 */
	public static function refresh_paths(): bool {
		delete_option( self::FLUSH_OPTION );

		$paths = [];
		$home  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		foreach ( MII_Lang::languages() as $lang ) {
			$page_id = self::page_id( $lang );

			if ( ! $page_id || isset( $paths[ $page_id ] ) ) {
				continue;
			}

			$path = trim( (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH ), '/' );

			if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) + 1 );
			}

			// A front page has no path to hang concepts under.
			if ( '' !== $path ) {
				$paths[ $page_id ] = [ 'path' => $path, 'lang' => $lang ];
			}
		}

		if ( get_option( self::PATHS_OPTION, [] ) === $paths ) {
			return false;
		}

		update_option( self::PATHS_OPTION, $paths, true );

		return true;
	}

	/** Mark paths for recomputation; done on the next request's init. */
	public static function schedule_refresh(): void {
		update_option( self::FLUSH_OPTION, 1, true );
	}

	public static function on_save_page( $post_id ): void {
		$base = (int) get_option( self::PAGE_OPTION, 0 );

		if ( ! $base ) {
			return;
		}

		$group = [ $base ];

		if ( function_exists( 'pll_get_post' ) ) {
			foreach ( MII_Lang::languages() as $lang ) {
				$group[] = (int) pll_get_post( $base, $lang );
			}
		}

		if ( in_array( (int) $post_id, $group, true ) ) {
			self::schedule_refresh();
		}
	}

	/**
	 * What the current request is showing, or null when it is not the results
	 * page: [ page_id, lang, concept|null, page, unknown_slug ].
	 */
	public static function current(): ?array {
		if ( null !== self::$current ) {
			return self::$current ?: null;
		}

		// Before the main query ran there is nothing to know yet; do not cache that.
		if ( ! did_action( 'wp' ) ) {
			return null;
		}

		self::$current = [];

		if ( ! is_page() ) {
			return null;
		}

		$page_id = (int) get_queried_object_id();
		$lang    = MII_Lang::current();

		if ( ! $page_id || self::page_id( $lang ) !== $page_id ) {
			return null;
		}

		$slug = (string) get_query_var( 'mii_concept' );
		$num  = (int) get_query_var( 'mii_page' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only.
		if ( '' === $slug && isset( $_GET['concept'] ) ) {
			$slug = sanitize_title( wp_unslash( $_GET['concept'] ) );
			$num  = absint( $_GET['pg'] ?? 0 );
		}
		// phpcs:enable

		$concept = '' === $slug ? null : self::concept_from_slug( $slug, $lang );

		return self::$current = [
			'page_id' => $page_id,
			'lang'    => $lang,
			'concept' => $concept,
			'page'    => max( 1, $num ),
			'unknown' => '' !== $slug && null === $concept,
		];
	}

	/** An unknown concept is a 404, not the index under a wrong URL. */
	public static function template_redirect(): void {
		$current = self::current();

		if ( $current && $current['unknown'] ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/** Never let WordPress "correct" a concept URL back to the bare page. */
	public static function redirect_canonical( $url ) {
		return self::current() && ( self::current()['concept'] || self::current()['unknown'] ) ? false : $url;
	}

	/* ---------------------------------------------------------- head / SEO */

	public static function title_parts( array $parts ): array {
		$label = self::current_label();

		if ( null !== $label ) {
			$parts['title'] = $label . ' – ' . ( $parts['title'] ?? '' );
		}

		return $parts;
	}

	public static function seo_title( $title ) {
		$label = self::current_label();

		return null === $label ? $title : $label . ' – ' . $title;
	}

	public static function seo_description( $description ) {
		$label = self::current_label();

		return null === $label ? $description : sprintf( self::text( 'metadesc', self::current()['lang'] ), $label );
	}

	/** Each concept page is its own canonical, never the bare page's. */
	public static function canonical( $url ) {
		$current = self::current();

		if ( ! $current || ! $current['concept'] ) {
			return $url;
		}

		$canonical = self::url( $current['concept'], $current['lang'], $current['page'] );

		return '' !== $canonical ? $canonical : $url;
	}

	/** Polylang's switcher and hreflang: the same concept in the other language. */
	public static function translation_url( $url, $lang ) {
		$current = self::current();

		if ( ! $current || ! $current['concept'] ) {
			return $url;
		}

		$translated = self::url( $current['concept'], (string) $lang );

		return '' !== $translated ? $translated : $url;
	}

	/* ------------------------------------------------------------- render */

	public static function shortcode( $atts ): string {
		$atts    = shortcode_atts( [ 'concept' => '', 'per_page' => self::PER_PAGE ], (array) $atts, self::TAG );
		$current = self::current();
		$lang    = $current['lang'] ?? MII_Lang::current();
		$concept = $current['concept'] ?? null;
		$page    = $current['page'] ?? 1;

		// A page dedicated to one concept: [mavo_image_results concept="garden"].
		if ( ! $concept && '' !== $atts['concept'] && MII_Concepts::exists( MII_Concepts::sanitize_slug( (string) $atts['concept'] ) ) ) {
			$concept = MII_Concepts::sanitize_slug( (string) $atts['concept'] );
		}

		if ( $current && $current['unknown'] ) {
			return '';
		}

		self::enqueue();

		return $concept
			? self::render_concept( $concept, $lang, $page, max( 1, min( 60, (int) $atts['per_page'] ) ) )
			: self::render_index( $lang );
	}

	/** The configured page renders results even without the shortcode in it. */
	public static function append_to_page( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || ! self::current() || has_shortcode( (string) $content, self::TAG ) ) {
			return $content;
		}

		return $content . self::shortcode( [] );
	}

	public static function render_concept( string $concept, string $lang, int $page = 1, int $per_page = self::PER_PAGE ): string {
		// Every tile as a reference, so the count and pagination are exact;
		// only this page's photos are loaded in full.
		$refs  = self::tile_refs( $concept, $lang );
		$total = count( $refs );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, $page ), $pages );
		$tiles = self::hydrate( array_slice( $refs, ( $page - 1 ) * $per_page, $per_page ), $lang );
		$label = MII_Concepts::label( $concept, $lang );

		$out  = '<div class="mavo-image-results mavo-image-results--' . esc_attr( str_replace( '_', '-', $concept ) ) . '">';
		$out .= '<h2 class="mavo-image-results__title">' . esc_html( $label ) . '</h2>';
		$out .= '<p class="mavo-image-results__count">' . esc_html( 1 === $total ? self::text( 'one', $lang ) : sprintf( self::text( 'count', $lang ), $total ) ) . '</p>';

		if ( ! $tiles ) {
			$out .= '<p class="mavo-image-results__empty">' . esc_html( self::text( 'empty', $lang ) ) . '</p>';
		} else {
			// The theme's grid (mv-tiles.css), as its grid-wrapper.php writes it.
			$out .= '<div class="mv-tile-grid mv-grid mv-grid--3 mavo-image-results__grid">';

			foreach ( $tiles as $tile ) {
				$out .= self::render_tile( $tile, $lang );
			}

			$out .= '</div>';
		}

		$out .= self::render_pagination( $concept, $lang, $page, $pages );
		$out .= self::render_related( $concept, $lang );

		$index = self::url( '', $lang );

		if ( '' !== $index ) {
			$out .= '<p class="mavo-image-results__all"><a class="mv-badge mv-badge--warm" href="' . esc_url( $index ) . '">' . esc_html( self::text( 'all', $lang ) ) . '</a></p>';
		}

		return $out . '</div>';
	}

	/**
	 * The bare results page: the curated browse rows, then the site's hero
	 * buttons — nothing else (user's decision, 2026-10-03).
	 *
	 * Rows are the theme's .mv-shelf (mv-tiles.css, arrows from its
	 * js/mv-shelf.js) — the same row as mavo-for-you's /pour-vous/ page, but
	 * server-rendered and the same for every visitor, so this page is cached
	 * and indexed like any other. Each tile is one article, shown with the
	 * photo of it that matches the row, not its featured image.
	 *
	 * The buttons are the home page hero's, owned by the theme and asked for
	 * through mavo_hero_cta_row; without an answer there are simply none.
	 * With no rows configured the page falls back to listing every concept.
	 */
	public static function render_index( string $lang ): string {
		$rows = self::render_rows( $lang );

		if ( '' === $rows ) {
			return self::render_concept_list( $lang );
		}

		/** The home page hero's call-to-action buttons, in a language; '' for none. */
		$cta = trim( (string) apply_filters( 'mavo_hero_cta_row', '', $lang ) );

		return '<div class="mavo-image-results mavo-image-results--browse">' . $rows
			. ( '' !== $cta ? '<div class="mavo-image-results__cta">' . $cta . '</div>' : '' )
			. '</div>';
	}

	/** No "Pour vous" block on the results page, in any language. */
	public static function hide_for_you( $show, $post_id ) {
		foreach ( MII_Lang::languages() as $lang ) {
			if ( (int) $post_id === self::page_id( $lang ) ) {
				return false;
			}
		}

		return $show;
	}

	/** @return string[] The curated row concepts, in order. */
	public static function row_concepts(): array {
		$rows = array_filter( (array) get_option( self::ROWS_OPTION, [] ), [ 'MII_Concepts', 'exists' ] );

		/** The concepts given a row on the results page, in order. */
		return array_values( array_unique( (array) apply_filters( 'mavo_image_results_rows', $rows ) ) );
	}

	/** One slug per line, from the admin textarea; unknown slugs dropped. */
	public static function parse_rows( string $text ): array {
		$out = [];

		foreach ( preg_split( '/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $slug ) {
			$slug = MII_Concepts::sanitize_slug( $slug );

			if ( MII_Concepts::exists( $slug ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	public static function render_rows( string $lang ): string {
		$min   = max( 1, (int) apply_filters( 'mavo_image_results_row_min', self::ROW_MIN ) );
		$out   = '';
		$shown = []; // post IDs already on the page, from rows above.

		// One appearance per article on the whole page (user's decision,
		// 2026-10-04): rows fill top to bottom in their admin order, and each
		// skips what an earlier row already shows — so reordering the rows is
		// how to steer which row keeps a popular article. Concept pages are
		// untouched; they keep every matching photo.
		foreach ( self::row_concepts() as $concept ) {
			$tiles = self::row_tiles( $concept, $lang, self::ROW_TILES, $min, $shown );

			if ( $tiles ) {
				$out  .= self::render_row( $concept, $lang, $tiles );
				$shown = array_merge( $shown, array_column( $tiles, 'post_id' ) );
			}
		}

		if ( '' !== $out && wp_script_is( 'mv-shelf', 'registered' ) ) {
			wp_enqueue_script( 'mv-shelf' );
		}

		return $out;
	}

	/**
	 * A browse row: the most popular articles using a photo of the concept,
	 * each with its best photo (see ranked_articles()).
	 *
	 * Articles in $exclude are left out before $min and $limit apply, so a
	 * row whose articles all appear higher up the page disappears rather
	 * than showing a stub. No photo appears twice in a row.
	 *
	 * @param int[] $exclude Post IDs already shown elsewhere on the page.
	 * @return array<int,array{image:array,post_id:int}> [] when fewer than $min articles match
	 */
	public static function row_tiles( string $concept, string $lang, int $limit = self::ROW_TILES, int $min = 1, array $exclude = [] ): array {
		$articles = self::ranked_articles( $concept, $lang, $exclude );

		if ( count( $articles ) < max( 1, $min ) ) {
			return [];
		}

		$refs = [];
		$used = [];

		// One photo per article, and no photo twice in a row: an article whose
		// photos are all taken (one picture used in two articles) is skipped.
		foreach ( $articles as $post_id => $article ) {
			foreach ( $article['photos'] as $id ) {
				if ( ! isset( $used[ $id ] ) ) {
					$used[ $id ] = true;
					$refs[]      = [ 'attachment_id' => $id, 'post_id' => $post_id ];
					break;
				}
			}

			if ( count( $refs ) >= $limit ) {
				break;
			}
		}

		if ( count( $refs ) < max( 1, $min ) ) {
			return [];
		}

		return self::hydrate( $refs, $lang );
	}

	/**
	 * Every article in $lang using a photo of the concept, most popular first.
	 *
	 * One grouped query over the index — not the first page of an image
	 * search, which ranks photos and would miss a popular article whose
	 * matching photo ranks low. Articles are ordered by MII_Popularity (views
	 * in the same month last year, summed over the translation group; then
	 * the rolling 90-day views; then the newest photo).
	 *
	 * An article's photos are those that are not its featured image, strongest
	 * match then newest first. An article whose only match is its featured
	 * image keeps that one, flagged as a fallback, and all such articles come
	 * after the others: the featured image is what every other tile on the
	 * site already shows (measured on the live turquoise page: 11 of the first
	 * 24 tiles had been featured images).
	 *
	 * @param int[] $exclude Post IDs to leave out.
	 * @return array<int,array{photos:int[],fallback:bool}> post_id => article, in order
	 */
	public static function ranked_articles( string $concept, string $lang, array $exclude = [] ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT u.post_id, u.attachment_id,
			        MAX(CASE WHEN u.role = \'featured\' THEN 1 ELSE 0 END) AS featured,
			        MAX(c.confidence) AS confidence
			   FROM ' . MII_DB::usage() . ' u
			   JOIN ' . MII_DB::concepts() . ' c ON c.attachment_id = u.attachment_id AND c.concept = %s
			   JOIN ' . MII_DB::items() . ' i ON i.attachment_id = u.attachment_id
			  WHERE u.lang = %s
			  GROUP BY u.post_id, u.attachment_id',
			$concept,
			$lang
		), ARRAY_A );

		/** Whether an article's featured image may stand in when it has no other match. */
		$fallback = (bool) apply_filters( 'mavo_image_results_featured_fallback', true, $concept, $lang );
		$exclude  = array_flip( array_map( 'intval', $exclude ) );
		$photos   = []; // post_id => [ 'inline' => [ [conf, id], … ], 'featured' => [ … ] ]

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];

			if ( ! isset( $exclude[ $post_id ] ) ) {
				$photos[ $post_id ][ (int) $row['featured'] ? 'featured' : 'inline' ][] = [ (float) $row['confidence'], (int) $row['attachment_id'] ];
			}
		}

		$articles = [];

		foreach ( $photos as $post_id => $kinds ) {
			$is_fallback = empty( $kinds['inline'] );
			$list        = $is_fallback ? ( $kinds['featured'] ?? [] ) : $kinds['inline'];

			if ( ! $list || ( $is_fallback && ! $fallback ) ) {
				continue;
			}

			// Strongest match first, then the newer upload.
			rsort( $list );

			$articles[ $post_id ] = [ 'photos' => array_column( $list, 1 ), 'fallback' => $is_fallback ];
		}

		$pop = MII_Popularity::for_posts( array_keys( $articles ) );

		uksort( $articles, static function ( $a, $b ) use ( $articles, $pop ) {
			return [ (int) $articles[ $a ]['fallback'], -( $pop[ $a ]['season'] ?? 0 ), -( $pop[ $a ]['recent'] ?? 0 ), -$articles[ $a ]['photos'][0] ]
				<=> [ (int) $articles[ $b ]['fallback'], -( $pop[ $b ]['season'] ?? 0 ), -( $pop[ $b ]['recent'] ?? 0 ), -$articles[ $b ]['photos'][0] ];
		} );

		return $articles;
	}

	private static function render_row( string $concept, string $lang, array $tiles ): string {
		$id    = 'mavo-image-row-' . str_replace( '_', '-', $concept );
		$label = MII_Concepts::label( $concept, $lang );
		$more  = self::url( $concept, $lang );

		$html = '<section class="mv-shelf mavo-image-results__row" aria-labelledby="' . esc_attr( $id ) . '"'
			. ' data-mv-shelf-prev="' . esc_attr( self::text( 'prev_row', $lang ) ) . '"'
			. ' data-mv-shelf-next="' . esc_attr( self::text( 'next_row', $lang ) ) . '">'
			. '<div class="mv-shelf__head"><h2 class="mv-shelf__title" id="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</h2>';

		if ( '' !== $more ) {
			$html .= '<a class="mv-shelf__more" href="' . esc_url( $more ) . '">' . esc_html( self::text( 'more', $lang ) ) . '</a>';
		}

		$html .= '</div><div class="mv-shelf__viewport"><ul class="mv-shelf__track" tabindex="0" aria-label="' . esc_attr( $label ) . '">';

		foreach ( $tiles as $tile ) {
			$card = self::render_tile( $tile, $lang );

			if ( '' !== $card ) {
				$html .= '<li class="mv-shelf__slide">' . $card . '</li>';
			}
		}

		return $html . '</ul></div></section>';
	}

	/** Every concept with photos, as the theme's compact text tiles. */
	public static function render_concept_list( string $lang ): string {
		$counts = MII_Search::concept_counts( $lang );
		$tiles  = '';

		// Dictionary order, which groups landscape, places, activities….
		foreach ( MII_Concepts::all() as $slug => $def ) {
			if ( empty( $counts[ $slug ] ) ) {
				continue;
			}

			$n = (int) $counts[ $slug ];

			$tiles .= '<div class="mv-tile mv-tile--text mv-tile--compact mavo-image-results__concept">'
				. '<span class="mv-tile__title"><a class="mv-tile__link" href="' . esc_url( self::url( $slug, $lang ) ) . '">'
				. esc_html( MII_Concepts::label( $slug, $lang ) ) . '</a></span>'
				. '<span class="mv-tile__description"><span class="mv-tile__count">' . $n . '</span> '
				. esc_html( self::text( 1 === $n ? 'unit_one' : 'unit', $lang ) )
				. '</span></div>';
		}

		if ( '' === $tiles ) {
			return '<p class="mavo-image-results__empty">' . esc_html( self::text( 'empty', $lang ) ) . '</p>';
		}

		return '<div class="mavo-image-results mavo-image-results--index">'
			. '<div class="mv-tile-grid mv-tile-grid--compact">' . $tiles . '</div>'
			. '</div>';
	}

	/**
	 * The concept grid's tiles, as references: up to $per_article photos per
	 * article, in the same popularity order as the browse rows.
	 *
	 * Dealt in rounds — every article's best photo first, then every second
	 * photo — so one article's photos are not side by side and the first
	 * pages show as many different articles as possible. A photo used in two
	 * articles appears once, under the more popular one.
	 *
	 * @return array<int,array{attachment_id:int,post_id:int}>
	 */
	public static function tile_refs( string $concept, string $lang, int $per_article = self::PER_ARTICLE ): array {
		$articles = self::ranked_articles( $concept, $lang );
		$refs     = [];
		$used     = []; // attachment_id => post_id it is shown under

		for ( $round = 0; $round < max( 1, $per_article ); $round++ ) {
			foreach ( $articles as $post_id => $article ) {
				$mine = array_values( array_filter(
					$article['photos'],
					static fn( $id ) => ! isset( $used[ $id ] ) || $used[ $id ] === $post_id
				) );
				$id   = $mine[ $round ] ?? null;

				if ( null === $id || isset( $used[ $id ] ) ) {
					continue;
				}

				$used[ $id ] = $post_id;
				$refs[]      = [ 'attachment_id' => $id, 'post_id' => $post_id ];
			}
		}

		return $refs;
	}

	/** The concept grid's tiles, hydrated. */
	public static function tiles( string $concept, string $lang, int $per_article = self::PER_ARTICLE ): array {
		return self::hydrate( self::tile_refs( $concept, $lang, $per_article ), $lang );
	}

	/**
	 * References to tiles: image objects in one bulk load, posts primed.
	 *
	 * @param array<int,array{attachment_id:int,post_id:int}> $refs
	 * @return array<int,array{image:array,post_id:int}>
	 */
	private static function hydrate( array $refs, string $lang ): array {
		if ( ! $refs ) {
			return [];
		}

		$images = MII_Images::get_many( array_column( $refs, 'attachment_id' ), [ 'lang' => $lang ] );
		$tiles  = [];

		_prime_post_caches( array_values( array_unique( array_column( $refs, 'post_id' ) ) ), false, false );

		foreach ( $refs as $ref ) {
			if ( isset( $images[ $ref['attachment_id'] ] ) ) {
				$tiles[] = [ 'image' => $images[ $ref['attachment_id'] ], 'post_id' => $ref['post_id'] ];
			}
		}

		return $tiles;
	}

	/* ------------------------------------------------------------- private */

	/**
	 * A tile's eyebrow: "Country, Region" of the article's place, in the
	 * article's language — the city a post is tagged with is too specific to
	 * label a photo with. Either part alone when only one is known.
	 *
	 * Filterable, so a later location plugin can choose the level per place
	 * (London; GB-South; Denmark) without this plugin changing.
	 */
	private static function eyebrow( array $image, int $post_id, string $lang ): string {
		$context = null;

		foreach ( $image['geo']['candidates'] ?? [] as $candidate ) {
			if ( (int) $candidate['post_id'] === $post_id ) {
				$context = $candidate;
				break;
			}
		}

		$parts = array_filter( [
			(string) ( $context['country']['name'] ?? '' ),
			(string) ( $context['region']['name'] ?? '' ),
		], 'strlen' );

		/** The eyebrow over a results tile: '' for none. */
		return (string) apply_filters( 'mavo_image_tile_eyebrow', implode( ', ', array_unique( $parts ) ), $post_id, $lang, $context, $image );
	}

	/**
	 * One photo as the theme's media tile — the markup of the theme's
	 * card-post.php and of mavo-for-you's cards: the whole tile is one link
	 * (the stretched .mv-tile__link), photo above, text below.
	 *
	 * The alt text is shown as the description, so the <img> itself takes
	 * alt="" as in those tiles: a screen reader would otherwise hear it twice.
	 */
	private static function render_tile( array $tile, string $lang ): string {
		$image   = $tile['image'];
		$post_id = $tile['post_id'];
		$src     = (string) wp_get_attachment_image_url( $image['attachment_id'], 'medium_large' );

		if ( '' === $src ) {
			return '';
		}

		$place = self::eyebrow( $image, $post_id, $lang );

		$html = '<div class="mv-tile mv-tile--media mavo-image-results__tile">'
			. '<span class="mv-tile__media"><img class="mv-tile__img" src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async"></span>'
			. '<span class="mv-tile__body">';

		if ( '' !== $place ) {
			$html .= '<span class="mv-tile__eyebrow">' . esc_html( $place ) . '</span>';
		}

		$html .= '<span class="mv-tile__title"><a class="mv-tile__link" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">'
			. esc_html( wp_strip_all_tags( (string) get_the_title( $post_id ) ) ) . '</a></span>';

		if ( '' !== (string) $image['alt_text'] ) {
			$html .= '<span class="mv-tile__description">' . esc_html( (string) $image['alt_text'] ) . '</span>';
		}

		return $html . '</span></div>';
	}

	private static function render_pagination( string $concept, string $lang, int $page, int $pages ): string {
		if ( $pages < 2 ) {
			return '';
		}

		$out = '<nav class="mavo-image-results__pages">';

		if ( $page > 1 ) {
			$out .= '<a class="mavo-image-results__prev" href="' . esc_url( self::url( $concept, $lang, $page - 1 ) ) . '">' . esc_html( self::text( 'prev', $lang ) ) . '</a> ';
		}

		$out .= '<span>' . esc_html( sprintf( self::text( 'page', $lang ), $page, $pages ) ) . '</span>';

		if ( $page < $pages ) {
			$out .= ' <a class="mavo-image-results__next" href="' . esc_url( self::url( $concept, $lang, $page + 1 ) ) . '">' . esc_html( self::text( 'next', $lang ) ) . '</a>';
		}

		return $out . '</nav>';
	}

	/** Other concepts of the same group that have images in this language. */
	private static function render_related( string $concept, string $lang ): string {
		$group  = MII_Concepts::get( $concept )['group'] ?? '';
		$counts = MII_Search::concept_counts( $lang );
		$links  = [];

		foreach ( MII_Concepts::all() as $slug => $def ) {
			if ( $slug !== $concept && $def['group'] === $group && ! empty( $counts[ $slug ] ) ) {
				$links[] = '<a class="mv-badge mv-badge--neutral" href="' . esc_url( self::url( $slug, $lang ) ) . '">' . esc_html( MII_Concepts::label( $slug, $lang ) ) . '</a>';
			}
		}

		if ( ! $links ) {
			return '';
		}

		return '<p class="mavo-image-results__related"><span class="mavo-image-results__label">' . esc_html( self::text( 'related', $lang ) ) . '</span> ' . implode( ' ', $links ) . '</p>';
	}

	private static function current_label(): ?string {
		$current = self::current();

		return $current && $current['concept'] ? MII_Concepts::label( $current['concept'], $current['lang'] ) : null;
	}

	private static function text( string $key, string $lang ): string {
		return self::TEXT[ $key ][ $lang ] ?? self::TEXT[ $key ][ MII_Lang::FALLBACK ];
	}

	private static function enqueue(): void {
		wp_enqueue_style( 'mavo-image-results', MII_PLUGIN_URL . 'assets/results.css', [], MII_VERSION );
	}

	/** For tests. */
	public static function reset(): void {
		self::$current = null;
	}
}
