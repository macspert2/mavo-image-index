<?php
/**
 * Resumable batch rebuilds, shared by the admin screen (one AJAX request per
 * step) and WP-CLI (a loop of steps). A step takes a cursor — the last ID it
 * handled — and returns the next one, so nothing has to stay in memory or in
 * an option between steps, and an interrupted rebuild resumes where it left
 * off by simply starting again.
 *
 *   images  every image attachment, forced
 *   stale   only attachments MII_Status reports stale or failed
 *   usages  every published post of a usage post type
 */

defined( 'ABSPATH' ) || exit;

class MII_Rebuild {

	const LAST_OPTION   = 'mavo_image_index_last_rebuild';
	const DEFAULT_BATCH = 100;
	const MODES         = [ 'images', 'stale', 'usages' ];

	/**
	 * @return array{done:bool,cursor:int,processed:int,failed:int[],total:?int}
	 *         total is only counted on the first step (cursor 0).
	 */
	public static function step( string $mode, int $cursor = 0, int $batch = self::DEFAULT_BATCH ): array {
		global $wpdb;

		$batch  = max( 1, min( 1000, $batch ) );
		$total  = null;
		$failed = [];

		switch ( $mode ) {
			case 'images':
				if ( 0 === $cursor ) {
					$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
				}

				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d ORDER BY ID LIMIT %d",
					$cursor,
					$batch
				) ) );

				foreach ( MII_Indexer::index( $ids, true ) as $id => $result ) {
					if ( 'failed' === $result ) {
						$failed[] = $id;
					}
				}
				break;

			case 'stale':
				$ids = MII_Status::stale_ids( $cursor, $batch );

				foreach ( MII_Indexer::index( $ids, false ) as $id => $result ) {
					if ( 'failed' === $result ) {
						$failed[] = $id;
					}
				}
				break;

			case 'usages':
				$types = MII_DB::in_strings( MII_Usage::post_types() );

				if ( 0 === $cursor ) {
					$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($types)" );
				}

				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($types) AND ID > %d ORDER BY ID LIMIT %d",
					$cursor,
					$batch
				) ) );

				MII_Usage::index_posts( $ids );
				break;

			default:
				return [ 'done' => true, 'cursor' => $cursor, 'processed' => 0, 'failed' => [], 'total' => 0 ];
		}

		$done = count( $ids ) < $batch;

		if ( $done ) {
			self::finish( $mode );
		}

		return [
			'done'      => $done,
			'cursor'    => $ids ? max( $ids ) : $cursor,
			'processed' => count( $ids ),
			'failed'    => $failed,
			'total'     => $total,
		];
	}

	/**
	 * Remove rows nothing points at any more — attachments deleted, posts
	 * unpublished — which an ID-cursor pass never visits.
	 */
	private static function finish( string $mode ): void {
		global $wpdb;

		if ( 'usages' === $mode ) {
			$types = MII_DB::in_strings( MII_Usage::post_types() );

			$wpdb->query( 'DELETE FROM ' . MII_DB::usage() . " WHERE post_id NOT IN (
				SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($types) )" );
		} else {
			$images = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'";

			foreach ( [ MII_DB::items(), MII_DB::semantics(), MII_DB::concepts() ] as $table ) {
				$wpdb->query( "DELETE FROM $table WHERE attachment_id NOT IN ( $images )" );
			}
		}

		$last          = (array) get_option( self::LAST_OPTION, [] );
		$last[ $mode ] = time();

		update_option( self::LAST_OPTION, $last, false );
		MII_Cache::bump();
	}
}
