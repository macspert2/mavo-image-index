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

	const PER_PAGE     = 24;
	const PER_ARTICLE  = 2;
	const POOL         = 100;

	/** Visitor-facing text, per language (the project's convention, not gettext). */
	const TEXT = [
		'count'    => [ 'fr' => '%d photos', 'en' => '%d photos', 'de' => '%d Fotos' ],
		'one'      => [ 'fr' => '1 photo', 'en' => '1 photo', 'de' => '1 Foto' ],
		'unit'     => [ 'fr' => 'photos', 'en' => 'photos', 'de' => 'Fotos' ],
		'unit_one' => [ 'fr' => 'photo', 'en' => 'photo', 'de' => 'Foto' ],
		'from'     => [ 'fr' => 'Article :', 'en' => 'From:', 'de' => 'Artikel:' ],
		'all'      => [ 'fr' => 'Toutes les thématiques', 'en' => 'All themes', 'de' => 'Alle Themen' ],
		'related'  => [ 'fr' => 'Voir aussi', 'en' => 'See also', 'de' => 'Siehe auch' ],
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
		$tiles = self::tiles( $concept, $lang );
		$total = count( $tiles );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, $page ), $pages );
		$tiles = array_slice( $tiles, ( $page - 1 ) * $per_page, $per_page );
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

	/** Every concept with photos, as the theme's compact text tiles. */
	public static function render_index( string $lang ): string {
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
	 * Images for a concept, each paired with the article to link it to, at
	 * most PER_ARTICLE per article — so one article with fifteen turquoise
	 * coves does not fill the page.
	 *
	 * @return array<int,array{image:array,post_id:int}>
	 */
	public static function tiles( string $concept, string $lang ): array {
		$results  = mavo_image_search( [ 'lang' => $lang, 'concepts' => [ $concept ], 'limit' => self::POOL ] );
		$per_post = [];
		$tiles    = [];

		foreach ( $results as $image ) {
			$post_id = self::article_for( $image, $lang );

			if ( ! $post_id || ( $per_post[ $post_id ] ?? 0 ) >= self::PER_ARTICLE ) {
				continue;
			}

			$per_post[ $post_id ] = ( $per_post[ $post_id ] ?? 0 ) + 1;
			$tiles[]              = [ 'image' => $image, 'post_id' => $post_id ];
		}

		_prime_post_caches( array_keys( $per_post ), false, false );

		return $tiles;
	}

	/* ------------------------------------------------------------- private */

	/** The article an image is shown in: in this language, content before featured. */
	private static function article_for( array $image, string $lang ): int {
		$best = 0;

		foreach ( $image['usages'] as $usage ) {
			if ( $usage['lang'] !== $lang ) {
				continue;
			}

			if ( 'content' === $usage['role'] ) {
				return (int) $usage['post_id'];
			}

			$best = $best ?: (int) $usage['post_id'];
		}

		return $best;
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

		$place = '';
		foreach ( $image['geo']['candidates'] ?? [] as $context ) {
			if ( (int) $context['post_id'] === $post_id && ! empty( $context['place']['name'] ) ) {
				$place = (string) $context['place']['name'];
				break;
			}
		}

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
