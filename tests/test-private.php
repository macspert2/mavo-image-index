<?php
/**
 * Private concepts (family, children): indexed and searchable from code, on
 * no public surface. The point is that the blog's photos of its children
 * cannot be listed in one place by following links.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

mii_post( 50, '', [ 'type' => 'page', 'lang' => 'fr' ] );
$GLOBALS['MOCK_TRANSLATIONS'][50] = [ 'fr' => 50 ];
$GLOBALS['MOCK_PERMALINKS']       = [ 50 => 'https://example.test/images/' ];
update_option( MII_Results::PAGE_OPTION, 50 );
MII_Results::schedule_refresh();
MII_Results::register_rules();

$content = '';
foreach ( range( 1001, 1012 ) as $id ) {
	$alt = [ 'Famille sur la plage', 'Enfants au bord du lac', 'Plage de sable', 'Lac de montagne' ][ $id % 4 ];
	mii_image( $id, 1200, 800, [ 'fr' => $alt ] );
	$content .= '<img class="wp-image-' . $id . '">';
}
mii_post( 101, $content, [ 'lang' => 'fr' ] );
MII_Rebuild::step( 'images', 0, 100 );
MII_Rebuild::step( 'usages', 0, 100 );

foreach ( [ 'family', 'children' ] as $private ) {
	check( "$private is a concept", MII_Concepts::exists( $private ) );
	check( "$private is not public", ! MII_Concepts::is_public( $private ) );
	same( "$private has no URL", '', MII_Results::url( $private, 'fr' ) );
	same( "$private has no place URL", '', MII_Results::url( $private, 'fr', 1, 1 ) );
	same( "$private gets no [mavo_image_more] link, even with a URL", '', MII_Shortcode::render( [ 'concept' => $private, 'url' => 'https://example.test/x/' ] ) );
}
check( 'beach is public', MII_Concepts::is_public( 'beach' ) );

// Still indexed and searchable from code.
check( 'family photos are indexed', count( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'family' ] ] ) ) > 0 );
$family_ids = array_column( mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'family' ] ] ), 'attachment_id' );
sort( $family_ids );
same( 'the family photos', [ 1004, 1008, 1012 ], $family_ids );
check( 'and excludable', ! array_intersect( $family_ids, array_column( mavo_image_search( [ 'lang' => 'fr', 'exclude_concepts' => [ 'family' ] ] ), 'attachment_id' ) ) );

// Visiting a private topic's URL is an ordinary 404.
foreach ( [ 'en-famille', 'enfants', 'family', 'children' ] as $slug ) {
	MII_Results::reset();
	$GLOBALS['MOCK_DID']['wp'] = 1;
	$GLOBALS['MOCK_QUERIED']   = 50;
	$GLOBALS['MOCK_QV']        = [ 'mii_concept' => $slug, 'mii_page' => 0 ];
	$GLOBALS['MOCK_STATUS']    = 200;
	$GLOBALS['wp_query']       = new WP_Query_Stub();

	MII_Results::template_redirect();
	same( "/images/$slug/ is a 404", 404, $GLOBALS['MOCK_STATUS'] );
	same( "/images/$slug/ renders nothing", '', MII_Results::shortcode( [] ) );
}

check( 'a fixed-concept page cannot be private', ! str_contains( MII_Results::shortcode( [ 'concept' => 'children' ] ), 'mavo-image-results__grid' ) );

// Not in the concept list, the pills, or the browse rows.
MII_Results::reset();
$GLOBALS['MOCK_QV'] = [];
$list = MII_Results::render_concept_list( 'fr' );
check( 'concept list: beach yes', str_contains( $list, '>Plage</a>' ), $list );
check( 'concept list: no family, no children', ! str_contains( $list, 'En famille' ) && ! str_contains( $list, 'Enfants' ), $list );

same( 'browse rows refuse private concepts', [ 'beach' ], MII_Results::parse_rows( "family\nchildren\nbeach" ) );
update_option( MII_Results::ROWS_OPTION, [ 'family', 'children', 'beach' ] );
same( 'a stored private row is ignored', [ 'beach' ], MII_Results::row_concepts() );

// Pills: the family group's other member would be the pill on a family page;
// on a public page of the same group none may appear either.
$related = new ReflectionMethod( 'MII_Results', 'render_related' );
add_action( 'mavo_image_register_concepts', static function () {
	mavo_register_image_concept( 'playground', [ 'group' => 'family', 'labels' => [ 'fr' => 'Aires de jeux' ], 'synonyms' => [ 'fr' => [ 'aire de jeux' ] ] ] );
} );
MII_Concepts::reset();
mii_image( 1100, 1200, 800, [ 'fr' => 'Aire de jeux et famille' ] );
mii_post( 102, '<img class="wp-image-1100">', [ 'lang' => 'fr' ] );
MII_Indexer::index( [ 1100 ] );
MII_Usage::index_posts( [ 102 ] );
$pills = $related->invoke( null, 'playground', 'fr' );
check( 'no family or children pill', ! str_contains( $pills, 'famille' ) && ! str_contains( $pills, 'Enfants' ), $pills );

done();
