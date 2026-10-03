<?php
/**
 * Builds the public image object, for one image or twenty, in a fixed number
 * of queries — never a query per image.
 *
 * Alt text is read live from the attachment, not from the index: it is the
 * source of truth, and an alt edited a second ago should show. Concepts come
 * from the index, since computing them is the index's job.
 */

defined( 'ABSPATH' ) || exit;

class MII_Images {

	/**
	 * @param int[] $ids
	 * @param array $args lang (string|null), post_id (int, geo context),
	 *                    hubs (bool, add mavo-hubs memberships per usage)
	 * @return array<int,array> attachment_id => image, in the order given;
	 *                          ids that are not images are left out
	 */
	public static function get_many( array $ids, array $args = [] ): array {
		global $wpdb;

		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$lang = MII_Lang::resolve( $args['lang'] ?? null );
		$out  = [];

		if ( ! $ids ) {
			return $out;
		}

		$in = MII_DB::in_ints( $ids );

		$items = [];
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . MII_DB::items() . " WHERE attachment_id IN ($in)", ARRAY_A ) as $row ) {
			$items[ (int) $row['attachment_id'] ] = $row;
		}

		// Attachments not yet indexed are still answered, from live data.
		$missing = array_diff( $ids, array_keys( $items ) );
		$live    = [];

		if ( $missing ) {
			foreach ( (array) $wpdb->get_results(
				"SELECT ID, post_mime_type, post_date FROM {$wpdb->posts}
				  WHERE ID IN (" . MII_DB::in_ints( $missing ) . ") AND post_type = 'attachment' AND post_mime_type LIKE 'image/%'",
				ARRAY_A
			) as $row ) {
				$live[ (int) $row['ID'] ] = $row;
			}
		}

		$found = array_values( array_filter( $ids, static fn( $id ) => isset( $items[ $id ] ) || isset( $live[ $id ] ) ) );

		if ( ! $found ) {
			return $out;
		}

		// Meta for URLs, alt text and live dimensions; posts for URLs.
		update_meta_cache( 'post', $found );
		_prime_post_caches( $found, false, false );

		$concepts = self::concepts_for( $found, $lang );
		$semantic = self::semantics_for( $found );
		$usages   = MII_Usage::for_attachments( $found );
		$names    = MII_Geo::place_names( array_merge( [], ...array_map(
			[ 'MII_Geo', 'place_terms' ],
			array_values( $usages )
		) ) );
		$hubs     = ! empty( $args['hubs'] ) ? self::hubs_for( $usages ) : [];

		foreach ( $found as $id ) {
			if ( isset( $items[ $id ] ) ) {
				$row   = $items[ $id ];
				$shape = [
					'width'        => (int) $row['width'],
					'height'       => (int) $row['height'],
					'aspect_ratio' => (float) $row['aspect_ratio'],
					'orientation'  => (string) $row['orientation'],
				];
				$mime  = (string) $row['mime_type'];
				$date  = $row['attachment_date'];
			} else {
				$shape = MII_Indexer::dimensions( $id );
				$mime  = (string) $live[ $id ]['post_mime_type'];
				$date  = $live[ $id ]['post_date'];
			}

			$alts = MII_Alt::all( $id );
			$rows = $usages[ $id ] ?? [];

			$out[ $id ] = [
				'attachment_id' => $id,
				'url'           => (string) wp_get_attachment_url( $id ),
				'mime_type'     => $mime,
				'alt'           => $alts,
				'alt_text'      => $alts[ $lang ] ?? $alts[ MII_Lang::default_language() ] ?? '',
				'alt_lang'      => isset( $alts[ $lang ] ) ? $lang : ( isset( $alts[ MII_Lang::default_language() ] ) ? MII_Lang::default_language() : null ),
				'concepts'      => $concepts[ $id ] ?? [],
				'dimensions'    => $shape,
				'usages'        => self::usages_public( $rows, $hubs ),
				'featured_for'  => array_values( array_unique( array_map( 'intval', array_column(
					array_filter( $rows, static fn( $r ) => 'featured' === $r['role'] ),
					'post_id'
				) ) ) ),
				'geo'           => MII_Geo::summarize( $rows, [ 'post_id' => (int) ( $args['post_id'] ?? 0 ) ], $names ),
				'dates'         => [
					'attachment_date'   => $date,
					'source_updated_at' => $semantic[ $id ]['source_updated_at'] ?? null,
					'indexed_at'        => $semantic[ $id ]['indexed_at'] ?? null,
				],
				'indexed'       => isset( $items[ $id ] ),
			];
		}

		return $out;
	}

	/**
	 * Concepts per attachment, one entry per concept: the strongest evidence
	 * across every language and source, with where it came from.
	 *
	 * @return array<int,array[]>
	 */
	public static function concepts_for( array $ids, ?string $lang = null, ?string $evidence_lang = null ): array {
		global $wpdb;

		$where = '';

		if ( null !== $evidence_lang ) {
			$where = $wpdb->prepare( ' AND lang = %s', $evidence_lang );
		}

		$rows = $wpdb->get_results(
			'SELECT attachment_id, lang, concept, source, confidence FROM ' . MII_DB::concepts() . '
			  WHERE attachment_id IN (' . MII_DB::in_ints( $ids ) . ")$where
			  ORDER BY confidence DESC",
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$id   = (int) $row['attachment_id'];
			$slug = (string) $row['concept'];

			if ( ! isset( $out[ $id ][ $slug ] ) ) {
				$out[ $id ][ $slug ] = [
					'slug'       => $slug,
					'label'      => MII_Concepts::label( $slug, $lang ),
					'source'     => (string) $row['source'],
					'confidence' => round( (float) $row['confidence'], 3 ),
					'langs'      => [],
					'sources'    => [],
				];
			}

			$out[ $id ][ $slug ]['langs'][]   = (string) $row['lang'];
			$out[ $id ][ $slug ]['sources'][] = (string) $row['source'];
		}

		foreach ( $out as $id => $concepts ) {
			foreach ( $concepts as $slug => $concept ) {
				$out[ $id ][ $slug ]['langs']   = array_values( array_unique( $concept['langs'] ) );
				$out[ $id ][ $slug ]['sources'] = array_values( array_unique( $concept['sources'] ) );
			}

			$out[ $id ] = array_values( $out[ $id ] );
		}

		return $out;
	}

	/* -------------------------------------------------------------- private */

	/** Latest change and index time across an attachment's languages. */
	private static function semantics_for( array $ids ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT attachment_id, MAX(source_updated_at) AS source_updated_at, MAX(indexed_at) AS indexed_at
			   FROM ' . MII_DB::semantics() . '
			  WHERE attachment_id IN (' . MII_DB::in_ints( $ids ) . ')
			  GROUP BY attachment_id',
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['attachment_id'] ] = $row;
		}

		return $out;
	}

	/** mavo-hubs memberships per using post. Empty if mavo-hubs is absent. */
	private static function hubs_for( array $usages ): array {
		if ( ! function_exists( 'mavo_get_hubs' ) ) {
			return [];
		}

		$post_ids = array_values( array_unique( array_merge( [], ...array_map(
			static fn( $rows ) => array_map( 'intval', array_column( $rows, 'post_id' ) ),
			array_values( $usages )
		) ) ) );

		update_meta_cache( 'post', $post_ids );

		$out = [];

		foreach ( $post_ids as $post_id ) {
			$out[ $post_id ] = array_map( 'intval', (array) mavo_get_hubs( $post_id ) );
		}

		return $out;
	}

	private static function usages_public( array $rows, array $hubs ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$usage = [
				'post_id'   => (int) $row['post_id'],
				'lang'      => (string) $row['lang'],
				'role'      => (string) $row['role'],
				'source'    => (string) $row['source'],
				'position'  => (int) $row['position'],
				'post_date' => $row['post_date'] ?? null,
			];

			if ( $hubs ) {
				$usage['hubs'] = $hubs[ (int) $row['post_id'] ] ?? [];
			}

			$out[] = $usage;
		}

		return $out;
	}
}
