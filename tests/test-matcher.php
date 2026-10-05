<?php
/** Concept matching: languages, phrases, boundaries, accents, wildcards, vetoes. */

require __DIR__ . '/harness.php';

/* ------------------------------------------------- the same concepts, three languages */

same( 'fr beach + turquoise', [ 'beach', 'turquoise_water' ], concepts_of( 'Plage de sable aux eaux turquoise', 'fr' ) );
same( 'en beach + turquoise', [ 'beach', 'turquoise_water' ], concepts_of( 'Sandy beach with turquoise water', 'en' ) );
same( 'de beach + turquoise', [ 'beach', 'turquoise_water' ], concepts_of( 'Sandstrand mit türkisfarbenem Wasser', 'de' ) );

same( 'agent.md matcher example',
	[ 'beach', 'cliff', 'turquoise_water' ],
	concepts_of( 'Plage de sable aux eaux turquoise sous les falaises', 'fr' ) );

/* -------------------------------------------------------------- phrase mapping */

// family_from_behind was removed (2026-10-05): "de dos" alone says nothing.
foreach ( [ 'fr' => 'famille de dos', 'en' => 'family seen from behind', 'de' => 'Familie von hinten' ] as $lang => $text ) {
	same( "$lang: just family — $text", [ 'family' ], concepts_of( $text, $lang ) );
}
same( 'a child from behind is not a family', [ 'children' ], concepts_of( 'Enfant de dos sur le sentier', 'fr' ) );
same( '"de dos" alone is nothing', [], concepts_of( 'Vue de dos', 'fr' ) );
check( 'family_from_behind no longer exists', ! MII_Concepts::exists( 'family_from_behind' ) );

/* ---------------------------------------------------------------- boundaries */

same( 'mer not inside merveilleux', [], concepts_of( 'Une vue merveilleuse', 'fr' ) );
same( 'sea not inside seasonal', [ 'market' ], concepts_of( 'Seasonal market stalls', 'en' ) );
same( 'port not inside aéroport or Porto', [], concepts_of( "L'aéroport de Porto", 'fr' ) );
same( 'lac not inside lacet', [], concepts_of( 'Route en lacets', 'fr' ) );
check( 'Hamburg is not a castle', ! in_array( 'castle', concepts_of( 'Hafenrundfahrt in Hamburg', 'de' ), true ) );
same( 'Düsseldorf is not a village', [], concepts_of( 'Altbier in Düsseldorf', 'de' ) );

/* -------------------------------------------------------------------- accents */

same( 'côte is a coast', [ 'coast' ], concepts_of( 'La côte sauvage au soleil', 'fr' ) );
check( 'côté is not a coast', ! in_array( 'coast', concepts_of( "De l'autre côté de la rue", 'fr' ), true ) );
same( 'marché is not a walk (it is a market)', [ 'market' ], concepts_of( 'Le marché du samedi', 'fr' ) );
same( 'a -sur-Mer town is not the sea', [ 'harbour' ], concepts_of( 'Port de Camaret-sur-Mer à marée basse', 'fr' ) );
same( 'but the sea still is', [ 'sea' ], concepts_of( 'Vue sur la mer à Saint-Malo', 'fr' ) );
same( 'la tour de Londres is a tower', [ 'tower' ], concepts_of( 'Tour de Londres au bord de la Tamise', 'fr' ) );
same( 'Parkplatz is not a park or a square', [ 'beach' ], concepts_of( 'Parkplatz am Strand', 'de' ) );
same( 'uppercase accents fold to lowercase', [ 'island' ], concepts_of( 'ÎLE DE PORQUEROLLES', 'fr' ) );
same( 'typographic apostrophe splits elision', [ 'turquoise_water' ], concepts_of( 'Une eau turquoise et l’eau claire', 'fr' ) );
same( 'decomposed é (NFD) still matches', [ 'church' ], concepts_of( "e\u{0301}glise", 'fr' ) );

/* ---------------------------------------------------------- plurals, wildcards */

same( 'explicit plural', [ 'beach' ], concepts_of( 'Les plus belles plages', 'fr' ) );
same( 'English (es) plural', [ 'church' ], concepts_of( 'Two churches', 'en' ) );
same( 'German compound head', [ 'beach' ], concepts_of( 'Kiesstrand im Süden', 'de' ) );
same( 'German stem', [ 'turquoise_water' ], concepts_of( 'Türkises Wasser', 'de' ) );

$m = MII_Matcher::match( 'Kiesstrand', 'de' );
same( 'wildcard match is weaker', 0.9, $m[0]['confidence'] ?? null );

same( 'short stem is not a wildcard', [ [ [ 'abc', 'exact' ] ] ], array_column( MII_Matcher::compile_phrase( 'abc*' ), 'tokens' ) );

/* ---------------------------------------------------------------- vetoes */

same( 'presqu’île is not an island', [], concepts_of( 'La presqu’île de Crozon', 'fr' ) );
same( 'Halbinsel is not an island', [], concepts_of( 'Die Halbinsel', 'de' ) );
same( 'Ostsee is not a lake', [ 'sea' ], concepts_of( 'Ostsee bei Rügen', 'de' ) );
same( 'Kindergarten is not a garden or a child', [], concepts_of( 'Der Kindergarten', 'de' ) );
same( 'le tour du lac is not a tower', [ 'lake' ], concepts_of( 'Le tour du lac', 'fr' ) );
same( 'la tour is a tower', [ 'tower' ], concepts_of( 'La tour de Belém', 'fr' ) );

/* ---------------------------------------------------------------- weights */

$m = MII_Matcher::match( 'crique', 'fr' );
same( 'weighted synonym', 0.8, $m[0]['confidence'] ?? null );

/* ----------------------------------------------------------- registration */

$before = MII_Concepts::version();

add_action( 'mavo_image_register_concepts', static function () {
	mavo_register_image_concept( 'street_art', [
		'labels'   => [ 'fr' => 'Street art', 'en' => 'Street art', 'de' => 'Street-Art' ],
		'synonyms' => [ 'fr' => [ 'street art', 'graffiti(s)', 'fresque(s) murale(s)' ], 'en' => [ 'street art', 'mural(s)' ], 'de' => [ 'street art', 'wandbild*' ] ],
	] );
	mavo_register_image_concept( 'beach', [ 'synonyms' => [ 'fr' => [ 'grève' ] ] ] );
} );
MII_Concepts::reset();

same( 'registered concept matches', [ 'street_art' ], concepts_of( 'Une fresque murale à Lisbonne', 'fr' ) );
same( 'extended concept keeps old synonyms', [ 'beach' ], concepts_of( 'plage', 'fr' ) );
same( 'extended concept gains new synonym', [ 'beach' ], concepts_of( 'La grève', 'fr' ) );
same( 'extension keeps labels', 'Plage', MII_Concepts::label( 'beach', 'fr' ) );
check( 'dictionary version changes with the dictionary', $before !== MII_Concepts::version() );

add_filter( 'mavo_image_concept_definitions', static function ( $defs ) {
	unset( $defs['street_art'] );
	return $defs;
} );
MII_Concepts::reset();
same( 'definitions filter can remove a concept', [], concepts_of( 'A colourful mural', 'en' ) );

/* -------------------------------------------------------------- labels */

same( 'label in language', 'Strand', MII_Concepts::label( 'beach', 'de' ) );
same( 'label for unknown language falls back to current (fr)', 'Plage', MII_Concepts::label( 'beach', 'it' ) );
same( 'label for unknown concept is readable', 'Some thing', MII_Concepts::label( 'some_thing', 'en' ) );

same( 'empty text', [], MII_Matcher::match( '', 'fr' ) );
same( 'unknown language matches nothing', [], MII_Matcher::match( 'plage', 'it' ) );

done();
