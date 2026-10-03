<?php
/**
 * Approximate geography, always labelled with how approximate it is.
 *
 * Images here carry no coordinates of their own. What they have is the posts
 * that use them, and those posts have, at best, a Geo Mashup location and, more
 * often, a chain of places from mavo-geotag-plus (city → region → country →
 * continent, each a post_tag). So an image's geography is the geography of a
 * usage, and the same photograph used in two articles has two.
 *
 * agent.md's ladder named geo hubs. Those no longer exist — mavo-hubs dropped
 * the geographic axis in favour of geotag-plus places — so the place levels
 * take their rungs:
 *
 *   image_exact      1.00  reserved: coordinates on the image itself
 *   image_manual     0.90  reserved: a location set on the image by hand
 *   post_exact       0.70  the using post's Geo Mashup coordinates
 *   place_city       0.50  the using post's most specific place is a city
 *   place_region     0.40  … a region
 *   place_country    0.25  … a country
 *   place_continent  0.10  … a continent
 *   unknown          0.00
 *
 * Resolution runs at index time and is stored on each usage row, so reading
 * an image's geography is never a hierarchy walk per result. Re-tagging a
 * post or moving its pin re-resolves that post's usages (see MII_Sync).
 */

defined( 'ABSPATH' ) || exit;

class MII_Geo {

	const LADDER = [
		'image_exact'     => 1.0,
		'image_manual'    => 0.9,
		'post_exact'      => 0.7,
		'place_city'      => 0.5,
		'place_region'    => 0.4,
		'place_country'   => 0.25,
		'place_continent' => 0.1,
		'unknown'         => 0.0,
	];

	private static ?bool $geomashup = null;

	/**
	 * Geography for a batch of posts: [ post_id => context ].
	 *
	 * One query for every coordinate; one place-chain lookup per post, which
	 * is geotag-plus's own API and runs only while indexing.
	 *
	 * @param array<int,string> $post_langs post_id => language
	 */
	public static function resolve_posts( array $post_langs ): array {
		$coords = self::coordinates( array_keys( $post_langs ) );
		$out    = [];

		foreach ( $post_langs as $post_id => $lang ) {
			$context = self::unknown();
			$place   = self::chain_places( (int) $post_id, (string) $lang );

			if ( $place ) {
				$precision = 'place_' . $place['level'];

				if ( ! isset( self::LADDER[ $precision ] ) ) {
					$precision = 'place_city';
				}

				$context = [
					'precision'  => $precision,
					'source'     => 'geotag_place',
					'confidence' => self::LADDER[ $precision ],
					'lat'        => null,
					'lng'        => null,
					'place'      => $place['term_id'],
					'country'    => $place['country'],
					'region'     => $place['region'],
				];
			}

			if ( isset( $coords[ $post_id ] ) ) {
				$context = array_merge( $context, [
					'precision'  => 'post_exact',
					'source'     => 'geomashup_post',
					'confidence' => self::LADDER['post_exact'],
					'lat'        => $coords[ $post_id ]['lat'],
					'lng'        => $coords[ $post_id ]['lng'],
				] );
			}

			/** One post's geography, as it will be stored on its usage rows. */
			$out[ $post_id ] = (array) apply_filters( 'mavo_image_post_geo', $context, (int) $post_id, (string) $lang );
		}

		return $out;
	}

	public static function unknown(): array {
		return [
			'precision'  => 'unknown',
			'source'     => 'none',
			'confidence' => 0.0,
			'lat'        => null,
			'lng'        => null,
			'place'      => null,
			'country'    => null,
			'region'     => null,
		];
	}

	/**
	 * The public shape of a context, from a usage row.
	 *
	 * @param array $row   A usage row (geo_* columns, post_id, lang).
	 * @param array $names term_id => name, preloaded by the caller.
	 */
	public static function from_row( array $row, array $names = [] ): array {
		$precision = (string) ( $row['geo_precision'] ?? 'unknown' );
		$place     = isset( $row['geo_place'] ) && $row['geo_place'] ? (int) $row['geo_place'] : null;
		$term      = static function ( $key ) use ( $row, $names ) {
			$id = isset( $row[ $key ] ) && $row[ $key ] ? (int) $row[ $key ] : 0;
			return $id ? [ 'term_id' => $id, 'name' => $names[ $id ] ?? '' ] : null;
		};

		return [
			'lat'        => isset( $row['geo_lat'] ) && null !== $row['geo_lat'] ? (float) $row['geo_lat'] : null,
			'lng'        => isset( $row['geo_lng'] ) && null !== $row['geo_lng'] ? (float) $row['geo_lng'] : null,
			'precision'  => $precision,
			'source'     => self::source_for( $precision, isset( $row['geo_lat'] ) ),
			'confidence' => round( (float) ( $row['geo_confidence'] ?? 0 ), 3 ),
			'post_id'    => (int) $row['post_id'],
			'lang'       => (string) ( $row['lang'] ?? '' ),
			'place'      => $place ? [ 'term_id' => $place, 'name' => $names[ $place ] ?? '' ] : null,
			'country'    => $term( 'geo_country' ),
			'region'     => $term( 'geo_region' ),
		];
	}

	/**
	 * The preferred context plus every candidate, from usage rows.
	 *
	 * A requested post wins outright: an image of a beach used in the Lefkada
	 * article and in a "best beaches" round-up belongs to Lefkada when asked
	 * from the Lefkada article. Otherwise the most precise context wins, then
	 * one in the requested language, then the lowest post ID for stability.
	 *
	 * @param array $rows Usage rows for ONE attachment.
	 * @param array $args post_id (int), lang (string|null)
	 */
	public static function summarize( array $rows, array $args, array $names = [] ): array {
		$by_post = [];

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];

			// Roles in one post share that post's geography.
			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = self::from_row( $row, $names );
			}
		}

		$lang = $args['lang'] ?? null;

		if ( $lang ) {
			$by_post = array_filter( $by_post, static fn( $c ) => $c['lang'] === $lang );
		}

		$candidates = array_values( $by_post );

		usort( $candidates, static function ( $a, $b ) use ( $lang ) {
			return [ $b['confidence'], (int) ( $b['lang'] === $lang ), $a['post_id'] ]
				<=> [ $a['confidence'], (int) ( $a['lang'] === $lang ), $b['post_id'] ];
		} );

		$wanted    = (int) ( $args['post_id'] ?? 0 );
		$preferred = null;

		if ( $wanted && isset( $by_post[ $wanted ] ) ) {
			$preferred = $by_post[ $wanted ];
		} elseif ( $candidates ) {
			$preferred = $candidates[0];
		}

		$summary = $preferred ?? array_merge( self::unknown(), [ 'post_id' => null, 'lang' => null ] );

		$summary['candidates'] = $candidates;

		return $summary;
	}

	/**
	 * Names for place terms, in one query. 'lang' => '' stops Polylang from
	 * filtering the terms down to the current language.
	 *
	 * @param int[] $term_ids
	 * @return array<int,string>
	 */
	public static function place_names( array $term_ids ): array {
		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );

		if ( ! $term_ids ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => 'post_tag',
			'include'    => $term_ids,
			'hide_empty' => false,
			'lang'       => '',
		] );

		$out = [];

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ (int) $term->term_id ] = (string) $term->name;
			}
		}

		return $out;
	}

	/** For tests. */
	public static function reset(): void {
		self::$geomashup = null;
	}

	/* -------------------------------------------------------------- private */

	private static function source_for( string $precision, bool $has_coords ): string {
		if ( 'post_exact' === $precision && $has_coords ) {
			return 'geomashup_post';
		}

		if ( str_starts_with( $precision, 'place_' ) ) {
			return 'geotag_place';
		}

		return 'unknown' === $precision ? 'none' : $precision;
	}

	/**
	 * Every term-ID column a usage row stores a place in, for name lookups.
	 *
	 * @param array[] $rows Usage rows.
	 * @return int[]
	 */
	public static function place_terms( array $rows ): array {
		return array_merge(
			array_column( $rows, 'geo_place' ),
			array_column( $rows, 'geo_country' ),
			array_column( $rows, 'geo_region' )
		);
	}

	/**
	 * The post's places, through mavo-geotag-plus's API: the most specific one,
	 * plus its country and region when the chain has those levels.
	 *
	 * @return array{term_id:int,level:string,country:?int,region:?int}|null
	 */
	private static function chain_places( int $post_id, string $lang ): ?array {
		if ( ! function_exists( 'mavo_geo_place_chain' ) ) {
			return null;
		}

		$chain = mavo_geo_place_chain( $post_id, $lang );

		if ( ! $chain ) {
			return null;
		}

		$leaf    = end( $chain );
		$term_id = (int) ( $leaf->{'term_id_' . $lang} ?? 0 );

		if ( ! $term_id ) {
			return null;
		}

		$levels = [];

		foreach ( $chain as $place ) {
			$id = (int) ( $place->{'term_id_' . $lang} ?? 0 );

			if ( $id ) {
				$levels[ sanitize_key( (string) ( $place->level ?? '' ) ) ] = $id;
			}
		}

		return [
			'term_id' => $term_id,
			'level'   => sanitize_key( (string) ( $leaf->level ?? 'city' ) ),
			'country' => $levels['country'] ?? null,
			'region'  => $levels['region'] ?? null,
		];
	}

	/**
	 * Geo Mashup coordinates for a batch of posts, straight from its tables —
	 * the same way mavo-geotag-plus and mavo-geo-explorer read them, so this
	 * does not depend on Geo Mashup's PHP API being loaded.
	 *
	 * @return array<int,array{lat:float,lng:float}>
	 */
	private static function coordinates( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );

		if ( ! $post_ids || ! self::geomashup_available() ) {
			return [];
		}

		$rows = $wpdb->get_results(
			"SELECT r.object_id AS post_id, l.lat, l.lng
			   FROM {$wpdb->prefix}geo_mashup_location_relationships r
			   JOIN {$wpdb->prefix}geo_mashup_locations l ON l.id = r.location_id
			  WHERE r.object_name = 'post'
			    AND r.object_id IN (" . MII_DB::in_ints( $post_ids ) . ')',
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$lat = (float) $row['lat'];
			$lng = (float) $row['lng'];

			// 0,0 is Geo Mashup's "no location", not a point off Ghana.
			if ( 0.0 === $lat && 0.0 === $lng ) {
				continue;
			}

			$out[ (int) $row['post_id'] ] = [ 'lat' => $lat, 'lng' => $lng ];
		}

		return $out;
	}

	private static function geomashup_available(): bool {
		global $wpdb;

		if ( null === self::$geomashup ) {
			$table           = $wpdb->prefix . 'geo_mashup_locations';
			self::$geomashup = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}

		return self::$geomashup;
	}
}
