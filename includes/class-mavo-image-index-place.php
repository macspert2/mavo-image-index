<?php
/**
 * "Madère en images": a place's distinctive photo topics, as one compact row.
 *
 * On a place's tag archive (/tag/madere/) — inserted by this plugin through
 * GeneratePress's generate_before_loop, since tag archives do not run
 * shortcodes — and anywhere else through [mavo_image_place place="madere"].
 * Four overlay tiles (the theme's .mv-tile--overlay), one per topic: the
 * topic's best photo in that place, its name, its photo count, linking to
 * the topic × place results page (/images/montagnes/madere/).
 *
 * Which topics: what is *distinctive* here, not what is frequent. Counted
 * plainly every place is "Famille · Maisons · Mer". Each concept's share of
 * the place's photos is set against its share of the whole language's
 * photos (the lift); topics need MIN_LIFT and MIN_PHOTOS, rank by
 * log(lift) × √photos, and no more than MAX_PER_GROUP come from one group
 * (landscape, places, visual…), so the four say different things.
 * Private concepts (family, children) never qualify.
 *
 * Which photos count: those used in articles whose OWN place — the most
 * specific one, stored per usage row — lies inside this place. Not every
 * article *tagged* with it: a "ten best islands" round-up tagged Madère and
 * Crete would otherwise bring Cretan beaches into Madère's gallery.
 *
 * When there is no gallery — decided by the data, not by level:
 *   - the place holds more than MAX_SHARE of the language's photos (a
 *     continent, a big country): skipped before the topic query runs, since
 *     nothing about most of the blog is distinctive;
 *   - fewer than MIN_TOPICS topics clear the bar (a small or generic place);
 *   - no results page in this language, so the tiles would lead nowhere.
 */

defined( 'ABSPATH' ) || exit;

class MII_Place {

	const TAG           = 'mavo_image_place';
	const TOPICS        = 4;
	const MIN_TOPICS    = 3;
	const MIN_PHOTOS    = 4;
	const MIN_LIFT      = 1.3;
	const MAX_SHARE     = 0.25;
	const MAX_PER_GROUP = 2;

	const TEXT = [
		'heading' => [ 'fr' => '%s en images', 'en' => '%s in pictures', 'de' => '%s in Bildern' ],
		'photos'  => [ 'fr' => '%d photos', 'en' => '%d photos', 'de' => '%d Fotos' ],
	];

	private static bool $printed = false;

	public static function init(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'shortcode' ] );
		// Switched off at the user's request (2026-10-10): the gallery row is
		// not to appear on tag archives for now. Everything else is intact —
		// auto_insert(), the topic ranking, the [mavo_image_place] shortcode
		// and the topic × place pages the tiles link to — so turning it back
		// on is uncommenting this line.
		// add_action( 'generate_before_loop', [ __CLASS__, 'auto_insert' ] );
	}

	/**
	 * A place and every place inside it, as post_tag term IDs in the term's
	 * own language (mavo-geotag-plus). Without that plugin, the term alone.
	 *
	 * @return int[]
	 */
	public static function terms( int $term_id ): array {
		$terms = function_exists( 'mavo_geo_subtree_terms' ) ? (array) mavo_geo_subtree_terms( $term_id ) : [];

		return array_values( array_unique( array_merge( [ $term_id ], array_map( 'intval', $terms ) ) ) );
	}

	/**
	 * The place's distinctive topics, strongest first; [] for no gallery.
	 *
	 * @return array<int,array{concept:string,label:string,photos:int,lift:float,attachment_id:int,post_id:int}>
	 */
	public static function topics( int $term_id, string $lang ): array {
		$key    = 'place:' . $term_id . ':' . $lang;
		$cached = MII_Cache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$topics = self::compute( $term_id, $lang );

		/** A place's gallery topics, after ranking; [] hides the gallery. */
		$topics = array_values( (array) apply_filters( 'mavo_image_place_topics', $topics, $term_id, $lang ) );

		MII_Cache::set( $key, $topics );

		return $topics;
	}

	public static function render( int $term_id, string $lang ): string {
		$term = get_term( $term_id, 'post_tag' );

		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		$topics = self::topics( $term_id, $lang );

		if ( ! $topics ) {
			return '';
		}

		$images = MII_Images::get_many( array_column( $topics, 'attachment_id' ), [ 'lang' => $lang ] );
		$tiles  = '';

		foreach ( $topics as $topic ) {
			$url = MII_Results::url( $topic['concept'], $lang, 1, $term_id );
			$src = (string) wp_get_attachment_image_url( $topic['attachment_id'], 'medium_large' );

			if ( '' === $url || '' === $src ) {
				continue;
			}

			$alt = (string) ( $images[ $topic['attachment_id'] ]['alt_text'] ?? '' );

			$tiles .= '<div class="mv-tile mv-tile--overlay mavo-image-place__tile">'
				. '<span class="mv-tile__media"><img class="mv-tile__img" src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async"></span>'
				. '<span class="mv-tile__body">'
				. '<span class="mv-tile__title"><a class="mv-tile__link" href="' . esc_url( $url ) . '">' . esc_html( $topic['label'] ) . '</a></span>'
				. '<span class="mv-tile__description">' . esc_html( sprintf( self::text( 'photos', $lang ), $topic['photos'] ) ) . '</span>'
				. '</span></div>';
		}

		if ( '' === $tiles ) {
			return '';
		}

		wp_enqueue_style( 'mavo-image-results', MII_PLUGIN_URL . 'assets/results.css', [], MII_VERSION );

		$id = 'mavo-image-place-' . $term_id;

		return '<section class="mavo-image-place" aria-labelledby="' . esc_attr( $id ) . '">'
			. '<h2 class="mavo-image-place__title" id="' . esc_attr( $id ) . '">' . esc_html( sprintf( self::text( 'heading', $lang ), $term->name ) ) . '</h2>'
			. '<div class="mavo-image-place__grid">' . $tiles . '</div>'
			. '</section>';
	}

	/** [mavo_image_place place="madere"] — slug or term ID; on a tag archive, that tag. */
	public static function shortcode( $atts ): string {
		$atts  = shortcode_atts( [ 'place' => '', 'lang' => '' ], (array) $atts, self::TAG );
		$place = trim( (string) $atts['place'] );
		$term  = null;

		if ( '' === $place ) {
			$term = is_tag() ? get_queried_object() : null;
		} elseif ( ctype_digit( $place ) ) {
			$term = get_term( (int) $place, 'post_tag' );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $place ), 'post_tag' );
		}

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		self::$printed = true;

		return self::render( (int) $term->term_id, MII_Lang::resolve( (string) $atts['lang'] ) );
	}

	/** On a tag archive, under the title: the gallery, once. */
	public static function auto_insert( $context = '' ): void {
		if ( 'archive' !== $context || self::$printed || ! is_tag() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof WP_Term || is_paged() ) {
			return;
		}

		/** Whether tag archives get the gallery automatically. */
		if ( ! apply_filters( 'mavo_image_place_auto', true, (int) $term->term_id ) ) {
			return;
		}

		self::$printed = true;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in render().
		echo self::render( (int) $term->term_id, MII_Lang::current() );
	}

	/** For tests. */
	public static function reset(): void {
		self::$printed = false;
	}

	/* -------------------------------------------------------------- private */

	private static function compute( int $term_id, string $lang ): array {
		global $wpdb;

		if ( '' === MII_Results::url( '', $lang ) ) {
			return [];
		}

		$places = self::terms( $term_id );
		$usage  = MII_DB::usage();
		$items  = MII_DB::items();

		$site_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT u.attachment_id) FROM $usage u JOIN $items i ON i.attachment_id = u.attachment_id WHERE u.lang = %s",
			$lang
		) );

		$place_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT u.attachment_id) FROM $usage u JOIN $items i ON i.attachment_id = u.attachment_id
			  WHERE u.lang = %s AND u.geo_place IN (" . MII_DB::in_ints( $places ) . ')',
			$lang
		) );

		$max_share = (float) apply_filters( 'mavo_image_place_max_share', self::MAX_SHARE, $term_id, $lang );

		// Too small to have topics, or too big to have distinctive ones.
		if ( ! $site_total || $place_total < self::MIN_PHOTOS * self::MIN_TOPICS || $place_total / $site_total > $max_share ) {
			return [];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.concept, COUNT(DISTINCT u.attachment_id) AS n
			   FROM $usage u
			   JOIN " . MII_DB::concepts() . " c ON c.attachment_id = u.attachment_id
			   JOIN $items i ON i.attachment_id = u.attachment_id
			  WHERE u.lang = %s AND u.geo_place IN (" . MII_DB::in_ints( $places ) . ')
			  GROUP BY c.concept',
			$lang
		), ARRAY_A );

		$site       = MII_Search::concept_counts( $lang );
		$candidates = [];

		foreach ( (array) $rows as $row ) {
			$concept = (string) $row['concept'];
			$n       = (int) $row['n'];

			// Private concepts (family, children) are never a topic.
			if ( $n < self::MIN_PHOTOS || ! MII_Concepts::is_public( $concept ) || empty( $site[ $concept ] ) ) {
				continue;
			}

			$lift = ( $n / $place_total ) / ( $site[ $concept ] / $site_total );

			if ( $lift >= self::MIN_LIFT ) {
				$candidates[ $concept ] = [ 'n' => $n, 'lift' => $lift, 'score' => log( $lift ) * sqrt( $n ) ];
			}
		}

		uasort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		$topics = [];
		$groups = [];
		$used   = [];

		foreach ( $candidates as $concept => $c ) {
			$group = MII_Concepts::get( $concept )['group'] ?? '';

			if ( ( $groups[ $group ] ?? 0 ) >= self::MAX_PER_GROUP ) {
				continue;
			}

			// Exactly what the topic × place page will show, so the count on
			// this tile is the count there; the face is its first article's
			// main photo, unless another topic already uses that photo.
			$grid   = MII_Results::grid_articles( $concept, $lang, $places );
			$photo  = null;
			$count  = 0;

			foreach ( $grid as $article ) {
				$count += count( $article['photos'] );

				foreach ( $photo ? [] : $article['photos'] as $id ) {
					if ( ! isset( $used[ $id ] ) ) {
						$photo = [ $id, $article['post_id'] ];
						break;
					}
				}
			}

			if ( ! $photo ) {
				continue;
			}

			$used[ $photo[0] ] = true;
			$groups[ $group ]  = ( $groups[ $group ] ?? 0 ) + 1;
			$topics[]          = [
				'concept'       => $concept,
				'label'         => MII_Concepts::label( $concept, $lang ),
				'photos'        => $count,
				'lift'          => round( $c['lift'], 2 ),
				'attachment_id' => $photo[0],
				'post_id'       => $photo[1],
			];

			if ( count( $topics ) >= self::TOPICS ) {
				break;
			}
		}

		return count( $topics ) >= self::MIN_TOPICS ? $topics : [];
	}

	private static function text( string $key, string $lang ): string {
		return self::TEXT[ $key ][ $lang ] ?? self::TEXT[ $key ][ MII_Lang::FALLBACK ];
	}
}
