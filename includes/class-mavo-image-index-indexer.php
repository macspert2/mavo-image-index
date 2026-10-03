<?php
/**
 * Writes the items, semantics and concepts rows for a batch of attachments.
 *
 * Idempotent and incremental: a language whose alt text hash and dictionary
 * version both match the stored row is skipped unless $force. One attachment
 * failing is recorded against that attachment and the batch carries on.
 *
 * Only concepts with source 'alt' are rewritten here. Rows from any other
 * source (manual, image_analysis, …) belong to whoever wrote them.
 */

defined( 'ABSPATH' ) || exit;

class MII_Indexer {

	/** Within this much of 1:1 an image counts as square (4:3 is 1.333). */
	const SQUARE_TOLERANCE = 0.05;

	/**
	 * @param int[] $attachment_ids
	 * @return array<int,string> id => indexed | unchanged | removed | failed
	 */
	public static function index( array $attachment_ids, bool $force = false ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		$out = [];

		if ( ! $ids ) {
			return $out;
		}

		$version = MII_Concepts::sync_version_option();
		$in      = MII_DB::in_ints( $ids );

		$posts = [];
		foreach ( (array) $wpdb->get_results(
			"SELECT ID, post_type, post_mime_type, post_date FROM {$wpdb->posts} WHERE ID IN ($in)",
			ARRAY_A
		) as $row ) {
			$posts[ (int) $row['ID'] ] = $row;
		}

		$existing = [];
		foreach ( (array) $wpdb->get_results(
			'SELECT attachment_id, lang, alt_hash, dict_version, status, source_updated_at FROM ' . MII_DB::semantics() . " WHERE attachment_id IN ($in)",
			ARRAY_A
		) as $row ) {
			$existing[ (int) $row['attachment_id'] ][ $row['lang'] ] = $row;
		}

		// One query for every attachment's meta in the batch, instead of a
		// query per get_post_meta() below.
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			$post = $posts[ $id ] ?? null;

			if ( ! $post || 'attachment' !== $post['post_type'] || ! str_starts_with( (string) $post['post_mime_type'], 'image/' ) ) {
				self::purge( $id );
				$out[ $id ] = 'removed';
				continue;
			}

			try {
				self::write_item( $id, $post );

				$changed = false;

				foreach ( MII_Lang::languages() as $lang ) {
					$changed = self::index_language( $id, $lang, $existing[ $id ][ $lang ] ?? null, $version, $force ) || $changed;
				}

				$out[ $id ] = $changed ? 'indexed' : 'unchanged';
			} catch ( Throwable $e ) {
				self::mark_failed( $id, $e->getMessage() );
				$out[ $id ] = 'failed';
			}
		}

		if ( array_diff( $out, [ 'unchanged' ] ) ) {
			MII_Cache::bump();
		}

		return $out;
	}

	/** Remove every row about an attachment, from every table. */
	public static function purge( int $attachment_id ): void {
		global $wpdb;

		foreach ( [ MII_DB::items(), MII_DB::semantics(), MII_DB::concepts(), MII_DB::usage() ] as $table ) {
			$wpdb->delete( $table, [ 'attachment_id' => $attachment_id ], [ '%d' ] );
		}
	}

	/**
	 * Dimensions and orientation from the attachment metadata, which records
	 * the dimensions after WordPress's EXIF auto-rotation. No file is opened.
	 *
	 * @return array{width:int,height:int,aspect_ratio:float,orientation:string}
	 */
	public static function dimensions( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );

		return self::shape(
			(int) ( is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0 ),
			(int) ( is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0 )
		);
	}

	/** @return array{width:int,height:int,aspect_ratio:float,orientation:string} */
	public static function shape( int $width, int $height ): array {
		if ( $width < 1 || $height < 1 ) {
			return [ 'width' => max( 0, $width ), 'height' => max( 0, $height ), 'aspect_ratio' => 0.0, 'orientation' => '' ];
		}

		$ratio     = $width / $height;
		$tolerance = (float) apply_filters( 'mavo_image_square_tolerance', self::SQUARE_TOLERANCE );

		if ( abs( $ratio - 1 ) <= $tolerance ) {
			$orientation = 'square';
		} else {
			$orientation = $ratio > 1 ? 'landscape' : 'portrait';
		}

		return [
			'width'        => $width,
			'height'       => $height,
			'aspect_ratio' => round( $ratio, 4 ),
			'orientation'  => $orientation,
		];
	}

	/* -------------------------------------------------------------- private */

	private static function write_item( int $id, array $post ): void {
		global $wpdb;

		$shape = self::dimensions( $id );

		MII_DB::check( $wpdb->replace(
			MII_DB::items(),
			[
				'attachment_id'   => $id,
				'mime_type'       => (string) $post['post_mime_type'],
				'width'           => $shape['width'],
				'height'          => $shape['height'],
				'aspect_ratio'    => $shape['aspect_ratio'],
				'orientation'     => $shape['orientation'],
				'attachment_date' => $post['post_date'] ?: null,
				'indexed_at'      => MII_DB::now(),
			],
			[ '%d', '%s', '%d', '%d', '%f', '%s', '%s', '%s' ]
		) );
	}

	/** @return bool Whether anything was written. */
	private static function index_language( int $id, string $lang, ?array $existing, string $version, bool $force ): bool {
		global $wpdb;

		$raw = MII_Alt::raw( $id, $lang );
		$alt = trim( $raw );

		if ( '' === $alt ) {
			if ( ! $existing ) {
				return false;
			}

			$wpdb->delete( MII_DB::semantics(), [ 'attachment_id' => $id, 'lang' => $lang ], [ '%d', '%s' ] );
			$wpdb->delete( MII_DB::concepts(), [ 'attachment_id' => $id, 'lang' => $lang, 'source' => 'alt' ], [ '%d', '%s', '%s' ] );

			return true;
		}

		$hash = md5( $raw );

		if ( ! $force && $existing
			&& 'indexed' === $existing['status']
			&& $hash === $existing['alt_hash']
			&& $version === $existing['dict_version']
		) {
			return false;
		}

		$now     = MII_DB::now();
		$matches = MII_Matcher::match( $alt, $lang );

		MII_DB::check( $wpdb->delete( MII_DB::concepts(), [ 'attachment_id' => $id, 'lang' => $lang, 'source' => 'alt' ], [ '%d', '%s', '%s' ] ) );

		foreach ( $matches as $match ) {
			MII_DB::check( $wpdb->insert(
				MII_DB::concepts(),
				[
					'attachment_id' => $id,
					'lang'          => $lang,
					'concept'       => $match['concept'],
					'source'        => 'alt',
					'confidence'    => $match['confidence'],
					'matched'       => mb_substr( (string) $match['matched'], 0, 191 ),
					'indexed_at'    => $now,
				],
				[ '%d', '%s', '%s', '%s', '%f', '%s', '%s' ]
			) );
		}

		// When the text itself is unchanged (a dictionary rebuild), keep the
		// time it last changed rather than claiming it changed now.
		$source_updated = ( $existing && $existing['alt_hash'] === $hash && ! empty( $existing['source_updated_at'] ) )
			? $existing['source_updated_at']
			: $now;

		MII_DB::check( $wpdb->replace(
			MII_DB::semantics(),
			[
				'attachment_id'     => $id,
				'lang'              => $lang,
				'alt_text'          => $alt,
				// Padded, so text search can anchor on whole words with LIKE '% x %'.
				'alt_norm'          => ' ' . MII_Matcher::normalize( $alt ) . ' ',
				'alt_hash'          => $hash,
				'dict_version'      => $version,
				'status'            => 'indexed',
				'error'             => '',
				'source_updated_at' => $source_updated,
				'indexed_at'        => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		) );

		/** An attachment's alt text in one language was (re)matched. */
		do_action( 'mavo_image_indexed', $id, $lang, wp_list_pluck( $matches, 'concept' ) );

		return true;
	}

	/** Record a failure on every language row this attachment has alt text in. */
	private static function mark_failed( int $id, string $message ): void {
		global $wpdb;

		foreach ( MII_Lang::languages() as $lang ) {
			$raw = MII_Alt::raw( $id, $lang );

			if ( '' === trim( $raw ) ) {
				continue;
			}

			$wpdb->replace(
				MII_DB::semantics(),
				[
					'attachment_id' => $id,
					'lang'          => $lang,
					'alt_text'      => trim( $raw ),
					'alt_norm'      => '',
					'alt_hash'      => md5( $raw ),
					'dict_version'  => '',
					'status'        => 'failed',
					'error'         => mb_substr( $message, 0, 255 ),
					'indexed_at'    => MII_DB::now(),
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}
}
