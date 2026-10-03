<?php
/** [mavo_image_more]: targets, languages, failure modes, escaping. */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

MII_Shortcode::init();

function sc( array $atts ): string {
	return MII_Shortcode::render( $atts );
}

/* ------------------------------------------------------------ no target */

same( 'no target, no link', '', sc( [ 'concept' => 'turquoise_water' ] ) );
same( 'missing concept', '', sc( [ 'url' => 'https://example.test/x/' ] ) );
same( 'unknown concept', '', sc( [ 'concept' => 'volcano', 'url' => 'https://example.test/x/' ] ) );
same( 'unsafe url', '', sc( [ 'concept' => 'beach', 'url' => 'javascript:alert(1)' ] ) );

/* ------------------------------------------------------------ explicit url */

same( 'explicit url, default text in current language',
	'<a class="mavo-image-more mavo-image-more--turquoise-water" href="https://example.test/x/">Voir d’autres images similaires</a>',
	sc( [ 'concept' => 'turquoise_water', 'url' => 'https://example.test/x/' ] ) );

same( 'custom text, escaped',
	'<a class="mavo-image-more mavo-image-more--beach extra bad" href="https://example.test/x/">Plages &lt;b&gt; &amp; criques</a>',
	sc( [ 'concept' => 'beach', 'url' => 'https://example.test/x/', 'text' => 'Plages <b> & criques', 'class' => 'extra "bad' ] ) );

same( 'German default text', 'Ähnliche Bilder entdecken', strip_tags( sc( [ 'concept' => 'beach', 'url' => 'https://example.test/x/', 'lang' => 'de' ] ) ) );
same( 'unsupported language falls back to current', 'Voir d’autres images similaires', strip_tags( sc( [ 'concept' => 'beach', 'url' => 'https://example.test/x/', 'lang' => 'it' ] ) ) );

$GLOBALS['MOCK_LANG'] = 'en';
same( 'current language is Polylang’s', 'See more like this', strip_tags( sc( [ 'concept' => 'beach', 'url' => 'https://example.test/x/' ] ) ) );
$GLOBALS['MOCK_LANG'] = 'fr';

/* -------------------------------------------------------- mapped targets */

mii_post( 500, '', [ 'type' => 'page', 'lang' => 'fr' ] );
mii_post( 501, '', [ 'type' => 'page', 'lang' => 'en' ] );
$GLOBALS['MOCK_TRANSLATIONS'][500] = [ 'fr' => 500, 'en' => 501 ];

update_option( MII_Shortcode::TARGETS_OPTION, MII_Shortcode::parse_targets(
	"# comment\nturquoise_water = 500\nbeach@de = https://example.test/de/strand/\n* = https://example.test/?s={label}&l={lang}\nnot a line\n= 3"
) );

same( 'parsed targets', [ 'turquoise_water' => '500', 'beach@de' => 'https://example.test/de/strand/', '*' => 'https://example.test/?s={label}&l={lang}' ], MII_Shortcode::targets() );

same( 'page target, same language', 'https://example.test/?p=500', MII_Shortcode::target_url( 'turquoise_water', 'fr' ) );
same( 'page target, translated', 'https://example.test/?p=501', MII_Shortcode::target_url( 'turquoise_water', 'en' ) );
same( 'untranslated page target gives the generic one, not French',
	'https://example.test/?s=T%C3%BCrkisfarbenes%20Wasser&l=de', MII_Shortcode::target_url( 'turquoise_water', 'de' ) );
same( 'language-specific mapping', 'https://example.test/de/strand/', MII_Shortcode::target_url( 'beach', 'de' ) );
same( 'generic with placeholders', 'https://example.test/?s=Plage&l=fr', MII_Shortcode::target_url( 'beach', 'fr' ) );
same( 'explicit url beats mapping', 'https://example.test/y/', MII_Shortcode::target_url( 'turquoise_water', 'fr', 'https://example.test/y/' ) );

$wpdb->update( 'wp_posts', [ 'post_status' => 'draft' ], [ 'ID' => 501 ] );
same( 'unpublished translation is not linked', 'https://example.test/?s=Turquoise%20water&l=en', MII_Shortcode::target_url( 'turquoise_water', 'en' ) );

/* ------------------------------------------------------------------ filter */

add_filter( 'mavo_image_more_url', static fn( $url, $concept, $lang, $context ) => 'sunset' === $concept ? 'https://example.test/sunsets/' . $context['post_id'] : $url, 10, 4 );
$GLOBALS['MOCK_CURRENT_POST'] = 42;
same( 'filter supplies a target, with context', 'https://example.test/sunsets/42', MII_Shortcode::target_url( 'sunset', 'fr', '', [ 'post_id' => 42 ] ) );
check( 'filter sees the shortcode context', str_contains( sc( [ 'concept' => 'sunset' ] ), 'sunsets/42' ) );

update_option( MII_Shortcode::TARGETS_OPTION, [] );
add_filter( 'mavo_image_more_url', static fn() => '', 20 );
same( 'filter can veto', '', sc( [ 'concept' => 'sunset' ] ) );
remove_all_filters( 'mavo_image_more_url' );

/* --------------------------------------------------------------- image_id */

mii_image( 7, 1200, 800, [ 'fr' => 'Plage' ] );
MII_Indexer::index( [ 7 ] );

check( 'image_id without the concept still renders by default', '' !== sc( [ 'concept' => 'sunset', 'image_id' => 7, 'url' => 'https://example.test/x/' ] ) );
add_filter( 'mavo_image_more_strict', '__return_true' );
function __return_true() { return true; }
same( 'strict: concept must be on the image', '', sc( [ 'concept' => 'sunset', 'image_id' => 7, 'url' => 'https://example.test/x/' ] ) );
check( 'strict: matching concept renders', '' !== sc( [ 'concept' => 'beach', 'image_id' => 7, 'url' => 'https://example.test/x/' ] ) );

/* -------------------------------------------------------------- more_text */

add_action( 'mavo_image_register_concepts', static function () {
	mavo_register_image_concept( 'turquoise_water', [ 'more_text' => [ 'fr' => 'Voir d’autres plages aux eaux turquoise' ] ] );
} );
MII_Concepts::reset();
same( 'concept-specific default text', 'Voir d’autres plages aux eaux turquoise', strip_tags( sc( [ 'concept' => 'turquoise_water', 'url' => 'https://example.test/x/' ] ) ) );

check( 'no nofollow, no target=_blank, no script', ! preg_match( '/nofollow|_blank|<script/', sc( [ 'concept' => 'beach', 'url' => 'https://example.test/x/' ] ) ) );

done();
