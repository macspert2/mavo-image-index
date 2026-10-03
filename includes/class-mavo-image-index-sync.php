<?php
/**
 * Keeps the index current as the site changes.
 *
 * Hooks only collect IDs; the work happens once, at shutdown, so a save that
 * fires save_post, three meta updates and two set_object_terms calls costs one
 * reindex. A request that dirties more than a handful of items (a bulk edit,
 * a geotag-plus batch) hands the rest to a WP-Cron event rather than making
 * that request slow.
 *
 * What triggers what:
 *
 *   alt meta (any language), _wp_attachment_metadata, attachment saved
 *                                         → reindex that attachment
 *   attachment deleted                    → its rows go immediately
 *   post saved / trashed, _thumbnail_id,
 *   language or post_tag terms changed,
 *   Geo Mashup location saved             → reindex that post's usages
 *                                           (content, language, geography)
 *   post deleted                          → its usage rows go immediately
 *   mavo-hubs membership or hub changed   → cache only: hubs are read live
 */

defined( 'ABSPATH' ) || exit;

class MII_Sync {

	const CRON_HOOK    = 'mavo_image_index_process_queue';
	const QUEUE_OPTION = 'mavo_image_index_queue';
	const INLINE_MAX   = 20;
	const CRON_BATCH   = 200;

	private static array $attachments = [];
	private static array $posts       = [];
	private static bool $hooked       = false;

	public static function init(): void {
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_meta' ], 10, 3 );
		}

		add_action( 'add_attachment', [ __CLASS__, 'queue_attachment' ] );
		add_action( 'edit_attachment', [ __CLASS__, 'queue_attachment' ] );
		add_action( 'delete_attachment', [ __CLASS__, 'on_delete_attachment' ] );

		// After mavo-geotag-plus, which tags places on save_post at 20.
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 30, 2 );
		add_action( 'trashed_post', [ __CLASS__, 'queue_post' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'queue_post' ] );
		add_action( 'deleted_post', [ __CLASS__, 'on_deleted_post' ] );

		add_action( 'set_object_terms', [ __CLASS__, 'on_terms' ], 10, 4 );
		add_action( 'geo_mashup_location_saved', [ __CLASS__, 'on_geo_mashup' ], 10, 3 );

		foreach ( [ 'mavo_hub_membership_added', 'mavo_hub_membership_removed', 'mavo_hub_marked', 'mavo_hub_unmarked' ] as $hook ) {
			add_action( $hook, [ 'MII_Cache', 'bump' ], 10, 0 );
		}

		add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );
	}

	public static function on_meta( $meta_ids, $object_id, $meta_key ): void {
		$meta_key = (string) $meta_key;

		if ( '_wp_attachment_metadata' === $meta_key || isset( MII_Alt::meta_keys()[ $meta_key ] ) ) {
			self::queue_attachment( (int) $object_id );
		} elseif ( '_thumbnail_id' === $meta_key ) {
			self::queue_post( (int) $object_id );
		}
	}

	public static function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'attachment' === $post->post_type ) {
			self::queue_attachment( (int) $post_id );
		} elseif ( in_array( $post->post_type, MII_Usage::post_types(), true ) ) {
			self::queue_post( (int) $post_id );
		}
	}

	public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( in_array( $taxonomy, [ 'language', 'post_tag' ], true ) ) {
			self::queue_post( (int) $object_id );
		}
	}

	public static function on_geo_mashup( $location_id, $object_name, $object_id ): void {
		if ( 'post' === $object_name ) {
			self::queue_post( (int) $object_id );
		}
	}

	public static function on_delete_attachment( $post_id ): void {
		MII_Indexer::purge( (int) $post_id );
		MII_Cache::bump();
		unset( self::$attachments[ (int) $post_id ] );
	}

	public static function on_deleted_post( $post_id ): void {
		MII_Usage::purge_post( (int) $post_id );
		unset( self::$posts[ (int) $post_id ] );
	}

	public static function queue_attachment( $attachment_id ): void {
		self::$attachments[ (int) $attachment_id ] = true;
		self::hook_shutdown();
	}

	public static function queue_post( $post_id ): void {
		self::$posts[ (int) $post_id ] = true;
		self::hook_shutdown();
	}

	/** Process what this request dirtied. */
	public static function flush(): void {
		$attachments       = array_keys( self::$attachments );
		$posts             = array_keys( self::$posts );
		self::$attachments = [];
		self::$posts       = [];

		if ( ! $attachments && ! $posts ) {
			return;
		}

		$now_a = array_slice( $attachments, 0, self::INLINE_MAX );
		$now_p = array_slice( $posts, 0, self::INLINE_MAX );

		self::defer( array_slice( $attachments, self::INLINE_MAX ), array_slice( $posts, self::INLINE_MAX ) );

		try {
			if ( $now_a ) {
				MII_Indexer::index( $now_a );
			}
			if ( $now_p ) {
				MII_Usage::index_posts( $now_p );
			}
		} catch ( Throwable $e ) {
			// Never break a save over the index. A rebuild catches it up.
			self::defer( $now_a, $now_p );
		}
	}

	/** WP-Cron: work through the deferred queue, a batch at a time. */
	public static function process_queue(): void {
		$queue = self::stored_queue();

		$attachments = array_splice( $queue['attachments'], 0, self::CRON_BATCH );
		$posts       = array_splice( $queue['posts'], 0, self::CRON_BATCH );

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( $attachments ) {
			MII_Indexer::index( $attachments );
		}
		if ( $posts ) {
			MII_Usage::index_posts( $posts );
		}

		if ( $queue['attachments'] || $queue['posts'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/** @return array{attachments:int[],posts:int[]} */
	public static function stored_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, [] );

		return [
			'attachments' => array_values( array_map( 'intval', (array) ( $queue['attachments'] ?? [] ) ) ),
			'posts'       => array_values( array_map( 'intval', (array) ( $queue['posts'] ?? [] ) ) ),
		];
	}

	/* -------------------------------------------------------------- private */

	private static function defer( array $attachments, array $posts ): void {
		if ( ! $attachments && ! $posts ) {
			return;
		}

		$queue = self::stored_queue();

		$queue['attachments'] = array_values( array_unique( array_merge( $queue['attachments'], $attachments ) ) );
		$queue['posts']       = array_values( array_unique( array_merge( $queue['posts'], $posts ) ) );

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	private static function hook_shutdown(): void {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ __CLASS__, 'flush' ] );
		}
	}
}
