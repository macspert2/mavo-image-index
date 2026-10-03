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

visit( 50, 'eaux-turquoise' );
$tiles = MII_Results::tiles( 'turquoise_water', 'fr' );
$per   = array_count_values( array_column( $tiles, 'post_id' ) );
// Place eyebrow: the image's place in the article it links to.
$GLOBALS['MOCK_CHAINS'][101] = [ [ 'country', 300 ], [ 'region', 301 ] ];
mii_term( 'Milos', 'post_tag', 'milos', 301 );
MII_Usage::index_posts( [ 101 ] );

same( 'at most two images per article', [ 100 => 2, 101 => 1, 102 => 1, 103 => 1, 105 => 1 ], ( static function ( $a ) { ksort( $a ); return $a; } )( $per ) );

$order = array_column( $tiles, 'post_id' );
same( 'featured image never chosen when the article has another match', [ 11 ], array_column( array_column( array_filter( $tiles, static fn( $t ) => 103 === $t['post_id'] ), 'image' ), 'attachment_id' ) );
same( 'image featured elsewhere links to the article using it in content', 12, array_column( array_column( array_filter( $tiles, static fn( $t ) => 105 === $t['post_id'] ), 'image' ), 'attachment_id' )[0] ?? null );
same( 'featured-only article falls back, last', 102, end( $order ) );
same( 'featured fallback is its featured image', 7, end( $tiles )['image']['attachment_id'] );

add_filter( 'mavo_image_results_featured_fallback', static fn() => false );
check( 'fallback can be switched off', ! in_array( 102, array_column( MII_Results::tiles( 'turquoise_water', 'fr' ), 'post_id' ), true ) );
remove_all_filters( 'mavo_image_results_featured_fallback' );

$html = MII_Results::shortcode( [] );
check( 'heading', str_contains( $html, '<h2 class="mavo-image-results__title">Eaux turquoise</h2>' ), $html );
check( 'count', str_contains( $html, '6 photos' ) );
same( 'tiles', 6, substr_count( $html, 'class="mv-tile mv-tile--media mavo-image-results__tile"' ) );
check( 'theme grid', str_contains( $html, '<div class="mv-tile-grid mv-grid mv-grid--3 mavo-image-results__grid">' ) );
check( 'theme tile anatomy', str_contains( $html, '<span class="mv-tile__media"><img class="mv-tile__img" src="https://example.test/wp-content/uploads/6-medium_large.jpg" alt="" loading="lazy" decoding="async"></span>' ), $html );
check( 'stretched link to the article', str_contains( $html, '<span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=101">Article 101</a></span>' ), $html );
check( 'alt text in the page language, as the description', str_contains( $html, '<span class="mv-tile__description">Eaux turquoise à Milos</span>' ) );
check( 'back to the index', str_contains( $html, '<a class="mv-badge mv-badge--warm" href="https://example.test/images/">Toutes les thématiques' ) );
check( 'related: same group, with images, as badges', str_contains( $html, '<span class="mavo-image-results__label">Voir aussi</span> <a class="mv-badge mv-badge--neutral" href="https://example.test/images/coucher-de-soleil/">Coucher de soleil</a></p>' ), $html );
check( 'no pagination for one page', ! str_contains( $html, 'mavo-image-results__pages' ) );
check( 'place as eyebrow', str_contains( $html, '<span class="mv-tile__body"><span class="mv-tile__eyebrow">Milos</span><span class="mv-tile__title"><a class="mv-tile__link" href="https://example.test/?p=101">' ), $html );
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
check( 'English alt', str_contains( $html, '<span class="mv-tile__description">Turquoise water 1</span>' ) );

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
same( 'every matching article, one tile each', [ '100', '101', '102', '103', '104', '105' ], $sorted );
same( 'featured-only articles last', [ '102', '104' ], ( static function ( $l ) { $t = array_slice( $l, -2 ); sort( $t ); return $t; } )( $links[1] ) );
check( 'tiles in slides', 6 === substr_count( $track[0], '<li class="mv-shelf__slide"><div class="mv-tile mv-tile--media' ) );
check( 'row tile never the featured image when the article has another match', ! str_contains( $track[0], 'uploads/10-medium_large' ) && str_contains( $track[0], 'uploads/11-medium_large' ) );

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
visit( 50 );
same( 'row minimum is filterable', 3, substr_count( MII_Results::shortcode( [] ), '<section class="mv-shelf' ) );
remove_all_filters( 'mavo_image_results_row_min' );

$GLOBALS['MOCK_REGISTERED'] = [];
$GLOBALS['MOCK_SCRIPTS']    = [];
visit( 50 );
check( 'without the theme script the rows still render', str_contains( MII_Results::shortcode( [] ), 'mv-shelf__track' ) && ! $GLOBALS['MOCK_SCRIPTS'] );
update_option( MII_Results::ROWS_OPTION, [] );

same( 'concept counts', [ 'beach' => 2, 'garden' => 1, 'sea' => 1, 'sunset' => 1, 'turquoise_water' => 10 ], ( static function ( $c ) { ksort( $c ); return $c; } )( mavo_image_concept_counts( 'fr' ) ) );

done();
