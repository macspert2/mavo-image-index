<?php
/** The place gallery ("Madère en images") and the topic × place pages. */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

/* ---------------------------------------------------------------- fixtures */

// Results page.
mii_post( 50, '', [ 'type' => 'page', 'lang' => 'fr' ] );
$GLOBALS['MOCK_TRANSLATIONS'][50] = [ 'fr' => 50 ];
$GLOBALS['MOCK_PERMALINKS']       = [ 50 => 'https://example.test/images/' ];
update_option( MII_Results::PAGE_OPTION, 50 );

// Places: Europe 800 > Portugal 710 > Madère 700 > Funchal 701; Crète 900; Bourg 950.
foreach ( [ 800 => [ 'Europe', 'europe' ], 710 => [ 'Portugal', 'portugal' ], 700 => [ 'Madère', 'madere' ], 701 => [ 'Funchal', 'funchal' ], 900 => [ 'Crète', 'crete' ], 950 => [ 'Bourg', 'bourg' ], 702 => [ 'Madeira', 'madeira' ] ] as $id => [ $name, $slug ] ) {
	mii_term( $name, 'post_tag', $slug, $id );
}
$GLOBALS['MOCK_SUBTREES'] = [ 700 => [ 700, 701 ], 800 => [ 800, 710, 700, 701, 900, 950 ], 950 => [ 950 ] ];

$next_image = 5000; // clear of the post IDs: both live in wp_posts

/** A post with $n images of each alt text, at a place chain. */
function place_post( int $post_id, array $chain, array $images, array $tags = [] ): void {
	global $next_image;

	$content = '';
	foreach ( $images as $alt => $n ) {
		for ( $i = 0; $i < $n; $i++ ) {
			$id = $next_image++;
			mii_image( $id, 1200, 800, [ 'fr' => $alt ] );
			$content .= '<img class="wp-image-' . $id . '">';
		}
	}

	mii_post( $post_id, $content, [ 'lang' => 'fr' ] );
	$GLOBALS['MOCK_CHAINS'][ $post_id ] = $chain;

	foreach ( $tags as $t ) {
		mii_term_rel( $post_id, $t );
	}
}

$madere  = [ [ 'continent', 800 ], [ 'country', 710 ], [ 'region', 700 ] ];
$funchal = array_merge( $madere, [ [ 'city', 701 ] ] );

place_post( 101, $madere, [ 'Montagne' => 4, 'Jardin' => 3, 'Côte rocheuse' => 3, 'Famille' => 4 ], [ 700 ] );
place_post( 102, $funchal, [ 'Montagne' => 2, 'Jardin' => 2, 'Randonnée' => 5, 'Côte rocheuse' => 2, 'Plage' => 1 ], [ 700, 701 ] );
// A round-up tagged Madère, but its own place is Crete: its beaches must not count.
place_post( 103, [ [ 'continent', 800 ], [ 'region', 900 ] ], [ 'Plage' => 6, 'Mer' => 6 ], [ 700, 900 ] );
// The rest of the site.
place_post( 110, [], [ 'Plage' => 25, 'Famille' => 25, 'Maison' => 20 ] );
place_post( 111, [], [ 'Montagne' => 2, 'Jardin' => 2, 'Côte rocheuse' => 2, 'Église' => 15, 'Rue' => 15 ] );
place_post( 112, [ [ 'region', 950 ] ], [ 'Montagne' => 3, 'Jardin' => 3 ], [ 950 ] );

MII_Rebuild::step( 'images', 0, 500 );
MII_Rebuild::step( 'usages', 0, 500 );

/* ----------------------------------------------------------------- topics */

$topics = MII_Place::topics( 700, 'fr' );
$slugs  = array_column( $topics, 'concept' );

same( 'Madère: its distinctive topics', [ 'coast', 'garden', 'hiking', 'mountain' ], ( static function ( $s ) { sort( $s ); return $s; } )( $slugs ) );
check( 'family is never a topic', ! in_array( 'family', $slugs, true ) );
check( 'a round-up tagged Madère but placed in Crete does not count', ! in_array( 'beach', $slugs, true ) && ! in_array( 'sea', $slugs, true ) );
same( 'photo counts are the place’s', 6, array_column( $topics, 'photos', 'concept' )['mountain'] );
check( 'lifts recorded', min( array_column( $topics, 'lift' ) ) >= MII_Place::MIN_LIFT );
same( 'each topic has its own photo', count( $topics ), count( array_unique( array_column( $topics, 'attachment_id' ) ) ) );
check( 'topic photo comes from the place', in_array( array_column( $topics, 'post_id', 'concept' )['hiking'], [ 101, 102 ], true ) );

same( 'a place covering most of the site: no gallery, not even computed', [], MII_Place::topics( 800, 'fr' ) );
same( 'a place with too few photos: no gallery', [], MII_Place::topics( 950, 'fr' ) );
same( 'a place with no photos: no gallery', [], MII_Place::topics( 702, 'fr' ) );

add_filter( 'mavo_image_place_max_share', static fn() => 1.0 );
MII_Cache::bump();
check( 'share threshold is filterable: lifted, Europe gets a gallery too', count( MII_Place::topics( 800, 'fr' ) ) >= MII_Place::MIN_TOPICS );
remove_all_filters( 'mavo_image_place_max_share' );
MII_Cache::bump();

/* ---------------------------------------------------------------- render */

$html = MII_Place::render( 700, 'fr' );

check( 'heading', str_contains( $html, '<h2 class="mavo-image-place__title" id="mavo-image-place-700">Madère en images</h2>' ), $html );
same( 'four overlay tiles', 4, substr_count( $html, '<div class="mv-tile mv-tile--overlay mavo-image-place__tile">' ) );
check( 'tile links to the topic × place page', str_contains( $html, '<a class="mv-tile__link" href="https://example.test/images/montagne/madere/">Montagne</a>' ) || str_contains( $html, 'href="https://example.test/images/?concept=montagne&amp;place=madere">' ) || str_contains( $html, 'href="https://example.test/images/?concept=montagne&place=madere">Montagne</a>' ), $html );
check( 'photo count', str_contains( $html, '<span class="mv-tile__description">6 photos</span>' ) );
same( 'no gallery for a big place', '', MII_Place::render( 800, 'fr' ) );

/* ---------------------------------------------------------- auto insert */

$GLOBALS['MOCK_TAG'] = 700;
ob_start();
MII_Place::auto_insert( 'archive' );
MII_Place::auto_insert( 'archive' );
$printed = ob_get_clean();
same( 'printed once on the tag archive', 1, substr_count( $printed, '<section class="mavo-image-place"' ) );

MII_Place::reset();
ob_start();
MII_Place::auto_insert( 'search' );
same( 'only in the archive loop', '', ob_get_clean() );

$GLOBALS['MOCK_PAGED'] = 1;
ob_start();
MII_Place::auto_insert( 'archive' );
same( 'not on page 2 of the archive', '', ob_get_clean() );
$GLOBALS['MOCK_PAGED'] = 0;

add_filter( 'mavo_image_place_auto', static fn() => false );
ob_start();
MII_Place::auto_insert( 'archive' );
same( 'auto insertion can be switched off', '', ob_get_clean() );
remove_all_filters( 'mavo_image_place_auto' );

check( 'shortcode by slug', str_contains( MII_Place::shortcode( [ 'place' => 'madere' ] ), 'Madère en images' ) );
check( 'shortcode by ID', str_contains( MII_Place::shortcode( [ 'place' => '700' ] ), 'Madère en images' ) );
check( 'shortcode on a tag archive needs no attribute', str_contains( MII_Place::shortcode( [] ), 'Madère en images' ) );
same( 'unknown place', '', MII_Place::shortcode( [ 'place' => 'atlantide' ] ) );
$GLOBALS['MOCK_TAG'] = 0;

/* ------------------------------------------------------- topic × place page */

MII_Results::schedule_refresh();
MII_Results::register_rules();
MII_Cache::bump();

same( 'place URL', 'https://example.test/images/montagne/madere/', MII_Results::url( 'mountain', 'fr', 1, 700 ) );
same( 'place URL, page 2', 'https://example.test/images/montagne/madere/2/', MII_Results::url( 'mountain', 'fr', 2, 700 ) );
same( 'unknown place term: no URL', '', MII_Results::url( 'mountain', 'fr', 1, 99999 ) );

function visit_place( string $concept, string $place, int $num = 0 ): void {
	MII_Results::reset();
	$GLOBALS['MOCK_DID']['wp'] = 1;
	$GLOBALS['MOCK_QUERIED']   = 50;
	$GLOBALS['MOCK_QV']        = [ 'mii_concept' => $concept, 'mii_place' => $place, 'mii_page' => $num ];
	$GLOBALS['MOCK_LANG']      = 'fr';
	$GLOBALS['MOCK_STATUS']    = 200;
	$GLOBALS['wp_query']       = new WP_Query_Stub();
}

visit_place( 'montagne', 'madere' );
same( 'current knows the place', 700, MII_Results::current()['place'] );

$html = MII_Results::shortcode( [] );
check( 'heading names the place', str_contains( $html, '<h2 class="mavo-image-results__title">Madère : Montagne</h2>' ), $html );
check( 'every photo of the place, the count the gallery tile announced', str_contains( $html, '<p class="mavo-image-results__count">2 articles · 6 photos</p>' ), $html );
preg_match_all( '#mv-tile__link" href="https://example.test/\?p=(\d+)"#', $html, $links );
same( 'only the place’s articles', [ '101', '102' ], ( static function ( $l ) { $l = array_values( array_unique( $l ) ); sort( $l ); return $l; } )( $links[1] ) );
check( 'link to the topic everywhere', str_contains( $html, '<a class="mv-badge mv-badge--neutral" href="https://example.test/images/montagne/">Montagne : toutes les destinations</a>' ), $html );
check( 'link back to the place', str_contains( $html, '<a class="mv-badge mv-badge--warm" href="https://example.test/tag/madere/">Madère</a>' ) );

same( 'title', [ 'title' => 'Madère : Montagne – Images' ], MII_Results::title_parts( [ 'title' => 'Images' ] ) );
same( 'canonical is itself', 'https://example.test/images/montagne/madere/', MII_Results::canonical( 'https://example.test/images/' ) );
same( 'noindex, follow', [ 'noindex' => true, 'follow' => true ], MII_Results::robots( [ 'index' => true ] ) );
same( 'Yoast robots', [ 'index' => 'noindex', 'follow' => 'follow' ], MII_Results::seo_plugin_robots( [ 'index' => 'index', 'follow' => 'follow' ] ) );

$GLOBALS['MOCK_TERM_TRANSLATIONS'][700] = [ 'en' => 702 ];
mii_post( 51, '', [ 'type' => 'page', 'lang' => 'en' ] );
$GLOBALS['MOCK_TRANSLATIONS'][50]['en'] = 51;
$GLOBALS['MOCK_PERMALINKS'][51]         = 'https://example.test/en/pictures/';
MII_Results::refresh_paths();
same( 'hreflang: the translated place', 'https://example.test/en/pictures/mountains/madeira/', MII_Results::translation_url( '', 'en' ) );
$GLOBALS['MOCK_TERM_TRANSLATIONS'] = [];
same( 'hreflang: no translated place → the topic there', 'https://example.test/en/pictures/mountains/', MII_Results::translation_url( '', 'en' ) );

visit_place( 'montagne', '' );
same( 'topic pages stay indexable', [ 'index' => true ], MII_Results::robots( [ 'index' => true ] ) );

visit_place( 'montagne', 'atlantide' );
MII_Results::template_redirect();
same( 'unknown place is a 404', 404, $GLOBALS['MOCK_STATUS'] );

MII_Results::reset();
$GLOBALS['MOCK_QV'] = [];
$_GET = [ 'concept' => 'montagne', 'place' => 'madere' ];
same( 'query-string fallback reads the place', 700, MII_Results::current()['place'] );
$_GET = [];

done();
