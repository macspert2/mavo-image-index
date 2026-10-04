<?php
/**
 * Polylang, mavo-geotag-plus, mavo-hubs, Geo Mashup and mavo-img-srcset all
 * absent: everything still answers, nothing fatals.
 */

require __DIR__ . '/harness.php';

global $wpdb;

$wpdb->query( 'DROP TABLE wp_geo_mashup_locations' );
$wpdb->query( 'DROP TABLE wp_geo_mashup_location_relationships' );

same( 'languages without Polylang', [ 'fr', 'en', 'de' ], MII_Lang::languages() );
same( 'current language falls back to fr', 'fr', MII_Lang::current() );
same( 'fallback alt keys', [ '_wp_attachment_image_alt' => 'fr', '_mavo_alt_en' => 'en', '_mavo_alt_de' => 'de' ], MII_Alt::meta_keys() );

mii_image( 1, 1200, 800, [ 'fr' => 'Plage', 'en' => 'Beach' ] );
mii_post( 10, '<img class="wp-image-1">' );   // no language term at all

MII_Rebuild::step( 'images', 0, 100 );
MII_Rebuild::step( 'usages', 0, 100 );

same( 'post without language is the default language', 'fr', mavo_image_get_usages( 1 )[0]['lang'] ?? null );
same( 'search works', [ 1 ], array_column( mavo_image_search( [ 'concepts' => [ 'beach' ] ] ), 'attachment_id' ) );

$geo = mavo_image_get_geo_context( 1 );
same( 'no geo: unknown', [ 'unknown', 'none', 0.0, null ], [ $geo['precision'], $geo['source'], $geo['confidence'], $geo['place'] ] );

same( 'hub filter without mavo-hubs: nothing, not a fatal', [], mavo_image_search( [ 'hub' => 5 ] ) );
same( 'place filter without geotag-plus: plain tag join', [], mavo_image_search( [ 'place' => 5 ] ) );
same( 'hubs requested without mavo-hubs: omitted', false, isset( mavo_image_search( [ 'concepts' => [ 'beach' ], 'hubs' => true ] )[0]['usages'][0]['hubs'] ) );

MII_Shortcode::init();
same( 'shortcode without Polylang', 'Voir d’autres images similaires', link_text( MII_Shortcode::render( [ 'concept' => 'beach', 'url' => 'https://example.test/x/' ] ) ) );

update_option( MII_Shortcode::TARGETS_OPTION, [ 'beach' => '10' ] );
same( 'page target without Polylang uses the page itself', 'https://example.test/?p=10', MII_Shortcode::target_url( 'beach', 'fr' ) );

mii_image( 2, 0, 0, [] );
$wpdb->query( "DELETE FROM wp_postmeta WHERE post_id = 2" );
$img = mavo_image_get( 2 );
same( 'missing metadata: zero dimensions, no orientation', [ 0, 0, '' ], [ $img['dimensions']['width'], $img['dimensions']['height'], $img['dimensions']['orientation'] ] );

done();
