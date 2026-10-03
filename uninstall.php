<?php
/**
 * Deleting the plugin (not deactivating it) removes its tables and options.
 * Everything here is derived from attachments and posts, so a reinstall and
 * one "Rebuild all" restores it; nothing curated is lost.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( [ 'mavo_image_items', 'mavo_image_semantics', 'mavo_image_concepts', 'mavo_image_usage' ] as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( [
	'mavo_image_index_db_version',
	'mavo_image_index_concept_version',
	'mavo_image_index_cache_gen',
	'mavo_image_index_last_rebuild',
	'mavo_image_index_queue',
	'mavo_image_index_more_targets',
] as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'mavo_image_index_process_queue' );
