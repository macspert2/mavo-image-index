<?php
/**
 * Stand-ins for Polylang, mavo-geotag-plus and mavo-hubs, driven by globals.
 * Only the functions the plugin calls.
 */

$GLOBALS['MOCK_LANG']         = 'fr';
$GLOBALS['MOCK_TRANSLATIONS'] = [];   // post_id => [ lang => post_id ]
$GLOBALS['MOCK_CHAINS']       = [];   // post_id => [ [ level, term_id_fr, term_id_en, term_id_de ], … ] continent first
$GLOBALS['MOCK_SUBTREES']     = [];   // term_id => [ term ids ]
$GLOBALS['MOCK_HUB_MEMBERS']  = [];   // hub_id => [ post ids ] direct
$GLOBALS['MOCK_POST_HUBS']    = [];   // post_id => [ hub ids ]

function pll_languages_list( $args = [] ) { return [ 'fr', 'en', 'de' ]; }
function pll_default_language( $field = 'slug' ) { return 'fr'; }
function pll_current_language( $field = 'slug' ) { return $GLOBALS['MOCK_LANG']; }
function pll_get_post( $id, $lang ) { return $GLOBALS['MOCK_TRANSLATIONS'][ $id ][ $lang ] ?? 0; }

function mavo_geo_place_chain( int $post_id, string $lang = '' ): array {
	return array_map( static fn( $p ) => (object) [
		'level'      => $p[0],
		'term_id_fr' => $p[1],
		'term_id_en' => $p[2] ?? 0,
		'term_id_de' => $p[3] ?? 0,
	], $GLOBALS['MOCK_CHAINS'][ $post_id ] ?? [] );
}
function mavo_geo_subtree_terms( int $term_id ): array { return $GLOBALS['MOCK_SUBTREES'][ $term_id ] ?? [ $term_id ]; }

function mavo_get_hubs( int $post_id ): array { return $GLOBALS['MOCK_POST_HUBS'][ $post_id ] ?? []; }
function mavo_get_hub_children( int $hub_id, array $args = [] ): array { return $GLOBALS['MOCK_HUB_MEMBERS'][ $hub_id ] ?? []; }
function mavo_get_hub_descendants( int $hub_id, array $args = [] ): array {
	$out   = [];
	$queue = $GLOBALS['MOCK_HUB_MEMBERS'][ $hub_id ] ?? [];
	while ( $queue ) {
		$id = array_shift( $queue );
		if ( in_array( $id, $out, true ) ) { continue; }
		$out[] = $id;
		$queue = array_merge( $queue, $GLOBALS['MOCK_HUB_MEMBERS'][ $id ] ?? [] );
	}
	return $out;
}

$GLOBALS['MOCK_TERM_TRANSLATIONS'] = []; // term_id => [ lang => term_id ]
function pll_get_term( $id, $lang ) { return $GLOBALS['MOCK_TERM_TRANSLATIONS'][ $id ][ $lang ] ?? 0; }
