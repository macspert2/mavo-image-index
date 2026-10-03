<?php
/**
 * Where each image is used, and as what.
 *
 * Not post_parent: that records which post an image was first uploaded to,
 * which says nothing about the three other articles that reuse it, or about
 * the article it was uploaded to and then removed from.
 *
 * Roles detected:
 *
 *   featured  _thumbnail_id
 *   content   an <img> in post_content — by wp-image-NNN class (most of the
 *             site), data-id, or, for the ~16% of older imported images with
 *             neither, the src URL resolved to an attachment
 *   gallery   [gallery ids="…"]
 *
 * 'hero' is a valid role with no detector here: whatever renders heroes can
 * add rows through the mavo_image_usage_extract filter.
 *
 * Extraction runs while indexing — on save, on a rebuild — never while a page
 * is rendered. Each row also carries the post's language and a snapshot of
 * its geography (MII_Geo), so every search filter is a column.
 */

defined( 'ABSPATH' ) || exit;

class MII_Usage {

	const ROLES = [ 'featured', 'content', 'gallery', 'hero' ];

	private static array $url_cache = [];

	/** @return string[] */
	public static function post_types(): array {
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'mavo_image_usage_post_types', [ 'post', 'page' ] ) ) ) );
	}

	/**
	 * Every image a post uses. Pure: give it markup and a thumbnail ID.
	 *
	 * @param callable|null $resolve_url src => attachment ID (0 if unknown).
	 *                                   Null means "do not resolve URLs".
	 * @return array<int,array{attachment_id:int,role:string,source:string,position:int}>
	 */
	public static function extract( string $content, int $thumbnail_id = 0, ?callable $resolve_url = null ): array {
		$found = [];

		if ( $thumbnail_id > 0 ) {
			$found[ $thumbnail_id . ':featured' ] = [
				'attachment_id' => $thumbnail_id,
				'role'          => 'featured',
				'source'        => 'thumbnail',
				'position'      => 0,
			];
		}

		if ( false !== stripos( $content, '<img' ) && preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
			foreach ( $tags[0] as $position => $tag ) {
				[ $id, $source ] = self::identify( $tag, $resolve_url );

				$key = $id . ':content';

				if ( $id > 0 && ! isset( $found[ $key ] ) ) {
					$found[ $key ] = [
						'attachment_id' => $id,
						'role'          => 'content',
						'source'        => $source,
						'position'      => $position + 1,
					];
				}
			}
		}

		if ( false !== strpos( $content, '[gallery' ) && preg_match_all( '/\[gallery\b[^\]]*\bids=["\']?([\d,\s]+)/i', $content, $galleries ) ) {
			$position = 0;

			foreach ( $galleries[1] as $list ) {
				foreach ( array_filter( array_map( 'intval', explode( ',', $list ) ) ) as $id ) {
					$key = $id . ':gallery';

					if ( ! isset( $found[ $key ] ) ) {
						$found[ $key ] = [
							'attachment_id' => $id,
							'role'          => 'gallery',
							'source'        => 'shortcode',
							'position'      => ++$position,
						];
					}
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * (Re)index the usages of a batch of posts. A post that is no longer
	 * published, or no longer exists, loses its rows.
	 *
	 * @param int[] $post_ids
	 * @return int Rows inserted, updated or deleted.
	 */
	public static function index_posts( array $post_ids ): int {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );

		if ( ! $post_ids ) {
			return 0;
		}

		$in    = MII_DB::in_ints( $post_ids );
		$types = self::post_types();
		$posts = [];

		foreach ( (array) $wpdb->get_results(
			"SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts} WHERE ID IN ($in)",
			ARRAY_A
		) as $row ) {
			if ( 'publish' === $row['post_status'] && in_array( $row['post_type'], $types, true ) ) {
				$posts[ (int) $row['ID'] ] = $row;
			}
		}

		update_meta_cache( 'post', array_keys( $posts ) );

		$langs = MII_Lang::post_languages( array_keys( $posts ) );
		$geo   = MII_Geo::resolve_posts( $langs );

		$existing = [];
		foreach ( (array) $wpdb->get_results(
			'SELECT * FROM ' . MII_DB::usage() . " WHERE post_id IN ($in)",
			ARRAY_A
		) as $row ) {
			$existing[ (int) $row['post_id'] ][ $row['attachment_id'] . ':' . $row['role'] ] = $row;
		}

		$writes  = 0;
		$touched = [];
		$now     = MII_DB::now();

		foreach ( $post_ids as $post_id ) {
			$wanted = [];

			if ( isset( $posts[ $post_id ] ) ) {
				$post  = $posts[ $post_id ];
				$items = self::extract(
					(string) $post['post_content'],
					(int) get_post_meta( $post_id, '_thumbnail_id', true ),
					[ __CLASS__, 'resolve_url' ]
				);

				/** Usages found in one post. Add 'hero' or other roles here. */
				$items = (array) apply_filters( 'mavo_image_usage_extract', $items, $post_id );

				foreach ( $items as $item ) {
					$role = sanitize_key( (string) ( $item['role'] ?? '' ) );
					$id   = (int) ( $item['attachment_id'] ?? 0 );

					if ( $id > 0 && in_array( $role, self::ROLES, true ) ) {
						$wanted[ $id . ':' . $role ] = [
							'attachment_id' => $id,
							'role'          => $role,
							'source'        => sanitize_key( (string) ( $item['source'] ?? '' ) ),
							'position'      => max( 0, (int) ( $item['position'] ?? 0 ) ),
						];
					}
				}
			}

			$have = $existing[ $post_id ] ?? [];
			$lang = $langs[ $post_id ] ?? MII_Lang::default_language();
			$ctx  = $geo[ $post_id ] ?? MII_Geo::unknown();

			foreach ( array_diff_key( $have, $wanted ) as $row ) {
				$wpdb->delete( MII_DB::usage(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
				$touched[] = [ (int) $row['attachment_id'], $post_id ];
				$writes++;
			}

			foreach ( $wanted as $key => $item ) {
				$data = [
					'lang'           => $lang,
					'source'         => $item['source'],
					'position'       => $item['position'],
					'geo_precision'  => (string) $ctx['precision'],
					'geo_confidence' => (float) $ctx['confidence'],
					'geo_lat'        => $ctx['lat'],
					'geo_lng'        => $ctx['lng'],
					'geo_place'      => $ctx['place'] ? (int) $ctx['place'] : null,
					'geo_country'    => ! empty( $ctx['country'] ) ? (int) $ctx['country'] : null,
					'geo_region'     => ! empty( $ctx['region'] ) ? (int) $ctx['region'] : null,
				];

				if ( isset( $have[ $key ] ) ) {
					if ( ! self::differs( $have[ $key ], $data ) ) {
						continue;
					}

					$wpdb->update( MII_DB::usage(), $data + [ 'updated_at' => $now ], [ 'id' => (int) $have[ $key ]['id'] ] );
				} else {
					$wpdb->insert( MII_DB::usage(), $data + [
						'attachment_id' => $item['attachment_id'],
						'post_id'       => $post_id,
						'role'          => $item['role'],
						'created_at'    => $now,
						'updated_at'    => $now,
					] );
				}

				$touched[] = [ $item['attachment_id'], $post_id ];
				$writes++;
			}
		}

		if ( $writes ) {
			MII_Cache::bump();

			foreach ( array_unique( array_map( 'serialize', $touched ) ) as $pair ) {
				[ $attachment_id, $post_id ] = unserialize( $pair );

				/** An attachment's usage in one post was added, changed or removed. */
				do_action( 'mavo_image_usage_updated', $attachment_id, $post_id );
			}
		}

		return $writes;
	}

	/** Remove a post's rows outright, for a post being deleted. */
	public static function purge_post( int $post_id ): void {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT attachment_id FROM ' . MII_DB::usage() . ' WHERE post_id = %d', $post_id ) );

		if ( ! $ids ) {
			return;
		}

		$wpdb->delete( MII_DB::usage(), [ 'post_id' => $post_id ], [ '%d' ] );
		MII_Cache::bump();

		foreach ( $ids as $attachment_id ) {
			do_action( 'mavo_image_usage_updated', (int) $attachment_id, $post_id );
		}
	}

	/**
	 * Usage rows for a batch of attachments, with each post's date.
	 *
	 * @param int[] $attachment_ids
	 * @return array<int,array[]> attachment_id => rows
	 */
	public static function for_attachments( array $attachment_ids, ?string $lang = null ): array {
		global $wpdb;

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		$out            = array_fill_keys( $attachment_ids, [] );

		if ( ! $attachment_ids ) {
			return $out;
		}

		$where = '';

		if ( null !== $lang ) {
			$where = $wpdb->prepare( ' AND u.lang = %s', $lang );
		}

		$rows = $wpdb->get_results(
			'SELECT u.*, p.post_date FROM ' . MII_DB::usage() . " u
			   JOIN {$wpdb->posts} p ON p.ID = u.post_id
			  WHERE u.attachment_id IN (" . MII_DB::in_ints( $attachment_ids ) . ")$where
			  ORDER BY u.attachment_id, u.post_id, u.role",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['attachment_id'] ][] = $row;
		}

		return $out;
	}

	/**
	 * An image src to an attachment ID, for content images with no class.
	 *
	 * Only the site's own uploads are looked up. The variants (resized,
	 * -scaled, -rotated, .webp sidecar) are mavo-img-srcset's knowledge; it is
	 * asked first, and the fallback strips just the size suffix and sidecar.
	 */
	public static function resolve_url( string $src ): int {
		if ( isset( self::$url_cache[ $src ] ) ) {
			return self::$url_cache[ $src ];
		}

		$id = 0;

		if ( false !== strpos( $src, '/wp-content/uploads/' ) ) {
			if ( is_callable( [ 'Mavo_Alt_Admin', 'url_candidates' ] ) ) {
				$candidates = (array) Mavo_Alt_Admin::url_candidates( $src );
			} else {
				$plain      = (string) preg_replace( '/\.webp$/i', '', $src );
				$candidates = array_unique( [ $plain, (string) preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $plain ) ] );
			}

			foreach ( $candidates as $url ) {
				$id = (int) attachment_url_to_postid( (string) $url );

				if ( $id > 0 ) {
					break;
				}
			}
		}

		return self::$url_cache[ $src ] = $id;
	}

	/* -------------------------------------------------------------- private */

	/** @return array{0:int,1:string} attachment ID and how it was found */
	private static function identify( string $tag, ?callable $resolve_url ): array {
		if ( preg_match( '/\bclass\s*=\s*["\'][^"\']*\bwp-image-(\d+)\b/i', $tag, $m ) ) {
			return [ (int) $m[1], 'class' ];
		}

		if ( preg_match( '/\bdata-id\s*=\s*["\']?(\d+)/i', $tag, $m ) ) {
			return [ (int) $m[1], 'data_id' ];
		}

		if ( $resolve_url && preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $m ) ) {
			$id = (int) call_user_func( $resolve_url, html_entity_decode( $m[1], ENT_QUOTES ) );

			if ( $id > 0 ) {
				return [ $id, 'url' ];
			}
		}

		return [ 0, '' ];
	}

	private static function differs( array $row, array $data ): bool {
		foreach ( $data as $key => $value ) {
			$have = $row[ $key ] ?? null;

			if ( null === $value || null === $have ) {
				if ( ( null === $value ) !== ( null === $have ) ) {
					return true;
				}
				continue;
			}

			if ( is_float( $value ) ? abs( (float) $have - $value ) > 0.0000001 : (string) $have !== (string) $value ) {
				return true;
			}
		}

		return false;
	}
}
