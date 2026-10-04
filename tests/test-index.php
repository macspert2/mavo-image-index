<?php
/** Indexing, dimensions, staleness, dictionary changes, failures, purge. */

require __DIR__ . '/harness.php';

global $wpdb;

mii_image( 101, 1200, 800, [ 'fr' => 'Plage de Milos aux eaux turquoise à Lefkada', 'en' => 'Milos Beach with turquoise water on Lefkada' ] );
mii_image( 102, 800, 1200, [ 'fr' => 'Famille de dos sur le sentier côtier' ] );
mii_image( 103, 1000, 1020, [] );
mii_image( 104, 0, 0, [ 'de' => 'Burg' ], '2024-01-01 00:00:00', 'image/svg+xml' );
$wpdb->insert( 'wp_posts', [ 'ID' => 105, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf' ] );

$result = MII_Indexer::index( [ 101, 102, 103, 104, 105, 999 ] );

same( 'results', [ 101 => 'indexed', 102 => 'indexed', 103 => 'unchanged', 104 => 'indexed', 105 => 'removed', 999 => 'removed' ], $result );

/* -------------------------------------------------------------- dimensions */

$items = array_column( $wpdb->get_results( 'SELECT * FROM wp_mavo_image_items', ARRAY_A ), null, 'attachment_id' );
same( 'landscape', 'landscape', $items[101]['orientation'] );
same( 'aspect', 1.5, (float) $items[101]['aspect_ratio'] );
same( 'portrait', 'portrait', $items[102]['orientation'] );
same( 'near-square is square', 'square', $items[103]['orientation'] );
same( 'no dimensions → no orientation', '', $items[104]['orientation'] );
check( 'non-image not indexed', ! isset( $items[105] ) );

same( 'shape square tolerance edge', 'landscape', MII_Indexer::shape( 1060, 1000 )['orientation'] );
add_filter( 'mavo_image_square_tolerance', static fn() => 0.1 );
same( 'tolerance is filterable', 'square', MII_Indexer::shape( 1060, 1000 )['orientation'] );
remove_all_filters( 'mavo_image_square_tolerance' );

/* ------------------------------------------------------------ concepts */

$c = mavo_image_get_concepts( 101, 'de' );
same( 'concepts across languages', [ 'beach', 'turquoise_water' ], array_column( $c, 'slug' ) );
same( 'labels in the asked language', 'Strand', array_column( $c, 'label', 'slug' )['beach'] );
same( 'evidence from both languages', [ 'en', 'fr' ], ( static function ( $l ) { sort( $l ); return $l; } )( array_column( $c, 'langs', 'slug' )['beach'] ) );
same( 'source kept', 'alt', $c[0]['source'] );

check( 'has_concept any language', mavo_image_has_concept( 102, 'coastal_path' ) );
check( 'has_concept implied', mavo_image_has_concept( 102, 'coast' ) );
check( 'has_concept limited to en evidence', ! mavo_image_has_concept( 102, 'coastal_path', 'en' ) );
check( 'has_concept unknown language is false', ! mavo_image_has_concept( 102, 'coastal_path', 'it' ) );

same( 'alt per language', 'Milos Beach with turquoise water on Lefkada', mavo_image_get_alt( 101, 'en' ) );
same( 'no alt in a language → null, no fallback', null, mavo_image_get_alt( 102, 'de' ) );

$indexed = array_filter( $GLOBALS['MOCK_ACTIONS'], static fn( $a ) => 'mavo_image_indexed' === $a[0] );
check( 'mavo_image_indexed fired per language', count( $indexed ) === 4, count( $indexed ) );

/* ---------------------------------------------------------- unchanged skip */

same( 'second run is a no-op', [ 101 => 'unchanged' ], MII_Indexer::index( [ 101 ] ) );

/* -------------------------------------------------------------- staleness */

$s = MII_Status::summary();
same( 'fr indexed', 2, $s['languages']['fr']['indexed'] );
same( 'fr stale', 0, $s['languages']['fr']['stale'] );
same( 'fr missing (no alt)', 2, $s['languages']['fr']['missing'] );
same( 'de indexed', 1, $s['languages']['de']['indexed'] );
same( 'images', 4, $s['images'] );

mii_meta( 101, '_wp_attachment_image_alt', 'Coucher de soleil sur la plage de Milos' );
mii_meta( 102, '_wp_attachment_image_alt', null );          // alt removed
mii_image( 106, 640, 480, [ 'fr' => 'Un village blanc' ] ); // never indexed

$s = MII_Status::summary();
same( 'changed + orphaned + new are stale', 3, $s['languages']['fr']['stale'] );
same( 'stale ids', [ 101, 102, 106 ], MII_Status::stale_ids( 0, 10 ) );
same( 'stale ids honour the cursor', [ 106 ], MII_Status::stale_ids( 102, 10 ) );

$step = MII_Rebuild::step( 'stale', 0, 10 );
same( 'stale rebuild processed', 3, $step['processed'] );
check( 'stale rebuild done', $step['done'] );

check( 'concepts follow the new alt', in_array( 'sunset', array_column( mavo_image_get_concepts( 101 ), 'slug' ), true ) );
same( 'fr evidence is exactly the new alt', [ 'beach', 'sunset' ], array_column( MII_Images::concepts_for( [ 101 ], 'fr', 'fr' )[101], 'slug' ) );
check( 'english evidence untouched', in_array( 'turquoise_water', array_column( mavo_image_get_concepts( 101 ), 'slug' ), true ) );
same( 'removed alt removes its concepts', [], mavo_image_get_concepts( 102 ) );
same( 'nothing stale', [], MII_Status::stale_ids( 0, 10 ) );

/* --------------------------------------------------------- dictionary change */

add_action( 'mavo_image_register_concepts', static function () {
	mavo_register_image_concept( 'sunset', [ 'synonyms' => [ 'fr' => [ 'crépuscule' ] ] ] );
} );
MII_Concepts::reset();

same( 'dictionary change makes every row stale', [ 101, 104, 106 ], MII_Status::stale_ids( 0, 10 ) );
MII_Rebuild::step( 'stale', 0, 10 );
same( 'and a stale rebuild clears it', [], MII_Status::stale_ids( 0, 10 ) );
same( 'version option recorded', MII_Concepts::version(), get_option( MII_Concepts::VERSION_OPTION ) );

$row = $wpdb->get_row( "SELECT source_updated_at, indexed_at FROM wp_mavo_image_semantics WHERE attachment_id = 104", ARRAY_A );
check( 'source_updated_at kept across a dictionary rebuild', null !== $row['source_updated_at'] );

/* ------------------------------------------------------------------ failure */

add_filter( 'mavo_image_matcher_output', static function ( $m, $text ) {
	if ( str_contains( $text, 'boom' ) ) {
		throw new RuntimeException( 'boom' );
	}
	return $m;
}, 10, 2 );
mii_image( 107, 640, 480, [ 'fr' => 'boom' ] );
mii_image( 108, 640, 480, [ 'fr' => 'Une plage' ] );

same( 'one failure does not stop the batch', [ 107 => 'failed', 108 => 'indexed' ], MII_Indexer::index( [ 107, 108 ] ) );
same( 'failed counted', 1, MII_Status::summary()['languages']['fr']['failed'] );
check( 'failed is retried by a stale rebuild', in_array( 107, MII_Status::stale_ids( 0, 10 ), true ) );
same( 'error recorded', 'boom', $wpdb->get_var( "SELECT error FROM wp_mavo_image_semantics WHERE attachment_id = 107" ) );

/* -------------------------------------------------------------------- purge */

MII_Indexer::purge( 101 );
same( 'purge removes every row', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_image_concepts WHERE attachment_id = 101' )
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_image_items WHERE attachment_id = 101' ) );

/* ----------------------------------------------- full rebuild cleans orphans */

$wpdb->query( 'DELETE FROM wp_posts WHERE ID = 108' );
$step = MII_Rebuild::step( 'images', 0, 100 );
same( 'full rebuild total', 6, $step['total'] );
same( 'deleted attachment cleaned at the end', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_image_items WHERE attachment_id = 108' ) );
check( 'last rebuild recorded', isset( get_option( MII_Rebuild::LAST_OPTION )['images'] ) );

$matched = mavo_image_match_concepts( 'Plage aux eaux turquoise', 'fr' );
same( 'match_concepts on free text', [ 'beach', 'turquoise_water' ], ( static function ( $m ) { $c = array_column( $m, 'concept' ); sort( $c ); return $c; } )( $matched ) );
same( 'match_concepts shape', [ 'concept', 'confidence', 'matched' ], array_keys( $matched[0] ?? [] ) );

done();
