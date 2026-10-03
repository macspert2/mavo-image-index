<?php
/**
 * Semantic image search over the index.
 *
 * Two stages, so the expensive part never sees more than a few hundred rows:
 *
 *   1. SQL filters on indexed columns (concepts, dimensions, usage language,
 *      place, hub, role) and orders by concept evidence. For relevance it
 *      returns a bounded candidate pool; for date, id and random it returns
 *      the page directly.
 *   2. For relevance only, PHP adds the usage-dependent bonuses (featured,
 *      role, geo precision) to the pool in one more query, sorts and slices.
 *
 * Concepts match on evidence from ANY alt language: a concept is a fact about
 * the picture, and an image with only French alt is still a beach in German.
 * Language governs which posts count (same_language) and which alt text and
 * labels come back.
 */

defined( 'ABSPATH' ) || exit;

class MII_Search {

	const MAX_LIMIT = 100;
	const POOL_MIN  = 60;
	const POOL_MAX  = 600;

	/** Larger than any attachment ID this site will reach. */
	const SHUFFLE_PRIME = 2147483647;

	const DEFAULTS = [
		'lang'                      => null,
		'concepts'                  => [],
		'concept_operator'          => 'AND',
		'exclude_concepts'          => [],
		'text'                      => null,
		'place'                     => null,
		'include_place_descendants' => true,
		'hub'                       => null,
		'include_hub_descendants'   => false,
		'post_ids'                  => [],
		'exclude'                   => [],
		'same_language'             => true,
		'orientation'               => null,
		'min_width'                 => null,
		'min_height'                => null,
		'featured'                  => null,
		'role'                      => null,
		'prefer_featured'           => false,
		'after'                     => null,
		'before'                    => null,
		'limit'                     => 20,
		'offset'                    => 0,
		'orderby'                   => 'relevance',
		'order'                     => 'DESC',
		'seed'                      => null,
		'hubs'                      => false,
	];

	/** @return array[] Image objects, each with 'score' and 'matched_concepts'. */
	public static function run( array $args ): array {
		$args = self::normalize( $args );

		/** Search arguments, after defaults and sanitizing. */
		$args = (array) apply_filters( 'mavo_image_search_args', $args );

		$key    = 'search:' . md5( serialize( $args ) );
		$cached = MII_Cache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$results = self::execute( $args );

		/** Final results, already hydrated and scored. */
		$results = array_values( (array) apply_filters( 'mavo_image_search_results', $results, $args ) );

		MII_Cache::set( $key, $results );

		return $results;
	}

	/**
	 * Images per concept among images used in posts of one language:
	 * [ concept => count ]. One grouped query, cached.
	 */
	public static function concept_counts( string $lang ): array {
		global $wpdb;

		$key    = 'counts:' . $lang;
		$cached = MII_Cache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT c.concept, COUNT(DISTINCT c.attachment_id) AS n
			   FROM ' . MII_DB::concepts() . ' c
			  WHERE c.attachment_id IN ( SELECT attachment_id FROM ' . MII_DB::usage() . ' WHERE lang = %s )
			  GROUP BY c.concept',
			$lang
		), ARRAY_A );

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['concept'] ] = (int) $row['n'];
		}

		MII_Cache::set( $key, $out );

		return $out;
	}

	public static function normalize( array $args ): array {
		$args = array_merge( self::DEFAULTS, array_intersect_key( $args, self::DEFAULTS ) );

		$args['lang']             = MII_Lang::resolve( is_string( $args['lang'] ) ? $args['lang'] : null );
		$args['concepts']         = self::slugs( $args['concepts'] );
		$args['exclude_concepts'] = self::slugs( $args['exclude_concepts'] );
		$args['concept_operator'] = 'OR' === strtoupper( (string) $args['concept_operator'] ) ? 'OR' : 'AND';
		$args['text']             = is_string( $args['text'] ) && '' !== trim( $args['text'] ) ? MII_Matcher::normalize( $args['text'] ) : null;
		$args['place']            = $args['place'] ? absint( $args['place'] ) : null;
		$args['hub']              = $args['hub'] ? absint( $args['hub'] ) : null;
		$args['post_ids']         = array_values( array_filter( array_map( 'absint', (array) $args['post_ids'] ) ) );
		$args['exclude']          = array_values( array_filter( array_map( 'absint', (array) $args['exclude'] ) ) );
		$args['orientation']      = in_array( $args['orientation'], [ 'landscape', 'portrait', 'square' ], true ) ? $args['orientation'] : null;
		$args['min_width']        = $args['min_width'] ? absint( $args['min_width'] ) : null;
		$args['min_height']       = $args['min_height'] ? absint( $args['min_height'] ) : null;
		$args['featured']         = null === $args['featured'] ? null : (bool) $args['featured'];
		$args['role']             = in_array( $args['role'], MII_Usage::ROLES, true ) ? $args['role'] : null;
		$args['after']            = self::date( $args['after'] );
		$args['before']           = self::date( $args['before'] );
		$args['limit']            = max( 1, min( self::MAX_LIMIT, (int) $args['limit'] ) );
		$args['offset']           = max( 0, (int) $args['offset'] );
		$args['orderby']          = in_array( $args['orderby'], [ 'relevance', 'date', 'random', 'id' ], true ) ? $args['orderby'] : 'relevance';
		$args['order']            = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$args['seed']             = null === $args['seed'] ? (int) gmdate( 'Ymd' ) : (int) $args['seed'];
		$args['same_language']    = (bool) $args['same_language'];
		$args['prefer_featured']  = (bool) $args['prefer_featured'];
		$args['include_place_descendants'] = (bool) $args['include_place_descendants'];
		$args['include_hub_descendants']   = (bool) $args['include_hub_descendants'];
		$args['hubs']             = (bool) $args['hubs'];

		return $args;
	}

	/* -------------------------------------------------------------- private */

	private static function execute( array $args ): array {
		global $wpdb;

		$usage_where = self::usage_conditions( $args );

		if ( false === $usage_where ) {
			return []; // A filter that cannot match anything, e.g. a hub with no members.
		}

		$items     = MII_DB::items();
		$concepts  = MII_DB::concepts();
		$semantics = MII_DB::semantics();
		$usage     = MII_DB::usage();

		$join   = '';
		$where  = [ '1=1' ];
		$select = 'i.attachment_id, i.attachment_date, i.width, 0 AS concept_score, 0 AS matched_count, \'\' AS matched';

		if ( $args['concepts'] ) {
			$having = 'AND' === $args['concept_operator']
				? 'HAVING COUNT(*) = ' . count( $args['concepts'] )
				: '';

			// Strongest evidence per concept first, then summed per image.
			$join  .= " JOIN (
				SELECT attachment_id, SUM(conf) AS score, COUNT(*) AS n, GROUP_CONCAT(concept) AS matched
				  FROM ( SELECT attachment_id, concept, MAX(confidence) AS conf
				           FROM $concepts
				          WHERE concept IN (" . MII_DB::in_strings( $args['concepts'] ) . ")
				          GROUP BY attachment_id, concept ) best
				 GROUP BY attachment_id
				 $having
			) cs ON cs.attachment_id = i.attachment_id";
			$select = 'i.attachment_id, i.attachment_date, i.width, cs.score AS concept_score, cs.n AS matched_count, cs.matched';
		}

		if ( $args['exclude_concepts'] ) {
			$where[] = "i.attachment_id NOT IN ( SELECT attachment_id FROM $concepts WHERE concept IN (" . MII_DB::in_strings( $args['exclude_concepts'] ) . ') )';
		}

		if ( null !== $args['text'] ) {
			$where[] = $wpdb->prepare(
				"i.attachment_id IN ( SELECT attachment_id FROM $semantics WHERE lang = %s AND alt_norm LIKE %s )",
				$args['lang'],
				'% ' . $wpdb->esc_like( $args['text'] ) . ' %'
			);
		}

		if ( $usage_where ) {
			$where[] = "i.attachment_id IN ( SELECT u.attachment_id FROM $usage u WHERE " . implode( ' AND ', $usage_where ) . ' )';
		}

		if ( false === $args['featured'] ) {
			$scope   = $args['same_language'] ? $wpdb->prepare( ' AND lang = %s', $args['lang'] ) : '';
			$where[] = "i.attachment_id NOT IN ( SELECT attachment_id FROM $usage WHERE role = 'featured'$scope )";
		}

		if ( $args['orientation'] ) {
			$where[] = $wpdb->prepare( 'i.orientation = %s', $args['orientation'] );
		}
		if ( $args['min_width'] ) {
			$where[] = $wpdb->prepare( 'i.width >= %d', $args['min_width'] );
		}
		if ( $args['min_height'] ) {
			$where[] = $wpdb->prepare( 'i.height >= %d', $args['min_height'] );
		}
		if ( $args['after'] ) {
			$where[] = $wpdb->prepare( 'i.attachment_date >= %s', $args['after'] );
		}
		if ( $args['before'] ) {
			$where[] = $wpdb->prepare( 'i.attachment_date <= %s', $args['before'] );
		}
		if ( $args['exclude'] ) {
			$where[] = 'i.attachment_id NOT IN (' . MII_DB::in_ints( $args['exclude'] ) . ')';
		}

		$dir = $args['order'];

		switch ( $args['orderby'] ) {
			case 'date':
				$order = "i.attachment_date $dir, i.attachment_id $dir";
				break;
			case 'id':
				$order = "i.attachment_id $dir";
				break;
			case 'random':
				// A seeded affine permutation modulo a prime: deterministic for
				// a seed (so a page cache and pagination agree), different per
				// seed, and no ORDER BY RAND() over the whole result. The
				// multiplier comes from a hash of the seed, because nearby
				// multipliers give nearly the same order.
				$p     = self::SHUFFLE_PRIME;
				$a     = 1 + crc32( 'a' . $args['seed'] ) % ( $p - 1 );
				$b     = crc32( 'b' . $args['seed'] ) % $p;
				$order = "( ( i.attachment_id * $a + $b ) % $p ), i.attachment_id";
				break;
			default:
				$order = "concept_score DESC, i.attachment_date DESC, i.attachment_id DESC";
		}

		$relevance = 'relevance' === $args['orderby'];
		$pool      = $relevance
			? min( self::POOL_MAX, max( self::POOL_MIN, ( $args['offset'] + $args['limit'] ) * 4 ) )
			: $args['limit'];
		$offset    = $relevance ? 0 : $args['offset'];

		$sql = "SELECT $select FROM $items i $join WHERE " . implode( ' AND ', $where )
			. " ORDER BY $order LIMIT " . (int) $pool . ' OFFSET ' . (int) $offset;

		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

		if ( ! $rows ) {
			return [];
		}

		$features = $relevance ? self::usage_features( array_column( $rows, 'attachment_id' ), $args ) : [];
		$alt_lang = $relevance ? self::has_alt( array_column( $rows, 'attachment_id' ), $args['lang'] ) : [];
		$scored   = [];

		foreach ( $rows as $row ) {
			$id    = (int) $row['attachment_id'];
			$score = $relevance ? self::score( $row, $features[ $id ] ?? [], isset( $alt_lang[ $id ] ), $args ) : 0.0;

			$scored[ $id ] = [
				'score'   => $score,
				'matched' => '' === (string) $row['matched'] ? [] : array_values( array_intersect( $args['concepts'], explode( ',', (string) $row['matched'] ) ) ),
				'date'    => (string) $row['attachment_date'],
			];
		}

		if ( $relevance ) {
			uksort( $scored, static function ( $a, $b ) use ( $scored ) {
				return [ $scored[ $b ]['score'], $scored[ $b ]['date'], $b ] <=> [ $scored[ $a ]['score'], $scored[ $a ]['date'], $a ];
			} );
			$scored = array_slice( $scored, $args['offset'], $args['limit'], true );
		}

		$images = MII_Images::get_many( array_keys( $scored ), [
			'lang'    => $args['lang'],
			'post_id' => 1 === count( $args['post_ids'] ) ? $args['post_ids'][0] : 0,
			'hubs'    => $args['hubs'],
		] );

		$out = [];

		foreach ( $scored as $id => $meta ) {
			if ( isset( $images[ $id ] ) ) {
				$out[] = array_merge(
					[ 'attachment_id' => $id, 'score' => round( $meta['score'], 3 ), 'matched_concepts' => $meta['matched'] ],
					$images[ $id ]
				);
			}
		}

		return $out;
	}

	/**
	 * WHERE fragments over usage rows aliased u, [] for "no usage filter",
	 * or false when the filter can match nothing.
	 *
	 * @return string[]|false
	 */
	private static function usage_conditions( array $args ) {
		global $wpdb;

		$where = [];

		if ( $args['same_language'] ) {
			$where[] = $wpdb->prepare( 'u.lang = %s', $args['lang'] );
		}

		if ( $args['post_ids'] ) {
			$where[] = 'u.post_id IN (' . MII_DB::in_ints( $args['post_ids'] ) . ')';
		}

		if ( $args['role'] ) {
			$where[] = $wpdb->prepare( 'u.role = %s', $args['role'] );
		}

		if ( true === $args['featured'] ) {
			$where[] = "u.role = 'featured'";
		}

		if ( $args['place'] ) {
			if ( $args['include_place_descendants'] ) {
				// geotag-plus tags a post with every ancestor place, so "under
				// Greece" is "tagged Greece". The subtree is asked for as well,
				// for any post whose ancestor tags are incomplete.
				$terms = function_exists( 'mavo_geo_subtree_terms' ) ? array_map( 'intval', (array) mavo_geo_subtree_terms( $args['place'] ) ) : [];
				$terms = array_values( array_unique( array_merge( [ $args['place'] ], $terms ) ) );

				$where[] = "u.post_id IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = 'post_tag' AND tt.term_id IN (" . MII_DB::in_ints( $terms ) . ') )';
			} else {
				$where[] = $wpdb->prepare( 'u.geo_place = %d', $args['place'] );
			}
		}

		if ( $args['hub'] ) {
			if ( ! function_exists( 'mavo_get_hub_children' ) ) {
				return false;
			}

			$members = $args['include_hub_descendants']
				? (array) mavo_get_hub_descendants( $args['hub'] )
				: (array) mavo_get_hub_children( $args['hub'] );

			// The hub page's own images belong to it too.
			$members[] = $args['hub'];
			$where[]   = 'u.post_id IN (' . MII_DB::in_ints( $members ) . ')';
		}

		return $where;
	}

	/**
	 * Per-image usage facts for the candidate pool, in one query, under the
	 * same usage filter the search applied.
	 */
	private static function usage_features( array $ids, array $args ): array {
		global $wpdb;

		$where   = self::usage_conditions( $args ) ?: [];
		$where[] = 'u.attachment_id IN (' . MII_DB::in_ints( $ids ) . ')';

		$rows = $wpdb->get_results(
			"SELECT u.attachment_id,
			        COUNT(DISTINCT u.post_id) AS uses,
			        MAX(CASE WHEN u.role = 'featured' THEN 1 ELSE 0 END) AS featured,
			        MAX(u.geo_confidence) AS geo,
			        GROUP_CONCAT(DISTINCT u.role) AS roles
			   FROM " . MII_DB::usage() . ' u
			  WHERE ' . implode( ' AND ', $where ) . '
			  GROUP BY u.attachment_id',
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['attachment_id'] ] = $row;
		}

		return $out;
	}

	private static function has_alt( array $ids, string $lang ): array {
		global $wpdb;

		$found = $wpdb->get_col( $wpdb->prepare(
			'SELECT attachment_id FROM ' . MII_DB::semantics() . ' WHERE lang = %s AND attachment_id IN (' . MII_DB::in_ints( $ids ) . ')',
			$lang
		) );

		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	/**
	 * Deliberately simple and additive, so a consumer can reason about it:
	 *
	 *   concept evidence     3 × Σ best confidence per requested concept
	 *   alt in this language +1
	 *   featured             +1 when prefer_featured, else +0.25
	 *   requested role       +0.5
	 *   geography            + geo confidence (0 … 0.7 today)
	 *   large                +0.25 at 1200px wide or more
	 */
	private static function score( array $row, array $usage, bool $alt_in_lang, array $args ): float {
		$score = 3 * (float) $row['concept_score'];

		if ( $alt_in_lang ) {
			$score += 1;
		}

		if ( ! empty( $usage['featured'] ) ) {
			$score += $args['prefer_featured'] ? 1 : 0.25;
		}

		if ( $args['role'] && in_array( $args['role'], explode( ',', (string) ( $usage['roles'] ?? '' ) ), true ) ) {
			$score += 0.5;
		}

		$score += (float) ( $usage['geo'] ?? 0 );

		if ( (int) $row['width'] >= 1200 ) {
			$score += 0.25;
		}

		$image = [ 'attachment_id' => (int) $row['attachment_id'], 'concept_score' => (float) $row['concept_score'], 'usage' => $usage ];

		/** One candidate's relevance score. */
		return (float) apply_filters( 'mavo_image_search_score', $score, $image, $args );
	}

	private static function slugs( $value ): array {
		$value = is_string( $value ) ? explode( ',', $value ) : (array) $value;

		return array_values( array_unique( array_filter( array_map(
			static fn( $s ) => MII_Concepts::sanitize_slug( (string) $s ),
			$value
		) ) ) );
	}

	private static function date( $value ): ?string {
		if ( ! $value ) {
			return null;
		}

		$time = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null;
	}
}
