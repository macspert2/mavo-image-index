<?php
/** Usage extraction and indexing: roles, sources, reuse, unpublishing, sync. */

require __DIR__ . '/harness.php';

global $wpdb;

/* ---------------------------------------------------------------- extract */

$html = '<p><img class="size-full wp-image-54491 aligncenter mavo-img-tag" src="https://example.test/wp-content/uploads/a.jpg" alt="x"></p>'
	. '<img data-id="200" src="b.jpg">'
	. '<img src="https://example.test/wp-content/uploads/2020/01/old-640x480.jpg">'
	. '<img src="https://elsewhere.test/c.jpg">'
	. '<img class="wp-image-54491" src="again.jpg">'
	. '[gallery ids="300, 301,54491"]';

$GLOBALS['MOCK_URL_MAP']['https://example.test/wp-content/uploads/2020/01/old.jpg'] = 400;

$found = MII_Usage::extract( $html, 500, [ 'MII_Usage', 'resolve_url' ] );
$by    = [];
foreach ( $found as $f ) {
	$by[ $f['attachment_id'] . ':' . $f['role'] ] = $f['source'] . '@' . $f['position'];
}

same( 'roles and sources', [
	'500:featured'  => 'thumbnail@0',
	'54491:content' => 'class@1',
	'200:content'   => 'data_id@2',
	'400:content'   => 'url@3',
	'300:gallery'   => 'shortcode@1',
	'301:gallery'   => 'shortcode@2',
	'54491:gallery' => 'shortcode@3',
], $by );

same( 'no resolver, no URL lookups', [ 54491, 200, 300, 301, 54491 ], array_column( MII_Usage::extract( $html, 0, null ), 'attachment_id' ) );
same( 'no images', [], MII_Usage::extract( 'Just text', 0 ) );

/* ------------------------------------------------------------ index posts */

mii_image( 10, 1200, 800, [ 'fr' => 'Plage' ] );
mii_image( 11, 1200, 800, [ 'fr' => 'Montagne' ] );

mii_post( 1, '<img class="wp-image-10" src="x.jpg"><img class="wp-image-11" src="y.jpg">', [ 'lang' => 'fr', 'thumb' => 11 ] );
mii_post( 2, '<img class="wp-image-10" src="x.jpg">', [ 'lang' => 'en' ] );
mii_post( 3, '<img class="wp-image-10" src="x.jpg">', [ 'status' => 'draft', 'lang' => 'fr' ] );
mii_post( 4, '<img class="wp-image-10" src="x.jpg">', [ 'type' => 'product', 'lang' => 'fr' ] );

MII_Usage::index_posts( [ 1, 2, 3, 4 ] );

$u = mavo_image_get_usages( 10 );
same( 'reused attachment: two usages', [ 1, 2 ], array_column( $u, 'post_id' ) );
same( 'usage languages', [ 'fr', 'en' ], array_column( $u, 'lang' ) );
same( 'language filter', [ 2 ], array_column( mavo_image_get_usages( 10, [ 'lang' => 'en' ] ), 'post_id' ) );

$u11 = mavo_image_get_usages( 11 );
same( 'featured and content in one post', [ 'content', 'featured' ], array_column( $u11, 'role' ) );
same( 'role filter', [ 'featured' ], array_column( mavo_image_get_usages( 11, [ 'role' => 'featured' ] ), 'role' ) );

$img = mavo_image_get( 11 );
same( 'featured_for', [ 1 ], $img['featured_for'] );
same( 'post_parent is not consulted', 2, count( $img['usages'] ) );

$updated = array_filter( $GLOBALS['MOCK_ACTIONS'], static fn( $a ) => 'mavo_image_usage_updated' === $a[0] );
same( 'usage_updated fired once per attachment/post pair', 3, count( $updated ) );

/* ----------------------------------------------------- idempotent, then diff */

$GLOBALS['MOCK_ACTIONS'] = [];
same( 'unchanged reindex writes nothing', 0, MII_Usage::index_posts( [ 1, 2 ] ) );
same( 'and fires nothing', [], $GLOBALS['MOCK_ACTIONS'] );

$wpdb->update( 'wp_posts', [ 'post_content' => '<img class="wp-image-11" src="y.jpg">' ], [ 'ID' => 1 ] );
MII_Usage::index_posts( [ 1 ] );
same( 'image removed from content loses its row', [ 2 ], array_column( mavo_image_get_usages( 10 ), 'post_id' ) );

$wpdb->update( 'wp_posts', [ 'post_status' => 'draft' ], [ 'ID' => 2 ] );
MII_Usage::index_posts( [ 2 ] );
same( 'unpublished post loses its rows', [], mavo_image_get_usages( 10 ) );

/* -------------------------------------------------------------- sync hooks */

MII_Sync::init();

$wpdb->update( 'wp_posts', [ 'post_status' => 'publish' ], [ 'ID' => 2 ] );
do_action( 'save_post', 2, new WP_Post( 2, 'post' ) );
mii_meta( 10, '_wp_attachment_image_alt', 'Plage de sable' );
do_action( 'updated_post_meta', 1, 10, '_wp_attachment_image_alt', 'Plage de sable' );
do_action( 'shutdown' );

same( 'save_post reindexes usages at shutdown', [ 2 ], array_column( mavo_image_get_usages( 10 ), 'post_id' ) );
same( 'alt meta change reindexes the attachment', 'Plage de sable', $wpdb->get_var( "SELECT alt_text FROM wp_mavo_image_semantics WHERE attachment_id = 10 AND lang = 'fr'" ) );

do_action( 'deleted_post', 2 );
same( 'deleted post loses its rows immediately', [], mavo_image_get_usages( 10 ) );

do_action( 'delete_attachment', 11 );
same( 'deleted attachment loses every row', [], mavo_image_get_usages( 11 ) );

// A bulk change defers past the inline cap to WP-Cron.
for ( $i = 1; $i <= MII_Sync::INLINE_MAX + 5; $i++ ) {
	MII_Sync::queue_post( 9000 + $i );
}
MII_Sync::flush();
same( 'overflow deferred', 5, count( MII_Sync::stored_queue()['posts'] ) );
check( 'cron scheduled', false !== wp_next_scheduled( MII_Sync::CRON_HOOK ) );
MII_Sync::process_queue();
same( 'cron drains the queue', 0, count( MII_Sync::stored_queue()['posts'] ) );

/* ------------------------------------------------------- usages rebuild */

mii_post( 5, '<img class="wp-image-10" src="x.jpg">', [ 'lang' => 'de' ] );
$wpdb->insert( 'wp_mavo_image_usage', [ 'attachment_id' => 10, 'post_id' => 777, 'lang' => 'fr', 'role' => 'content', 'created_at' => 'x', 'updated_at' => 'x' ] );

$step = MII_Rebuild::step( 'usages', 0, 100 );
check( 'usages rebuild done', $step['done'] );
same( 'usages rebuild finds posts and drops rows of missing posts', [ 2, 5 ], array_column( mavo_image_get_usages( 10 ), 'post_id' ) );

done();
