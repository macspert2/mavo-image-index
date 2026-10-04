<?php
/** The results page: URLs, rules, rendering, SEO, and the shortcode's fallback to it. */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

/* ---------------------------------------------------------------- fixtures */

mii_post( 50, '', [ 'type' => 'page', 'lang' => 'fr' ] );
mii_post( 51, '', [ 'type' => 'page', 'lang' => 'en' ] );
mii_post( 52, '', [ 'type' => 'page', 'lang' => 'de', 'status' => 'draft' ] );
$GLOBALS['MOCK_TRANSLATIONS'][50] = [ 'fr' => 50, 'en' => 51, 'de' => 52 ];
$GLOBALS['MOCK_TRANSLATIONS'][51] = $GLOBALS['MOCK_TRANSLATIONS'][50];
$GLOBALS['MOCK_PERMALINKS']       = [ 50 => 'https://example.test/images/', 51 => 'https://example.test/en/pictures/' ];

// One article with five turquoise images, two more articles with one each.
for ( $i = 1; $i <= 5; $i++ ) {
	mii_image( $i, 1200, 800, [ 'fr' => "Eaux turquoise $i", 'en' => "Turquoise water $i" ] );
}
mii_image( 6, 1200, 800, [ 'fr' => 'Eaux turquoise à Milos' ] );
mii_image( 7, 1200, 800, [ 'fr' => 'Plage aux eaux turquoise' ] );
mii_image( 8, 1200, 800, [ 'fr' => 'Jardin' ] );
mii_image( 9, 1200, 800, [ 'fr' => 'Coucher de soleil' ] );
mii_image( 10, 1200, 800, [ 'fr' => 'Lagon aux eaux turquoise' ] );
mii_image( 11, 1200, 800, [ 'fr' => 'Crique aux eaux turquoise' ] );
mii_image( 12, 1200, 800, [ 'fr' => 'Mer turquoise' ] );

mii_post( 100, '<img class="wp-image-1"><img class="wp-image-2"><img class="wp-image-3"><img class="wp-image-4"><img class="wp-image-5">', [ 'lang' => 'fr' ] );
mii_post( 101, '<img class="wp-image-6"><img class="wp-image-9">', [ 'lang' => 'fr' ] );
mii_post( 102, '<img class="wp-image-7"><img class="wp-image-8">', [ 'lang' => 'fr', 'thumb' => 7 ] );
mii_post( 110, '<img class="wp-image-1">', [ 'lang' => 'en' ] );
// 103: featured AND a content match → only the content one. 104: featured-only
// match also used in 105's content → shown, linked to 105.
mii_post( 103, '<img class="wp-image-10"><img class="wp-image-11">', [ 'lang' => 'fr', 'thumb' => 10, 'date' => '2025-01-01 00:00:00' ] );
mii_post( 104, '', [ 'lang' => 'fr', 'thumb' => 12 ] );
mii_post( 105, '<img class="wp-image-12">', [ 'lang' => 'fr' ] );

MII_Rebuild::step( 'images', 0, 100 );
MII_Rebuild::step( 'usages', 0, 100 );

/* ------------------------------------------------------------------ slugs */

same( 'fr slug', 'eaux-turquoise', MII_Results::slug( 'turquoise_water', 'fr' ) );
same( 'en slug', 'turquoise-water', MII_Results::slug( 'turquoise_water', 'en' ) );
same( 'de slug transliterates umlauts', 'tuerkisfarbenes-wasser', MII_Results::slug( 'turquoise_water', 'de' ) );
same( 'de slug with ß', 'weisse-haeuser', MII_Results::slug( 'white_houses', 'de' ) );
same( 'fr slug drops accents', 'cote', MII_Results::slug( 'coast', 'fr' ) );

foreach ( MII_Lang::languages() as $lang ) {
	$seen = [];
	foreach ( MII_Concepts::slugs() as $concept ) {
		$slug = MII_Results::slug( $concept, $lang );
		check( "$lang slug unique: $slug", ! isset( $seen[ $slug ] ), [ $concept, $seen[ $slug ] ?? null ] );
		$seen[ $slug ] = $concept;
		same( "$lang slug round-trips: $concept", $concept, MII_Results::concept_from_slug( $slug, $lang ) );
	}
}
same( 'concept slug also accepted', 'turquoise_water', MII_Results::concept_from_slug( 'turquoise-water', 'fr' ) );
same( 'unknown slug', null, MII_Results::concept_from_slug( 'volcan', 'fr' ) );

/* ------------------------------------------------------------ URLs, rules */

same( 'no page configured: no URL', '', MII_Results::url( 'turquoise_water', 'fr' ) );

update_option( MII_Results::PAGE_OPTION, 50 );
same( 'before rules: query string', 'https://example.test/images/?concept=eaux-turquoise', MII_Results::url( 'turquoise_water', 'fr' ) );

MII_Results::schedule_refresh();
MII_Results::register_rules();
same( 'paths from real permalinks, unpublished translation skipped',
	[ 50 => [ 'path' => 'images', 'lang' => 'fr' ], 51 => [ 'path' => 'en/pictures', 'lang' => 'en' ] ],
	get_option( MII_Results::PATHS_OPTION ) );
same( 'rules', [
	'^images/([^/]+)(?:/([0-9]+))?/?$'      => 'index.php?page_id=50&mii_concept=$matches[1]&mii_page=$matches[2]&lang=fr',
	'^en/pictures/([^/]+)(?:/([0-9]+))?/?$' => 'index.php?page_id=51&mii_concept=$matches[1]&mii_page=$matches[2]&lang=en',
], $GLOBALS['MOCK_RULES'] );
same( 'flushed once', 1, $GLOBALS['MOCK_FLUSHES'] );

$GLOBALS['MOCK_RULES'] = [];
MII_Results::register_rules();
same( 'normal requests do not flush', 1, $GLOBALS['MOCK_FLUSHES'] );
same( 'but still register', 2, count( $GLOBALS['MOCK_RULES'] ) );

MII_Results::schedule_refresh();
MII_Results::register_rules();
same( 'unchanged paths do not flush', 1, $GLOBALS['MOCK_FLUSHES'] );

same( 'fr URL', 'https://example.test/images/eaux-turquoise/', MII_Results::url( 'turquoise_water', 'fr' ) );
same( 'en URL', 'https://example.test/en/pictures/turquoise-water/', MII_Results::url( 'turquoise_water', 'en' ) );
same( 'page 2', 'https://example.test/images/eaux-turquoise/2/', MII_Results::url( 'turquoise_water', 'fr', 2 ) );
same( 'untranslated language', '', MII_Results::url( 'turquoise_water', 'de' ) );
same( 'index URL', 'https://example.test/images/', mavo_image_results_url( '', 'fr' ) );

MII_Results::on_save_page( 51 );
check( 'editing a translation schedules a refresh', (bool) get_option( MII_Results::FLUSH_OPTION ) );
MII_Results::refresh_paths();
MII_Results::on_save_page( 999 );
check( 'other pages do not', ! get_option( MII_Results::FLUSH_OPTION ) );

/* --------------------------------------------------- [mavo_image_more] */

same( 'the shortcode now links to the results page',
	'<a class="mavo-image-more mavo-image-more--turquoise-water" href="https://example.test/images/eaux-turquoise/">Voir d’autres plages aux eaux turquoise</a>',
	MII_Shortcode::render( [ 'concept' => 'turquoise_water', 'text' => 'Voir d’autres plages aux eaux turquoise' ] ) );
same( 'and in English to the English page', 'https://example.test/en/pictures/turquoise-water/', MII_Shortcode::target_url( 'turquoise_water', 'en' ) );
same( 'and nothing where no translation exists', '', MII_Shortcode::render( [ 'concept' => 'turquoise_water', 'lang' => 'de' ] ) );

update_option( MII_Shortcode::TARGETS_OPTION, [ 'turquoise_water' => 'https://example.test/custom/' ] );
same( 'an explicit mapping still wins', 'https://example.test/custom/', MII_Shortcode::target_url( 'turquoise_water', 'fr' ) );
update_option( MII_Shortcode::TARGETS_OPTION, [] );

/* ------------------------------------------------------------- routing */

function visit( int $page_id, string $slug = '', int $num = 0, string $lang = 'fr' ): void {
	MII_Results::reset();
	$GLOBALS['MOCK_DID']['wp'] = 1;
	$GLOBALS['MOCK_QUERIED']   = $page_id;
	$GLOBALS['MOCK_QV']        = [ 'mii_concept' => $slug, 'mii_page' => $num ];
	$GLOBALS['MOCK_LANG']      = $lang;
	$GLOBALS['MOCK_STATUS']    = 200;
	$GLOBALS['wp_query']       = new WP_Query_Stub();
}

visit( 50, 'eaux-turquoise' );
same( 'current', [ 'page_id' => 50, 'lang' => 'fr', 'concept' => 'turquoise_water', 'page' => 1, 'unknown' => false ], MII_Results::current() );

visit( 101 );
same( 'other pages are not the results page', null, MII_Results::current() );

MII_Results::reset();
$GLOBALS['MOCK_DID'] = [];
same( 'nothing known before the query ran', null, MII_Results::current() );

visit( 50, 'volcan' );
MII_Results::template_redirect();
same( 'unknown concept is a 404', [ 404, true ], [ $GLOBALS['MOCK_STATUS'], $GLOBALS['wp_query']->is_404 ] );

visit( 50 );
$_GET['concept'] = 'eaux-turquoise';
same( 'query-string fallback', 'turquoise_water', MII_Results::current()['concept'] );
unset( $_GET['concept'] );

visit( 50, 'eaux-turquoise' );
same( 'no canonical redirect away from a concept URL', false, MII_Results::redirect_canonical( 'https://example.test/images/' ) );
same( 'canonical is the concept URL', 'https://example.test/images/eaux-turquoise/', MII_Results::canonical( 'https://example.test/images/' ) );
same( 'hreflang/switcher points at the same concept', 'https://example.test/en/pictures/turquoise-water/', MII_Results::translation_url( 'https://example.test/en/pictures/', 'en' ) );
same( 'title', [ 'title' => 'Eaux turquoise – Images' ], MII_Results::title_parts( [ 'title' => 'Images' ] ) );
same( 'Yoast title', 'Eaux turquoise – Images | Maman Voyage', MII_Results::seo_title( 'Images | Maman Voyage' ) );
same( 'description', 'Nos photos de voyage en famille : Eaux turquoise.', MII_Results::seo_description( '' ) );

visit( 50 );
same( 'bare page keeps its canonical', 'https://example.test/images/', MII_Results::canonical( 'https://example.test/images/' ) );
same( 'bare page keeps its title', [ 'title' => 'Images' ], MII_Results::title_parts( [ 'title' => 'Images' ] ) );

/* -------------------------------------------------------------- rendering */

$GLOBALS['MOCK_EXCERPTS'] = [
	101 => '<p>Une semaine à Milos,   entre criques et villages.</p>',
	100 => str_repeat( 'Un mot ', 17 ) . 'dernier mot qui dépasse largement la limite des cent trente caractères',
];
visit( 50, 'eaux-turquoise' );
$tiles = MII_Results::tiles( 'turquoise_water', 'fr' );
$per   = array_count_values( array_column( $tiles, 'post_id' ) );
// Place eyebrow: the image's place in the article it links to.
// Eyebrow: country and region of the article's place, never the city.
$GLOBALS['MOCK_CHAINS'][101] = [ [ 'country', 300 ], [ 'region', 301 ] ];
$GLOBALS['MOCK_CHAINS'][103] = [ [ 'continent', 309 ], [ 'country', 310 ], [ 'region', 311 ], [ 'city', 312 ] ];
foreach ( [ 300 => 'Grèce', 301 => 'Milos', 309 => 'Europe', 310 => 'Espagne', 311 => 'Aragon', 312 => 'Torla' ] as $tid => $tname ) {
	mii_term( $tname, 'post_tag', "g$tid", $tid );
}
MII_Usage::index_posts( [ 101, 103 ] );

same( 'at most two images per article', [ 100 => 2, 101 => 1, 102 => 1, 103 => 1, 105 => 1 ], ( static function ( $a ) { ksort( $a ); return $a; } )( $per ) );

$order = array_column( $tiles, 'post_id' );
same( 'featured image never chosen when the article has another match', [ 11 ], array_column( array_column( array_filter( $tiles, static fn( $t ) => 103 === $t['post_id'] ), 'image' ), 'attachment_id' ) );
same( 'image featured elsewhere links to the article using it in content', 12, array_column( array_column( array_filter( $tiles, static fn( $t ) => 105 === $t['post_id'] ), 'image' ), 'attachment_id' )[0] ?? null );
$first = array_values( array_unique( $order ) );
same( 'featured-only article falls back, last of the first round', 102, end( $first ) );
same( 'featured fallback is its featured image', 7, array_values( array_filter( $tiles, static fn( $t ) => 102 === $t['post_id'] ) )[0]['image']['attachment_id'] );
same( 'rounds: every article once before any article twice', $first, array_slice( $order, 0, count( $first ) ) );
same( 'a photo featured-only here but inline elsewhere appears once, under the inline article', 1,
	count( array_filter( $tiles, static fn( $t ) => 12 === $t['image']['attachment_id'] ) ) );

add_filter( 'mavo_image_results_featured_fallback', static fn() => false );
check( 'fallback can be switched off', ! in_array( 102, array_column( MII_Results::tiles( 'turquoise_water', 'fr' ), 'post_id' ), true ) );
remove_all_filters( 'mavo_image_results_featured_fallback' );

$html = MII_Results::shortcode( [] );
check( 'heading', str_contains( $html, '<h2 class="mavo-image-results__title">Eaux turquoise</h2>' ), $html );
check( 'count', str_contains( $html, '6 photos' ) );
same( 'tiles', 6, substr_count( $html, 'class="mv-tile mv-tile--media mavo-image-results__tile"' ) );
check( 'theme grid', str_contains( $html, '<div class="mv-tile-grid mv-grid mv-grid--3 mavo-image-results__grid">' ) );
check( 'theme tile anatomy', str_contains( $html, '<span class="mv-tile__media"><img class="mv-tile__img" src="https://example.test/wp-content/uploads/6-medium_large.jpg" alt="Eaux turquoise à Milos" loading="lazy" decoding="async"></span>' ), $html );
check( 'stretched link to the article', str_contains( $html, '<span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=101">Article 101</a></span>' ), $html );
check( 'alt text in the page language, on the img', str_contains( $html, 'alt="Eaux turquoise à Milos"' ) );
check( 'description is the article excerpt', str_contains( $html, '<a class="mv-tile__link" href="https://example.test/?p=101">Article 101</a></span><span class="mv-tile__description">Une semaine à Milos, entre criques et villages.</span>' ), $html );
preg_match( '#href="https://example.test/\?p=100">Article 100</a></span><span class="mv-tile__description">(.*?)</span>#', $html, $long );
same( 'long excerpt cut on a word, under 130 characters', str_repeat( 'Un mot ', 17 ) . 'dernier…', $long[1] ?? null );
check( 'no excerpt: no description', str_contains( $html, 'href="https://example.test/?p=103">Article 103</a></span></span></div>' ), $html );
check( 'back to the index', str_contains( $html, '<a class="mv-badge mv-badge--warm" href="https://example.test/images/">Toutes les thématiques' ) );
check( 'related: same group, with images, as badges', str_contains( $html, '<span class="mavo-image-results__label">Voir aussi</span> <a class="mv-badge mv-badge--neutral" href="https://example.test/images/coucher-de-soleil/">Coucher de soleil</a></p>' ), $html );
check( 'no pagination for one page', ! str_contains( $html, 'mavo-image-results__pages' ) );
check( 'eyebrow: country, region', str_contains( $html, '<span class="mv-tile__body"><span class="mv-tile__eyebrow">Grèce, Milos</span><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=101">' ), $html );
check( 'eyebrow never the city or continent', str_contains( $html, '<span class="mv-tile__eyebrow">Espagne, Aragon</span><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=103">' ) && ! str_contains( $html, 'Torla' ) && ! str_contains( $html, 'Europe' ), $html );
add_filter( 'mavo_image_tile_eyebrow', static fn( $label, $post_id ) => 103 === $post_id ? 'Torla' : $label, 10, 2 );
check( 'eyebrow is filterable (a location plugin can pick the level)', str_contains( MII_Results::shortcode( [] ), '<span class="mv-tile__eyebrow">Torla</span>' ) );
remove_all_filters( 'mavo_image_tile_eyebrow' );
$GLOBALS['MOCK_CHAINS'][105] = [ [ 'country', 310 ] ];
MII_Usage::index_posts( [ 105 ] );
check( 'country alone when there is no region', str_contains( MII_Results::shortcode( [] ), '<span class="mv-tile__eyebrow">Espagne</span><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=105">' ) );
same( 'geo context exposes country and region', [ 'Espagne', 'Aragon' ], [ mavo_image_get_geo_context( 11, [ 'post_id' => 103 ] )['country']['name'], mavo_image_get_geo_context( 11, [ 'post_id' => 103 ] )['region']['name'] ] );
check( 'no eyebrow without a place', str_contains( $html, '<span class="mv-tile__body"><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=100">' ) );
check( 'stylesheet enqueued', in_array( 'mavo-image-results', $GLOBALS['MOCK_STYLES'] ?? [], true ) );

$html = MII_Results::shortcode( [ 'per_page' => 3 ] );
check( 'pagination', str_contains( $html, 'Page 1 sur 2' ) && str_contains( $html, 'href="https://example.test/images/eaux-turquoise/2/"' ), $html );

visit( 50, 'eaux-turquoise', 2 );
$html = MII_Results::shortcode( [ 'per_page' => 3 ] );
same( 'page 2 tiles', 3, substr_count( $html, 'mavo-image-results__tile' ) );
check( 'previous link', str_contains( $html, 'href="https://example.test/images/eaux-turquoise/">← Page précédente' ) );

visit( 51, 'turquoise-water', 0, 'en' );
$html = MII_Results::shortcode( [] );
check( 'English page shows English usages only', str_contains( $html, '1 photo' ) && str_contains( $html, 'Article 110' ), $html );
check( 'English alt', str_contains( $html, 'alt="Turquoise water 1"' ) );

visit( 50 );
$html = MII_Results::shortcode( [] );
check( 'index: compact text tiles with counts', str_contains( $html, '<div class="mv-tile mv-tile--text mv-tile--compact mavo-image-results__concept"><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/images/eaux-turquoise/">Eaux turquoise</a></span><span class="mv-tile__description"><span class="mv-tile__count">10</span> photos</span></div>' ), $html );
check( 'index: singular', str_contains( $html, '<span class="mv-tile__count">1</span> photo</span>' ) );
check( 'index: compact theme grid', str_contains( $html, '<div class="mv-tile-grid mv-tile-grid--compact">' ) );
check( 'index includes garden', str_contains( $html, '>Jardins</a>' ) );
check( 'index omits empty concepts', ! str_contains( $html, 'Châteaux' ) );

visit( 50, 'volcan' );
same( 'unknown concept renders nothing', '', MII_Results::shortcode( [] ) );

visit( 101 );
same( 'fixed-concept page', 1, substr_count( MII_Results::shortcode( [ 'concept' => 'garden' ] ), '<h2' ) );

visit( 50, 'eaux-turquoise' );
check( 'appended to the page when the shortcode is absent', str_contains( MII_Results::append_to_page( '<p>Intro</p>' ), '<p>Intro</p><div class="mavo-image-results' ) );
same( 'not appended twice', '<p>[mavo_image_results]</p>', MII_Results::append_to_page( '<p>[mavo_image_results]</p>' ) );
visit( 101 );
same( 'not appended elsewhere', '<p>x</p>', MII_Results::append_to_page( '<p>x</p>' ) );

/* ------------------------------------------------------------ browse rows */

same( 'no rows configured: the plain list', 0, substr_count( MII_Results::render_index( 'fr' ), 'mv-shelf' ) );

same( 'rows parsed, unknown and duplicate slugs dropped', [ 'turquoise_water', 'garden', 'beach' ],
	MII_Results::parse_rows( "turquoise_water\nvolcano\ngarden, beach\nturquoise_water" ) );

update_option( MII_Results::ROWS_OPTION, [ 'turquoise_water', 'garden', 'beach' ] );
$GLOBALS['MOCK_REGISTERED'] = [ 'mv-shelf' ];
$GLOBALS['MOCK_SCRIPTS']    = [];

visit( 50 );
$html = MII_Results::shortcode( [] );

same( 'rows below the minimum are left out', 1, substr_count( $html, '<section class="mv-shelf' ) );
check( 'shelf markup', str_contains( $html, '<section class="mv-shelf mavo-image-results__row" aria-labelledby="mavo-image-row-turquoise-water" data-mv-shelf-prev="Précédent" data-mv-shelf-next="Suivant"><div class="mv-shelf__head"><h2 class="mv-shelf__title" id="mavo-image-row-turquoise-water">Eaux turquoise</h2><a class="mv-shelf__more" href="https://example.test/images/eaux-turquoise/">Tout voir</a></div><div class="mv-shelf__viewport"><ul class="mv-shelf__track" tabindex="0" aria-label="Eaux turquoise">' ), $html );

preg_match( '#<ul class="mv-shelf__track".*?</ul>#s', $html, $track );
preg_match_all( '#mv-tile__link" href="https://example.test/\?p=(\d+)"#', $track[0], $links );
$sorted = $links[1];
sort( $sorted );
// 104's only photo (12, its featured image) is the one 105 shows inline, so
// 104 has no photo of its own left: no photo twice in one row.
same( 'every article with a photo of its own, one tile each', [ '100', '101', '102', '103', '105' ], $sorted );
same( 'featured-only articles last', '102', end( $links[1] ) );
check( 'tiles in slides', 5 === substr_count( $track[0], '<li class="mv-shelf__slide"><div class="mv-tile mv-tile--media' ) );
check( 'each tile names its article', 5 === preg_match_all( '/mavo-image-results__tile" data-post-id="\d+"/', $track[0] ) );
check( 'row tile never the featured image when the article has another match', ! str_contains( $track[0], 'uploads/10-medium_large' ) && str_contains( $track[0], 'uploads/11-medium_large' ) );

/* ---- one appearance per article across the whole browse page ---- */

$all = array_column( MII_Results::row_tiles( 'turquoise_water', 'fr' ), 'post_id' );
$cut = array_column( MII_Results::row_tiles( 'turquoise_water', 'fr', 12, 1, [ $all[0], $all[1] ] ), 'post_id' );
check( 'excluded articles are left out of a row', ! array_intersect( [ $all[0], $all[1] ], $cut ), $cut );
same( 'the rest keep their order', array_values( array_intersect( $all, $cut ) ), array_values( array_intersect( $cut, $all ) ) );
same( 'the minimum counts what is left after exclusion', [], MII_Results::row_tiles( 'turquoise_water', 'fr', 12, count( $cut ) + 1, [ $all[0], $all[1] ] ) );

// In the fixtures every garden and beach article is also a turquoise one, so
// with turquoise first those rows are empty and vanish; small rows first.
add_filter( 'mavo_image_results_row_min', static fn() => 1 );
same( 'rows wholly shown above vanish', 1, substr_count( MII_Results::render_rows( 'fr' ), '<section class="mv-shelf' ) );

update_option( MII_Results::ROWS_OPTION, [ 'garden', 'beach', 'turquoise_water' ] );
$html = MII_Results::render_rows( 'fr' );
preg_match_all( '#mv-tile__link" href="https://example.test/\?p=(\d+)"#', $html, $every );
same( 'small rows first: all three rendered', 3, substr_count( $html, '<section class="mv-shelf' ) );
same( 'no article appears in two rows', count( $every[1] ), count( array_unique( $every[1] ) ) );
same( 'and together they still show every article with a photo of its own', [ '100', '101', '102', '103', '105' ],
	( static function ( $l ) { $l = array_unique( $l ); sort( $l ); return array_values( $l ); } )( $every[1] ) );
preg_match_all( '#<ul class="mv-shelf__track".*?</ul>#s', $html, $tracks );
same( 'the first row keeps everything it had', count( MII_Results::row_tiles( 'garden', 'fr', 12, 1 ) ), substr_count( $tracks[0][0], '<li class="mv-shelf__slide">' ) );
update_option( MII_Results::ROWS_OPTION, [ 'turquoise_water', 'garden', 'beach' ] );
remove_all_filters( 'mavo_image_results_row_min' );

visit( 50, 'eaux-turquoise' );
$concept = MII_Results::shortcode( [] );
preg_match_all( '#mv-tile__link" href="https://example.test/\?p=(\d+)"#', $concept, $grid );
check( 'concept pages still show several photos of one article', count( $grid[1] ) > count( array_unique( $grid[1] ) ), implode( ',', $grid[1] ) );
visit( 50 );

/* ---- popularity: same month last year, summed over translations ---- */

global $wpdb;
$wpdb->pdo->exec( 'CREATE TABLE wp_rpp_monthly_snapshots ( post_id INTEGER, snapshot_month TEXT, views INTEGER )' );
MII_Popularity::reset();
$month = MII_Popularity::same_month_last_year();

foreach ( [ [ 101, 50 ], [ 105, 10 ], [ 0, 99999 ] ] as [ $pid, $v ] ) {
	$wpdb->insert( 'wp_rpp_monthly_snapshots', [ 'post_id' => $pid, 'snapshot_month' => $month, 'views' => $v ] );
}
// Another month does not count.
$wpdb->insert( 'wp_rpp_monthly_snapshots', [ 'post_id' => 100, 'snapshot_month' => '2001-01-01', 'views' => 1000 ] );
// 103's English translation (110) had 80 views last year; the group counts.
$tt = mii_term( 'pll_103', 'post_translations', 'pll_103' );
$wpdb->update( 'wp_term_taxonomy', [ 'description' => serialize( [ 'fr' => 103, 'en' => 110 ] ) ], [ 'term_taxonomy_id' => $tt ] );
mii_term_rel( 103, $tt );
mii_term_rel( 110, $tt );
$wpdb->insert( 'wp_rpp_monthly_snapshots', [ 'post_id' => 110, 'snapshot_month' => $month, 'views' => 80 ] );
// Rolling views break the tie between the two with no seasonal data.
mii_meta( 100, 'views', 7 );

same( 'popularity', [ 100 => [ 'season' => 0, 'recent' => 7 ], 101 => [ 'season' => 50, 'recent' => 0 ], 103 => [ 'season' => 80, 'recent' => 0 ] ],
	array_intersect_key( MII_Popularity::for_posts( [ 100, 101, 103 ] ), [ 100 => 1, 101 => 1, 103 => 1 ] ) );

same( 'rows ordered by last year’s views, then recent views; featured-only last',
	[ 103, 101, 105, 100 ], array_slice( array_column( MII_Results::row_tiles( 'turquoise_water', 'fr' ), 'post_id' ), 0, 4 ) );
same( 'limit', 2, count( MII_Results::row_tiles( 'turquoise_water', 'fr', 2 ) ) );
$grid_first = array_values( array_unique( array_column( MII_Results::tile_refs( 'turquoise_water', 'fr' ), 'post_id' ) ) );
same( 'concept grid: same popularity order as the rows', array_column( MII_Results::row_tiles( 'turquoise_water', 'fr' ), 'post_id' ), $grid_first );
same( 'too few articles: no row', [], MII_Results::row_tiles( 'garden', 'fr', 12, 2 ) );
$wpdb->pdo->exec( 'DROP TABLE wp_rpp_monthly_snapshots' );
MII_Popularity::reset();
same( 'without the stats table, recent views still order', 100, MII_Results::row_tiles( 'turquoise_water', 'fr' )[0]['post_id'] );
check( 'theme arrows enqueued', in_array( 'mv-shelf', $GLOBALS['MOCK_SCRIPTS'], true ) );
check( 'no concept list on the browse page', ! str_contains( $html, 'mv-tile-grid--compact' ) && ! str_contains( $html, 'Toutes les thématiques' ) );
check( 'no buttons without the theme', ! str_contains( $html, 'mavo-image-results__cta' ) );

add_filter( 'mavo_hero_cta_row', static fn( $html, $lang ) => '<div class="mv-hero__cta-row"><a class="mv-button" href="#">' . $lang . '</a></div>', 10, 2 );
check( 'hero buttons after the rows, in the page language', str_ends_with( MII_Results::shortcode( [] ), '</section><div class="mavo-image-results__cta"><div class="mv-hero__cta-row"><a class="mv-button" href="#">fr</a></div></div></div>' ) );
remove_all_filters( 'mavo_hero_cta_row' );

same( 'For You block hidden on the results page', false, MII_Results::hide_for_you( true, 50 ) );
same( '… and on its translations', false, MII_Results::hide_for_you( true, 51 ) );
same( 'but not elsewhere', true, MII_Results::hide_for_you( true, 101 ) );

visit( 51, '', 0, 'en' );
$html = MII_Results::shortcode( [] );
same( 'a language without enough articles gets no row', 0, substr_count( $html, 'mv-shelf' ) );
check( '… and falls back to the concept list', str_contains( $html, 'mv-tile-grid--compact' ) );

add_filter( 'mavo_image_results_row_min', static fn() => 1 );
update_option( MII_Results::ROWS_OPTION, [ 'garden', 'beach', 'turquoise_water' ] );
visit( 50 );
same( 'row minimum is filterable', 3, substr_count( MII_Results::shortcode( [] ), '<section class="mv-shelf' ) );
update_option( MII_Results::ROWS_OPTION, [ 'turquoise_water', 'garden', 'beach' ] );
remove_all_filters( 'mavo_image_results_row_min' );

$GLOBALS['MOCK_REGISTERED'] = [];
$GLOBALS['MOCK_SCRIPTS']    = [];
visit( 50 );
check( 'without the theme script the rows still render', str_contains( MII_Results::shortcode( [] ), 'mv-shelf__track' ) && ! $GLOBALS['MOCK_SCRIPTS'] );
update_option( MII_Results::ROWS_OPTION, [] );

/* ------------------------------------------------------- tile excerpts */

$excerpt = new ReflectionMethod( 'MII_Results', 'excerpt' );
mii_post( 600, '', [ 'type' => 'page', 'lang' => 'fr' ] );
mii_meta( 600, '_yoast_wpseo_metadesc', 'Notre guide de la Crète en famille.' );
$GLOBALS['MOCK_EXCERPTS'][600] = '[mv-box] débris de shortcode';
same( 'a page shows its meta description first', 'Notre guide de la Crète en famille.', $excerpt->invoke( null, 600 ) );
mii_meta( 600, '_yoast_wpseo_metadesc', '%%excerpt%% %%sep%% %%sitename%%' );
same( 'a Yoast template is not text', '[mv-box] débris de shortcode', $excerpt->invoke( null, 600 ) );
mii_post( 601, '', [ 'lang' => 'fr' ] );
mii_meta( 601, '_yoast_wpseo_metadesc', 'Description SEO.' );
same( 'a post without an excerpt falls back to the meta description', 'Description SEO.', $excerpt->invoke( null, 601 ) );
same( 'unknown post', '', $excerpt->invoke( null, 999999 ) );

same( 'concept counts', [ 'beach' => 2, 'garden' => 1, 'sea' => 1, 'sunset' => 1, 'turquoise_water' => 10 ], ( static function ( $c ) { ksort( $c ); return $c; } )( mavo_image_concept_counts( 'fr' ) ) );

/* ---- one row on its own, for another page (mavo-search) ---- */

$row = mavo_image_concept_row( 'turquoise_water', 'fr', [ 'title' => 'Eaux turquoise en photos', 'limit' => 2, 'min' => 1, 'class' => 'mv-search-photos x"y' ] );
check( 'concept row: a shelf with the given title', str_contains( $row, '<section class="mv-shelf mavo-image-results__row mv-search-photos xy"' ) && str_contains( $row, '>Eaux turquoise en photos</h2>' ), $row );
same( 'concept row: limit', 2, substr_count( $row, '<li class="mv-shelf__slide">' ) );
same( 'concept row: too few articles, nothing', '', mavo_image_concept_row( 'turquoise_water', 'fr', [ 'min' => 99 ] ) );
same( 'concept row: unknown concept, nothing', '', mavo_image_concept_row( 'no_such_concept', 'fr' ) );
check( 'concept row: default title is the label', str_contains( mavo_image_concept_row( 'turquoise_water', 'fr', [ 'min' => 1 ] ), '>' . MII_Concepts::label( 'turquoise_water', 'fr' ) . '</h2>' ) );

done();
