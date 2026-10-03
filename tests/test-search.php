<?php
/** Search, best match, geography and hubs, with Polylang/geotag-plus/mavo-hubs present. */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

/* ---------------------------------------------------------------- fixtures */

mii_image( 1, 1600, 1000, [ 'fr' => 'Plage de Milos aux eaux turquoise', 'en' => 'Milos Beach with turquoise water' ], '2023-06-01 00:00:00' );
mii_image( 2, 1200, 800, [ 'fr' => 'Plage de Porto Katsiki' ], '2023-06-02 00:00:00' );
mii_image( 3, 800, 1200, [ 'fr' => 'Famille sur la plage' ], '2023-06-03 00:00:00' );
mii_image( 4, 1000, 700, [ 'en' => 'Boat on turquoise water' ], '2023-06-04 00:00:00' );
mii_image( 5, 1600, 900, [ 'fr' => 'Randonnée sur le sentier des crêtes' ], '2023-07-01 00:00:00' );
mii_image( 6, 700, 1000, [ 'fr' => 'Randonneurs au sommet' ], '2023-07-02 00:00:00' );
mii_image( 7, 1200, 800, [ 'fr' => 'Jardin botanique de Funchal' ], '2022-03-01 00:00:00' );
mii_image( 8, 2000, 1300, [ 'fr' => 'Plage déserte aux eaux turquoise' ], '2021-01-01 00:00:00' );

// Places: Greece 50 > Lefkada 51 (fr); Greece 52 > Lefkada 53 (en);
// Portugal 71 > Madeira 70; Austria 61 > Hallstatt 60.
foreach ( [ 50 => 'Grèce', 51 => 'Lefkada', 52 => 'Greece', 53 => 'Lefkada', 60 => 'Hallstatt', 61 => 'Autriche', 70 => 'Madère', 71 => 'Portugal' ] as $id => $name ) {
	mii_term( $name, 'post_tag', "t$id", $id );
}

mii_post( 100, '<img class="wp-image-1"><img class="wp-image-2"><img class="wp-image-3">', [ 'lang' => 'fr', 'thumb' => 2 ] );
mii_post( 101, '<img class="wp-image-1"><img class="wp-image-4">', [ 'lang' => 'en' ] );
mii_post( 102, '<img class="wp-image-5"><img class="wp-image-6">', [ 'lang' => 'fr', 'thumb' => 6 ] );
mii_post( 103, '<img class="wp-image-7">', [ 'lang' => 'fr' ] );
mii_post( 104, '<img class="wp-image-1">', [ 'lang' => 'fr' ] );
mii_post( 900, '', [ 'lang' => 'fr', 'type' => 'page' ] );
mii_post( 901, '', [ 'lang' => 'fr', 'type' => 'page' ] );

foreach ( [ 100 => [ 50, 51 ], 101 => [ 52, 53 ], 102 => [ 60, 61 ], 103 => [ 70, 71 ] ] as $post => $terms ) {
	foreach ( $terms as $t ) {
		mii_term_rel( $post, $t );
	}
}

$GLOBALS['MOCK_CHAINS'] = [
	100 => [ [ 'country', 50, 52 ], [ 'region', 51, 53 ] ],
	101 => [ [ 'country', 50, 52 ], [ 'region', 51, 53 ] ],
	102 => [ [ 'country', 61 ], [ 'city', 60 ] ],
	103 => [ [ 'country', 71 ], [ 'region', 70 ] ],
];
$GLOBALS['MOCK_SUBTREES'] = [ 50 => [ 50, 51 ], 71 => [ 71, 70 ] ];
mii_geomashup( 100, 38.72, 20.65 );

$GLOBALS['MOCK_HUB_MEMBERS'] = [ 900 => [ 102, 901 ], 901 => [ 103 ] ];
$GLOBALS['MOCK_POST_HUBS']   = [ 102 => [ 900 ], 901 => [ 900 ], 103 => [ 901 ] ];

MII_Rebuild::step( 'images', 0, 100 );
MII_Rebuild::step( 'usages', 0, 100 );

function ids( array $results ): array {
	return array_column( $results, 'attachment_id' );
}

function sorted_ids( array $results ): array {
	$ids = ids( $results );
	sort( $ids );
	return $ids;
}

/* ---------------------------------------------------------- concept logic */

same( 'AND', [ 1 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ] ] ) ) );
same( 'AND, any language', [ 1, 8 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ], 'same_language' => false ] ) ) );
same( 'OR', [ 1, 2, 3 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ], 'concept_operator' => 'OR' ] ) ) );
same( 'OR ranks the image with both first', 1, ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ], 'concept_operator' => 'OR' ] ) )[0] );
same( 'exclude', [ 1, 2 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'exclude_concepts' => [ 'family' ] ] ) ) );
same( 'concepts as a comma list', [ 1 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => 'beach,turquoise_water' ] ) ) );
same( 'unknown concept matches nothing', [], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'volcano' ] ] ) ) );

$r = mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ] ] )[0];
same( 'matched_concepts', [ 'beach', 'turquoise_water' ], $r['matched_concepts'] );
check( 'score present', $r['score'] > 6 );
same( 'alt in the requested language', 'Plage de Milos aux eaux turquoise', $r['alt_text'] );
same( 'dimensions inline', 'landscape', $r['dimensions']['orientation'] );

/* ---------------------------------------------------------------- language */

same( 'fr turquoise ignores en-only usage', [ 1 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'turquoise_water' ] ] ) ) );
same( 'en turquoise', [ 1, 4 ], sorted_ids( mavo_image_search( [ 'lang' => 'en', 'concepts' => [ 'turquoise_water' ] ] ) ) );
same( 'en beach uses fr evidence', [ 1 ], ids( mavo_image_search( [ 'lang' => 'en', 'concepts' => [ 'beach' ] ] ) ) );

$GLOBALS['MOCK_LANG'] = 'en';
same( 'lang defaults to the current language', [ 1, 4 ], sorted_ids( mavo_image_search( [ 'concepts' => [ 'turquoise_water' ] ] ) ) );
$r = mavo_image_search( [ 'concepts' => [ 'beach' ] ] )[0];
same( 'alt falls back to default language', 'Milos Beach with turquoise water', $r['alt_text'] );
$GLOBALS['MOCK_LANG'] = 'fr';

/* -------------------------------------------------------------- dimensions */

same( 'orientation', [ 3 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'orientation' => 'portrait' ] ) ) );
same( 'min_width', [ 1 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'min_width' => 1300 ] ) ) );
same( 'min_height', [ 3 ], ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'min_height' => 1100 ] ) ) );

/* ------------------------------------------------------- roles and dates */

same( 'featured only', [ 2, 6 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'featured' => true ] ) ) );
same( 'not featured', [ 1, 3 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'featured' => false ] ) ) );
same( 'after', [ 5, 6 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'after' => '2023-06-30' ] ) ) );
same( 'before', [ 7 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'before' => '2022-12-31' ] ) ) );
same( 'orderby date', [ 6, 5, 3, 2, 1, 7 ], ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'date' ] ) ) );
same( 'orderby date ASC', [ 7, 1, 2, 3, 5, 6 ], ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'date', 'order' => 'ASC' ] ) ) );

/* -------------------------------------------------------------- text search */

same( 'text phrase', [ 1 ], ids( mavo_image_search( [ 'lang' => 'fr', 'text' => 'eaux turquoise' ] ) ) );
same( 'text is whole-word', [], ids( mavo_image_search( [ 'lang' => 'fr', 'text' => 'turquois' ] ) ) );
same( 'text is case-insensitive', [ 5 ], ids( mavo_image_search( [ 'lang' => 'fr', 'text' => 'SENTIER' ] ) ) );
same( 'text in another language', [ 4 ], ids( mavo_image_search( [ 'lang' => 'en', 'text' => 'boat' ] ) ) );
same( 'text with LIKE wildcards is literal', [], ids( mavo_image_search( [ 'lang' => 'fr', 'text' => '%' ] ) ) );

/* -------------------------------------------------------------- best match */

$best = mavo_image_best_match( [ 'post_ids' => [ 102 ], 'concepts' => [ 'hiking' ], 'orientation' => 'landscape', 'min_width' => 960 ] );
same( 'best hiking landscape in one article', 5, $best['attachment_id'] ?? null );
same( 'best match with nothing to match', null, mavo_image_best_match( [ 'post_ids' => [ 102 ], 'concepts' => [ 'beach' ] ] ) );

$best = mavo_image_best_match( [ 'post_ids' => [ 102 ], 'concepts' => [ 'hiking' ], 'prefer_featured' => true ] );
same( 'prefer_featured tips the balance', 6, $best['attachment_id'] ?? null );

add_filter( 'mavo_image_search_score', static fn( $score, $image ) => 5 === $image['attachment_id'] ? $score + 10 : $score, 10, 2 );
MII_Cache::bump();
same( 'score filter', 5, mavo_image_best_match( [ 'post_ids' => [ 102 ], 'concepts' => [ 'hiking' ], 'prefer_featured' => true ] )['attachment_id'] );
remove_all_filters( 'mavo_image_search_score' );
MII_Cache::bump();

/* ---------------------------------------------------------------- places */

same( 'place with descendants', [ 1, 2, 3 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'place' => 50 ] ) ) );
same( 'place via subtree', [ 7 ], ids( mavo_image_search( [ 'lang' => 'fr', 'place' => 71, 'concepts' => [ 'garden' ] ] ) ) );
same( 'place, exact leaf only', [], ids( mavo_image_search( [ 'lang' => 'fr', 'place' => 71, 'include_place_descendants' => false ] ) ) );
same( 'place, exact leaf', [ 7 ], ids( mavo_image_search( [ 'lang' => 'fr', 'place' => 70, 'include_place_descendants' => false ] ) ) );
same( 'agent.md: garden in Madeira', [ 7 ], ids( mavo_image_search( [ 'place' => 70, 'include_place_descendants' => true, 'concepts' => [ 'garden' ], 'limit' => 12 ] ) ) );

/* ------------------------------------------------------------------ hubs */

same( 'hub direct members', [ 5, 6 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'hub' => 900 ] ) ) );
same( 'hub with descendants', [ 5, 6, 7 ], sorted_ids( mavo_image_search( [ 'lang' => 'fr', 'hub' => 900, 'include_hub_descendants' => true ] ) ) );
$r = mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'garden' ], 'hubs' => true ] )[0];
same( 'hub memberships per usage', [ 901 ], $r['usages'][0]['hubs'] );

/* -------------------------------------------------------------------- geo */

$geo = mavo_image_get_geo_context( 1 );
same( 'preferred: most precise usage', 'post_exact', $geo['precision'] );
same( 'source named', 'geomashup_post', $geo['source'] );
same( 'coordinates', [ 38.72, 20.65 ], [ $geo['lat'], $geo['lng'] ] );
same( 'confidence', 0.7, $geo['confidence'] );
same( 'from post', 100, $geo['post_id'] );
same( 'place alongside coordinates', 'Lefkada', $geo['place']['name'] );
same( 'every usage is a candidate', 3, count( $geo['candidates'] ) );

$geo = mavo_image_get_geo_context( 1, [ 'post_id' => 104 ] );
same( 'requested usage wins, even when vaguer', [ 104, 'unknown' ], [ $geo['post_id'], $geo['precision'] ] );

$geo = mavo_image_get_geo_context( 1, [ 'post_id' => 101 ] );
same( 'reused image, other article: place only', [ 'place_region', 'geotag_place', null ], [ $geo['precision'], $geo['source'], $geo['lat'] ] );
same( 'place term in the usage language', 53, $geo['place']['term_id'] );

same( 'city precision', 'place_city', mavo_image_get_geo_context( 5 )['precision'] );
same( 'hub-free region inference', [ 'place_region', 'Madère' ], [ mavo_image_get_geo_context( 7 )['precision'], mavo_image_get_geo_context( 7 )['place']['name'] ] );
same( 'never used: unknown', [ 'unknown', 0.0, null ], [ mavo_image_get_geo_context( 8 )['precision'], mavo_image_get_geo_context( 8 )['confidence'], mavo_image_get_geo_context( 8 )['lat'] ] );
same( 'language-limited geo', [ 101 ], array_column( mavo_image_get_geo_context( 1, [ 'lang' => 'en' ] )['candidates'], 'post_id' ) );

add_filter( 'mavo_image_geo_context', static function ( $c ) { $c['precision'] = 'image_manual'; return $c; } );
same( 'geo filter', 'image_manual', mavo_image_get_geo_context( 8 )['precision'] );
remove_all_filters( 'mavo_image_geo_context' );

$r = mavo_image_search( [ 'lang' => 'fr', 'post_ids' => [ 104 ], 'concepts' => [ 'beach' ] ] )[0];
same( 'search geo follows a single requested post', 104, $r['geo']['post_id'] );

// Re-tagging moves the geography (via the sync hooks).
MII_Sync::init();
$GLOBALS['MOCK_CHAINS'][104] = [ [ 'country', 71 ], [ 'region', 70 ] ];
do_action( 'set_object_terms', 104, [], [], 'post_tag' );
MII_Sync::flush();
same( 'retag updates stored geography', 'place_region', mavo_image_get_geo_context( 1, [ 'post_id' => 104 ] )['precision'] );

/* ------------------------------------------------------------ random order */

$a = ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'random', 'seed' => 7 ] ) );
$b = ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'random', 'seed' => 7 ] ) );
same( 'random is deterministic per seed', $a, $b );
$p1 = ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'random', 'seed' => 7, 'limit' => 3 ] ) );
$p2 = ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'random', 'seed' => 7, 'limit' => 3, 'offset' => 3 ] ) );
same( 'random pages tile the result', $a, array_merge( $p1, $p2 ) );
$sorted = $a;
sort( $sorted );
same( 'random keeps every result', [ 1, 2, 3, 5, 6, 7 ], $sorted );

$seeds = [];
foreach ( range( 1, 6 ) as $seed ) {
	$seeds[] = implode( ',', ids( mavo_image_search( [ 'lang' => 'fr', 'orderby' => 'random', 'seed' => $seed ] ) ) );
}
check( 'different seeds, different orders', count( array_unique( $seeds ) ) > 1, $seeds );
check( 'no ORDER BY RAND()', ! preg_grep( '/RAND\(/i', $wpdb->queries ) );

/* ------------------------------------------------------------- pagination */

$all = ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'concept_operator' => 'OR' ] ) );
$one = ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'concept_operator' => 'OR', 'limit' => 2 ] ) );
$two = ids( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach' ], 'concept_operator' => 'OR', 'limit' => 2, 'offset' => 2 ] ) );
same( 'relevance pages tile the result', $all, array_merge( $one, $two ) );

/* ------------------------------------------------------------------- N+1 */

function queries_for( array $args ): int {
	global $wpdb;
	MII_Cache::bump();
	$before = $wpdb->num_queries;
	mavo_image_search( $args );
	return $wpdb->num_queries - $before;
}

$few  = queries_for( [ 'lang' => 'fr', 'concepts' => [ 'garden' ] ] );
$many = queries_for( [ 'lang' => 'fr', 'same_language' => false ] );
same( 'query count does not grow with results', $few, $many );
check( 'and stays small', $many <= 12, $many );

$before = $wpdb->num_queries;
mavo_image_search( [ 'lang' => 'fr', 'same_language' => false ] );
same( 'repeat search is served from cache', $before, $wpdb->num_queries );

/* ----------------------------------------------------------- image object */

$img = mavo_image_get( 1, [ 'lang' => 'de' ] );
same( 'object keys', [ 'attachment_id', 'url', 'mime_type', 'alt', 'alt_text', 'alt_lang', 'concepts', 'dimensions', 'usages', 'featured_for', 'geo', 'dates', 'indexed' ], array_keys( $img ) );
same( 'alt map', [ 'fr', 'en' ], array_keys( $img['alt'] ) );
same( 'alt_lang falls back to default', 'fr', $img['alt_lang'] );
same( 'not an image', [], mavo_image_get( 100 ) );

mii_image( 9, 900, 900, [ 'fr' => 'Pas encore indexée' ] );
$img = mavo_image_get( 9 );
same( 'unindexed image still answered', [ false, 'square' ], [ $img['indexed'], $img['dimensions']['orientation'] ] );

done();
