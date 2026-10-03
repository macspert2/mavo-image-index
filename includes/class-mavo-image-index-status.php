<?php
/**
 * How complete and how current the index is. Admin and CLI only.
 *
 * Per language, every image attachment is exactly one of:
 *
 *   indexed  has alt text, and the stored row matches its hash and the
 *            current dictionary version
 *   stale    has alt text, and the row is missing, or its hash or dictionary
 *            version differs — or a row survives for alt text since removed
 *   failed   the last attempt to index it threw
 *   missing  has no alt text in this language (coverage, not an error)
 *
 * Hashes are compared in SQL (MD5 of the stored meta value against the stored
 * hash), so the answer for 16,000 attachments is three queries per language,
 * not 16,000 meta reads.
 */

defined( 'ABSPATH' ) || exit;

class MII_Status {

	public static function summary(): array {
		global $wpdb;

		$version = MII_Concepts::sync_version_option();
		$images  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$langs   = [];

		foreach ( MII_Lang::languages() as $lang ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) AS with_alt,
				        SUM(CASE WHEN s.id IS NULL THEN 1 ELSE 0 END) AS pending,
				        SUM(CASE WHEN s.status = 'failed' THEN 1 ELSE 0 END) AS failed,
				        SUM(CASE WHEN s.status = 'indexed' AND ( s.alt_hash <> MD5(pm.meta_value) OR s.dict_version <> %s ) THEN 1 ELSE 0 END) AS stale,
				        SUM(CASE WHEN s.status = 'indexed' AND s.alt_hash = MD5(pm.meta_value) AND s.dict_version = %s THEN 1 ELSE 0 END) AS indexed
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND TRIM(pm.meta_value) <> ''
				   LEFT JOIN " . MII_DB::semantics() . " s ON s.attachment_id = p.ID AND s.lang = %s
				  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'",
				$version,
				$version,
				MII_Alt::meta_key( $lang ),
				$lang
			), ARRAY_A );

			$orphans = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . MII_DB::semantics() . " s
				  WHERE s.lang = %s
				    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm
				                      WHERE pm.post_id = s.attachment_id AND pm.meta_key = %s AND TRIM(pm.meta_value) <> '' )",
				$lang,
				MII_Alt::meta_key( $lang )
			) );

			$with_alt = (int) ( $row['with_alt'] ?? 0 );

			$langs[ $lang ] = [
				'with_alt' => $with_alt,
				'indexed'  => (int) ( $row['indexed'] ?? 0 ),
				'stale'    => (int) ( $row['stale'] ?? 0 ) + (int) ( $row['pending'] ?? 0 ) + $orphans,
				'failed'   => (int) ( $row['failed'] ?? 0 ),
				'missing'  => max( 0, $images - $with_alt ),
			];
		}

		$items_missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN " . MII_DB::items() . " i ON i.attachment_id = p.ID
			  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND i.attachment_id IS NULL"
		);

		$roles = [];
		foreach ( (array) $wpdb->get_results( 'SELECT role, COUNT(*) AS n FROM ' . MII_DB::usage() . ' GROUP BY role', ARRAY_A ) as $row ) {
			$roles[ $row['role'] ] = (int) $row['n'];
		}

		return [
			'images'           => $images,
			'items'            => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MII_DB::items() ),
			'items_missing'    => $items_missing,
			'languages'        => $langs,
			'usage_rows'       => array_sum( $roles ),
			'usage_by_role'    => $roles,
			'used_images'      => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT attachment_id) FROM ' . MII_DB::usage() ),
			'concept_rows'     => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MII_DB::concepts() ),
			'concepts_defined' => count( MII_Concepts::all() ),
			'concepts_used'    => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT concept) FROM ' . MII_DB::concepts() ),
			'last_rebuild'     => (array) get_option( MII_Rebuild::LAST_OPTION, [] ),
			'db_version'       => (int) get_option( MII_DB::DB_VERSION_OPTION, 0 ),
			'dict_version'     => $version,
		];
	}

	/**
	 * Images per concept, per language of evidence.
	 *
	 * @return array<string,array<string,int>> lang => [ concept => images ]
	 */
	public static function top_concepts( int $limit = 15 ): array {
		global $wpdb;

		$out = [];

		foreach ( MII_Lang::languages() as $lang ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT concept, COUNT(DISTINCT attachment_id) AS n FROM ' . MII_DB::concepts() . '
				  WHERE lang = %s GROUP BY concept ORDER BY n DESC, concept ASC LIMIT %d',
				$lang,
				$limit
			), ARRAY_A );

			$out[ $lang ] = [];

			foreach ( (array) $rows as $row ) {
				$out[ $lang ][ $row['concept'] ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * Attachments needing (re)indexing, above a cursor, ascending.
	 *
	 * Stale, failed, never indexed, with an orphaned row, or with no items row.
	 *
	 * @return int[]
	 */
	public static function stale_ids( int $after, int $limit ): array {
		global $wpdb;

		$version = MII_Concepts::version();
		$ids     = [];

		foreach ( MII_Lang::languages() as $lang ) {
			$key = MII_Alt::meta_key( $lang );

			$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND TRIM(pm.meta_value) <> ''
				   LEFT JOIN " . MII_DB::semantics() . " s ON s.attachment_id = p.ID AND s.lang = %s
				  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID > %d
				    AND ( s.id IS NULL OR s.status <> 'indexed' OR s.alt_hash <> MD5(pm.meta_value) OR s.dict_version <> %s )
				  ORDER BY p.ID LIMIT %d",
				$key,
				$lang,
				$after,
				$version,
				$limit
			) ) );

			$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
				'SELECT s.attachment_id FROM ' . MII_DB::semantics() . " s
				  WHERE s.lang = %s AND s.attachment_id > %d
				    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm
				                      WHERE pm.post_id = s.attachment_id AND pm.meta_key = %s AND TRIM(pm.meta_value) <> '' )
				  ORDER BY s.attachment_id LIMIT %d",
				$lang,
				$after,
				$key,
				$limit
			) ) );
		}

		$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN " . MII_DB::items() . " i ON i.attachment_id = p.ID
			  WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND i.attachment_id IS NULL AND p.ID > %d
			  ORDER BY p.ID LIMIT %d",
			$after,
			$limit
		) ) );

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}
}
