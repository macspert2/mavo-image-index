<?php
/**
 * The starter concept dictionary.
 *
 * A concept is here because a visitor-facing feature can use it, not because
 * the word is frequent. Slugs are language-neutral and permanent: consumers
 * store them, so renaming one is a breaking change.
 *
 * Phrase syntax (see MII_Matcher::compile_phrase()):
 *
 *   plage(s)        optional suffix: matches "plage" and "plages"
 *   *strand         compound head: any token ENDING in "strand" (Sandstrand)
 *   türkis*         stem: any token STARTING with "türkis" (türkisfarbenem)
 *   ['phrase', 0.8] a phrase that is weaker evidence than the default 1.0
 *
 * Wildcards need a stem of four letters or more, and a phrase matches whole
 * tokens only, so "mer" never fires inside "merveilleux". Accents are kept,
 * not folded: French côte (coast) and côté (side) must stay different words.
 *
 * 'except' phrases veto any match they overlap: "le tour du lac" is a walk,
 * not a tower. 'implies' adds another concept at 0.9 × this one's confidence.
 * 'private' => true keeps a concept off every public page (see family).
 *
 * Built from domain knowledge first; to be tuned against the exported corpus.
 */

defined( 'ABSPATH' ) || exit;

return [

	/* ------------------------------------------------------------ landscape */

	'beach' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Plage', 'en' => 'Beach', 'de' => 'Strand' ],
		'synonyms' => [
			'fr' => [ 'plage(s)', [ 'crique(s)', 0.8 ] ],
			'en' => [ 'beach(es)', [ 'cove(s)', 0.8 ] ],
			'de' => [ 'strand', 'strände', '*strand', '*strände', [ 'badebucht(en)', 0.8 ] ],
		],
	],

	'sea' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Mer', 'en' => 'Sea', 'de' => 'Meer' ],
		'synonyms' => [
			'fr' => [ 'mer(s)', 'océan(s)', 'méditerranée', 'bord de mer', 'front de mer' ],
			'en' => [ 'sea(s)', 'ocean(s)', 'seaside', 'seafront' ],
			'de' => [ 'meer', '*meer', 'ozean', 'ostsee', 'nordsee', 'meeresblick' ],
		],
	],

	'coast' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Côte', 'en' => 'Coast', 'de' => 'Küste' ],
		'synonyms' => [
			'fr' => [ 'côte(s)', 'littoral', 'côte sauvage', 'côte rocheuse' ],
			'en' => [ 'coast(s)', 'coastline(s)', 'shore(s)', 'shoreline' ],
			'de' => [ 'küste(n)', '*küste(n)', 'küstenlinie' ],
		],
	],

	'cliff' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Falaises', 'en' => 'Cliffs', 'de' => 'Klippen' ],
		'synonyms' => [
			'fr' => [ 'falaise(s)' ],
			'en' => [ 'cliff(s)', 'clifftop(s)', 'cliff top(s)' ],
			'de' => [ 'klippe(n)', '*klippe(n)', 'steilküste(n)', 'felswand', 'felswände' ],
		],
	],

	'mountain' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Montagne', 'en' => 'Mountains', 'de' => 'Berge' ],
		'synonyms' => [
			'fr' => [ 'montagne(s)', 'sommet(s)', 'massif montagneux', 'pic(s)', 'paysage de montagne' ],
			'en' => [ 'mountain(s)', 'peak(s)', 'summit(s)', 'mountainside' ],
			// "*berg" would catch Heidelberg and Nürnberg; a stem is safer.
			'de' => [ 'berg', 'berge', 'berg*', 'gebirge', 'gipfel', '*gipfel', 'alpen' ],
		],
		'except' => [
			'de' => [ 'bergamo', 'bergen' ],
		],
	],

	'lake' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Lac', 'en' => 'Lake', 'de' => 'See' ],
		'synonyms' => [
			'fr' => [ 'lac(s)', 'étang(s)' ],
			'en' => [ 'lake(s)', 'lakeside', 'loch(s)' ],
			'de' => [ 'see', 'seen', '*see', 'seeufer' ],
		],
		// Two German seas end in -see.
		'except' => [
			'de' => [ 'ostsee', 'nordsee' ],
		],
	],

	'island' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Île', 'en' => 'Island', 'de' => 'Insel' ],
		'synonyms' => [
			'fr' => [ 'île(s)', 'îlot(s)' ],
			'en' => [ 'island(s)', 'isle(s)', 'islet(s)' ],
			'de' => [ 'insel(n)', '*insel(n)' ],
		],
		// A peninsula is not an island.
		'except' => [
			'fr' => [ 'presqu île(s)', 'presqu îles' ],
			'de' => [ 'halbinsel(n)' ],
		],
	],

	'river' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Rivière', 'en' => 'River', 'de' => 'Fluss' ],
		'synonyms' => [
			'fr' => [ 'rivière(s)', 'fleuve(s)', 'ruisseau(x)', 'torrent(s)' ],
			'en' => [ 'river(s)', 'riverside', 'stream(s)', 'creek(s)' ],
			'de' => [ 'fluss', 'flüsse', '*fluss', 'flussufer', 'bach' ],
		],
	],

	'forest' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Forêt', 'en' => 'Forest', 'de' => 'Wald' ],
		'synonyms' => [
			'fr' => [ 'forêt(s)', 'sous bois', 'laurisylve', 'laurissilva' ],
			'en' => [ 'forest(s)', 'woods', 'woodland(s)', 'laurisilva', 'laurissilva' ],
			'de' => [ 'wald', 'wälder', '*wald', 'wald*', 'lorbeerwald' ],
		],
	],

	'waterfall' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Cascade', 'en' => 'Waterfall', 'de' => 'Wasserfall' ],
		'synonyms' => [
			'fr' => [ 'cascade(s)', 'chute(s) d eau' ],
			'en' => [ 'waterfall(s)', 'cascade(s)' ],
			'de' => [ 'wasserfall', 'wasserfälle', '*wasserfall', '*wasserfälle' ],
		],
	],

	/* ------------------------------------------------- places / built world */

	'house' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Maisons', 'en' => 'Houses', 'de' => 'Häuser' ],
		'synonyms' => [
			'fr' => [ 'maison(s)', 'maisonnette(s)', 'villa(s)', 'chalet(s)' ],
			'en' => [ 'house(s)', 'cottage(s)', 'villa(s)', 'chalet(s)' ],
			'de' => [ 'haus', 'häuser', 'häuschen', '*häuser', 'ferienhaus', 'bauernhaus', 'fachwerkhaus', 'villa', 'chalet' ],
		],
	],

	'facade' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Façades', 'en' => 'Facades', 'de' => 'Fassaden' ],
		'synonyms' => [
			'fr' => [ 'façade(s)' ],
			'en' => [ 'facade(s)', 'façade(s)' ],
			'de' => [ 'fassade(n)', '*fassade(n)' ],
		],
	],

	'old_town' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Vieille ville', 'en' => 'Old town', 'de' => 'Altstadt' ],
		'synonyms' => [
			'fr' => [ 'vieille(s) ville(s)', 'centre historique', 'quartier historique', 'vieux quartier(s)', 'cité médiévale', 'ville médiévale' ],
			'en' => [ 'old town(s)', 'old city', 'old quarter', 'historic centre', 'historic center', 'historic quarter', 'medieval town' ],
			'de' => [ 'altstadt*', 'historisch* zentrum', 'historisch* stadtkern', 'mittelalterlich* stadt' ],
		],
	],

	'village' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Village', 'en' => 'Village', 'de' => 'Dorf' ],
		'synonyms' => [
			'fr' => [ 'village(s)', 'hameau(x)' ],
			'en' => [ 'village(s)', 'hamlet(s)' ],
			// Not "*dorf": Düsseldorf is not a village.
			'de' => [ 'dorf', 'dörfer', 'dörfchen', 'bergdorf', 'bergdörfer', 'fischerdorf', 'fischerdörfer', 'küstendorf', 'weindorf' ],
		],
	],

	'street' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Ruelles', 'en' => 'Streets', 'de' => 'Gassen' ],
		'synonyms' => [
			'fr' => [ 'rue(s)', 'ruelle(s)', 'venelle(s)' ],
			'en' => [ 'street(s)', 'alley(s)', 'alleyway(s)', 'lane(s)' ],
			'de' => [ 'straße(n)', '*straße(n)', 'gasse(n)', '*gasse(n)', 'gässchen' ],
		],
	],

	'harbour' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Port', 'en' => 'Harbour', 'de' => 'Hafen' ],
		'synonyms' => [
			'fr' => [ 'port(s)', 'marina(s)', 'port de pêche' ],
			'en' => [ 'harbour(s)', 'harbor(s)', 'port(s)', 'marina(s)' ],
			'de' => [ 'hafen', 'häfen', '*hafen' ],
		],
	],

	'garden' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Jardins', 'en' => 'Gardens', 'de' => 'Gärten' ],
		'synonyms' => [
			'fr' => [ 'jardin(s)' ],
			'en' => [ 'garden(s)' ],
			'de' => [ 'garten', 'gärten', '*garten', '*gärten' ],
		],
		'except' => [
			'de' => [ 'kindergarten', 'kindergärten' ],
		],
	],

	'tower' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Tours', 'en' => 'Towers', 'de' => 'Türme' ],
		'synonyms' => [
			// French "tour" is a tower unless masculine — "le tour du lac" is a
			// walk round it. Requiring an article missed most of the corpus
			// ("tour de Londres", "tours de pierre", "tour crénelée"), so the
			// word matches and the masculine uses are vetoed instead.
			'fr' => [ 'tour(s)', 'tour eiffel', 'donjon(s)', 'tour de guet' ],
			'en' => [ 'tower(s)', 'watchtower(s)' ],
			'de' => [ 'turm', 'türme', '*turm', '*türme' ],
		],
		'except' => [
			'fr' => [ 'le tour', 'un tour', 'petit tour', 'ce tour', 'tour du', 'tour en bateau', 'tours en bateau', 'tour à vélo', 'faire le tour' ],
		],
	],

	'castle' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Châteaux', 'en' => 'Castles', 'de' => 'Burgen' ],
		'synonyms' => [
			'fr' => [ 'château(x)', 'château fort', 'forteresse(s)', 'citadelle(s)' ],
			'en' => [ 'castle(s)', 'fortress(es)', 'citadel(s)', 'fort(s)' ],
			// Not "*burg": Hamburg and Salzburg are cities.
			'de' => [ 'burg', 'burgen', 'burgruine(n)', 'schloss', 'schlösser', 'festung(en)', 'zitadelle', 'ritterburg', 'wasserburg' ],
		],
	],

	'church' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Églises', 'en' => 'Churches', 'de' => 'Kirchen' ],
		'synonyms' => [
			'fr' => [ 'église(s)', 'chapelle(s)', 'cathédrale(s)', 'basilique(s)', 'abbaye(s)', 'monastère(s)' ],
			'en' => [ 'church(es)', 'chapel(s)', 'cathedral(s)', 'basilica(s)', 'abbey(s)', 'monastery', 'monasteries' ],
			'de' => [ 'kirche(n)', '*kirche(n)', 'kapelle(n)', '*kapelle(n)', 'kathedrale(n)', 'dom', 'münster', 'basilika', 'kloster', 'klöster', 'abtei' ],
		],
	],

	'landmark' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Monuments', 'en' => 'Landmarks', 'de' => 'Sehenswürdigkeiten' ],
		'synonyms' => [
			'fr' => [ 'monument(s)', [ 'emblématique(s)', 0.8 ], [ 'incontournable(s)', 0.7 ], [ 'bâtiment(s) historique(s)', 0.8 ] ],
			'en' => [ 'landmark(s)', 'monument(s)', [ 'iconic', 0.8 ], [ 'historic building(s)', 0.8 ] ],
			'de' => [ 'wahrzeichen', 'sehenswürdigkeit(en)', 'denkmal', 'denkmäler', 'monument(e)' ],
		],
	],

	/* ------------------------------------------------- visual characteristics */

	'sunset' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Coucher de soleil', 'en' => 'Sunset', 'de' => 'Sonnenuntergang' ],
		'synonyms' => [
			'fr' => [ 'coucher(s) de soleil', 'soleil couchant' ],
			'en' => [ 'sunset(s)' ],
			'de' => [ 'sonnenuntergang', 'sonnenuntergänge', '*sonnenuntergang', 'abendsonne', 'untergehend* sonne' ],
		],
	],

	'panoramic_view' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Vue panoramique', 'en' => 'Panoramic view', 'de' => 'Panoramablick' ],
		'synonyms' => [
			'fr' => [ 'vue(s) panoramique(s)', 'panorama(s)', 'vue imprenable', 'vue plongeante' ],
			'en' => [ 'panoramic view(s)', 'panorama(s)', 'sweeping view(s)' ],
			'de' => [ 'panorama*', '*panorama', 'rundblick', 'weitblick', 'ausblick' ],
		],
	],

	'turquoise_water' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Eaux turquoise', 'en' => 'Turquoise water', 'de' => 'Türkisfarbenes Wasser' ],
		'synonyms' => [
			'fr' => [ 'eau(x) turquoise(s)', 'mer turquoise', 'lagon(s) turquoise(s)', [ 'turquoise(s)', 0.8 ] ],
			'en' => [ 'turquoise water(s)', 'turquoise sea', 'turquoise lagoon', [ 'turquoise', 0.8 ] ],
			'de' => [ 'türkis* wasser', 'türkis* *wasser', 'türkis* meer', 'türkis* see', 'türkis* lagune', [ 'türkis*', 0.8 ] ],
		],
	],

	'blue_sky' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Ciel bleu', 'en' => 'Blue sky', 'de' => 'Blauer Himmel' ],
		'synonyms' => [
			'fr' => [ 'ciel bleu', 'grand ciel bleu', 'ciel d azur' ],
			'en' => [ 'blue sky', 'blue skies', 'clear sky', 'clear skies' ],
			'de' => [ 'blau* himmel', 'strahlend blau* himmel' ],
		],
	],

	'colourful_houses' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Maisons colorées', 'en' => 'Colourful houses', 'de' => 'Bunte Häuser' ],
		'synonyms' => [
			'fr' => [ 'maison(s) colorée(s)', 'façade(s) colorée(s)', 'maisons multicolores', 'façades multicolores', 'maisons aux couleurs vives', 'maisons pastel' ],
			'en' => [ 'colourful house(s)', 'colorful house(s)', 'colourful facade(s)', 'colorful facade(s)', 'brightly coloured house(s)', 'brightly colored house(s)', 'pastel house(s)', 'painted house(s)' ],
			'de' => [ 'bunt* häuser', 'bunt* *häuser', 'bunt* fassaden', 'farbenfroh* häuser', 'farbig* häuser', 'pastellfarben* häuser' ],
		],
		'implies' => [ 'house' ],
	],

	'white_houses' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Maisons blanches', 'en' => 'White houses', 'de' => 'Weiße Häuser' ],
		'synonyms' => [
			'fr' => [ 'maison(s) blanche(s)', 'maisons chaulées', 'maisons blanchies à la chaux', 'village(s) blanc(s)' ],
			'en' => [ 'white house(s)', 'whitewashed house(s)', 'white washed house(s)', 'whitewashed village(s)', 'white village(s)' ],
			'de' => [ 'weiß* häuser', 'weiß* *häuser', 'weiß getünchte* häuser', 'weiß* dorf' ],
		],
		'implies' => [ 'house' ],
	],

	'stone_houses' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Maisons en pierre', 'en' => 'Stone houses', 'de' => 'Steinhäuser' ],
		'synonyms' => [
			'fr' => [ 'maison(s) en pierre(s)', 'maison(s) de pierre(s)', 'bâtisse(s) en pierre(s)' ],
			'en' => [ 'stone house(s)', 'stone cottage(s)', 'stone building(s)' ],
			'de' => [ 'steinhaus', 'steinhäuser', 'natursteinhaus', 'natursteinhäuser', 'häuser aus stein' ],
		],
		'implies' => [ 'house' ],
	],

	/* -------------------------------------------------------------- activities */

	'hiking' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Randonnée', 'en' => 'Hiking', 'de' => 'Wandern' ],
		'synonyms' => [
			'fr' => [ 'randonnée(s)', 'randonneur(s)', 'randonneuse(s)', 'rando(s)', 'trek(s)', 'trekking' ],
			'en' => [ 'hike(s)', 'hiking', 'hiker(s)', 'trek(s)', 'trekking' ],
			'de' => [ 'wanderung(en)', '*wanderung(en)', 'wandern', 'wanderweg(e)', '*wanderweg(e)', 'wanderer', 'wanderin', 'trekking' ],
		],
	],

	'cycling' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Vélo', 'en' => 'Cycling', 'de' => 'Radfahren' ],
		'synonyms' => [
			'fr' => [ 'vélo(s)', 'cyclisme', 'cycliste(s)', 'piste(s) cyclable(s)', 'vtt', 'bicyclette(s)' ],
			'en' => [ 'cycling', 'bike(s)', 'biking', 'bicycle(s)', 'cyclist(s)', 'cycle path(s)', 'bike path(s)' ],
			'de' => [ 'fahrrad', 'fahrräder', 'radfahren', 'radtour(en)', 'radweg(e)', '*radweg(e)', 'radler', 'radlerin', 'mountainbike(s)' ],
		],
	],

	'walking' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Balade', 'en' => 'Walking', 'de' => 'Spaziergang' ],
		'synonyms' => [
			// "marche" only without the accent: marché is a market.
			'fr' => [ 'promenade(s)', 'balade(s)', 'se promener', 'se promènent', 'marche', 'marcher' ],
			'en' => [ 'walk(s)', 'walking', 'stroll(s)', 'strolling' ],
			'de' => [ 'spaziergang', 'spaziergänge', '*spaziergang', 'spazieren' ],
		],
	],

	'boating' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'En bateau', 'en' => 'Boats', 'de' => 'Bootsfahrt' ],
		'synonyms' => [
			'fr' => [ 'bateau(x)', 'barque(s)', 'croisière(s)', 'kayak(s)', 'canoë(s)', 'paddle' ],
			'en' => [ 'boat(s)', 'boat trip(s)', 'boat tour(s)', 'boating', 'kayak(s)', 'kayaking', 'canoe(s)' ],
			'de' => [ 'boot', 'boote', '*boot', '*boote', 'bootsfahrt(en)', 'bootstour(en)', 'kajak(s)', 'kanu(s)' ],
		],
	],

	'sailing' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Voile', 'en' => 'Sailing', 'de' => 'Segeln' ],
		'synonyms' => [
			'fr' => [ 'voilier(s)', 'à la voile', 'catamaran(s)' ],
			'en' => [ 'sailing', 'sailboat(s)', 'sailing boat(s)', 'yacht(s)', 'catamaran(s)' ],
			'de' => [ 'segeln', 'segel*', 'katamaran(e)', 'yacht(en)' ],
		],
		'implies' => [ 'boating' ],
	],

	'swimming' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Baignade', 'en' => 'Swimming', 'de' => 'Baden' ],
		'synonyms' => [
			'fr' => [ 'baignade(s)', 'se baigner', 'se baignent', 'se baignant', 'nager', 'nagent', 'piscine(s)' ],
			'en' => [ 'swimming', 'swim(s)', 'swimmer(s)', 'bathing', 'pool(s)' ],
			'de' => [ 'schwimmen', 'baden', 'badestelle(n)', 'schwimmbad', 'pool(s)' ],
		],
	],

	/* ------------------------------------------------------------------ family
	 * Private (user's decision, 2026-10-05): indexed and queryable by code,
	 * but never on a public surface — no topic page, pill, row, gallery or
	 * link — so the blog's photos of its children cannot be listed in one
	 * place. "family_from_behind" was removed outright: the alt-text rules
	 * write "family from behind" for photos that often show one child.
	 */

	'family' => [
		'group'    => 'family',
		'private'  => true,
		'labels'   => [ 'fr' => 'En famille', 'en' => 'Family', 'de' => 'Familie' ],
		'synonyms' => [
			'fr' => [ 'famille(s)', 'parents' ],
			'en' => [ 'family', 'families', 'parents' ],
			'de' => [ 'familie(n)', 'familien*', 'eltern' ],
		],
	],

	'children' => [
		'group'    => 'family',
		'private'  => true,
		'labels'   => [ 'fr' => 'Enfants', 'en' => 'Children', 'de' => 'Kinder' ],
		'synonyms' => [
			'fr' => [ 'enfant(s)', 'fils', 'fille(s)', 'garçon(s)', 'bébé(s)' ],
			'en' => [ 'child', 'children', 'kid(s)', 'son(s)', 'daughter(s)', 'boy(s)', 'girl(s)', 'baby', 'toddler(s)' ],
			'de' => [ 'kind', 'kinder', 'kinder*', 'sohn', 'söhne', 'tochter', 'töchter', 'junge(n)', 'mädchen', 'baby(s)' ],
		],
		'except' => [
			'de' => [ 'kindergarten', 'kindergärten' ],
		],
	],

	/* -------------------------------------------------------- routes / terrain */

	'coastal_path' => [
		'group'    => 'routes',
		'labels'   => [ 'fr' => 'Sentier côtier', 'en' => 'Coastal path', 'de' => 'Küstenweg' ],
		'synonyms' => [
			'fr' => [ 'sentier(s) côtier(s)', 'chemin(s) côtier(s)', 'sentier du littoral', 'sentier des douaniers' ],
			'en' => [ 'coastal path(s)', 'coast path(s)', 'coastal trail(s)', 'cliff path(s)', 'coastal walk(s)' ],
			'de' => [ 'küstenweg(e)', 'küstenpfad(e)', 'küstenwanderweg(e)' ],
		],
		'implies' => [ 'coast' ],
	],

	'viewpoint' => [
		'group'    => 'routes',
		'labels'   => [ 'fr' => 'Point de vue', 'en' => 'Viewpoint', 'de' => 'Aussichtspunkt' ],
		'synonyms' => [
			'fr' => [ 'point(s) de vue', 'belvédère(s)', 'mirador(s)', 'miradouro(s)' ],
			'en' => [ 'viewpoint(s)', 'lookout(s)', 'viewing platform(s)', 'mirador(s)', 'miradouro(s)' ],
			'de' => [ 'aussichtspunkt(e)', '*aussichtspunkt(e)', 'aussichtsplattform(en)', 'aussichtsturm', 'miradouro(s)' ],
		],
	],

	/* ------------------------------------------- added from the corpus, 2026-10-04
	 * The most frequent unmatched words in image-alt-registry.csv (7,977
	 * images) that a visitor would browse by: bay was 167 German alts,
	 * flowers 123 French, campervans ~85. */

	'bay' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Baies', 'en' => 'Bays', 'de' => 'Buchten' ],
		'synonyms' => [
			'fr' => [ 'baie(s)', 'anse(s)' ],
			'en' => [ 'bay(s)' ],
			'de' => [ 'bucht(en)', '*bucht(en)' ],
		],
	],

	'valley' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Vallées', 'en' => 'Valleys', 'de' => 'Täler' ],
		'synonyms' => [
			'fr' => [ 'vallée(s)', 'vallon(s)' ],
			'en' => [ 'valley(s)' ],
			// Not "*tal": Hospital, Kristall, Metall.
			'de' => [ 'tal', 'täler', 'flusstal', 'bergtal', 'aostatal' ],
		],
	],

	'hills' => [
		'group'    => 'landscape',
		'labels'   => [ 'fr' => 'Collines', 'en' => 'Hills', 'de' => 'Hügel' ],
		'synonyms' => [
			'fr' => [ 'colline(s)', 'vallonné(e)(s)' ],
			'en' => [ 'hill(s)', 'hillside(s)', 'hilltop(s)' ],
			'de' => [ 'hügel', 'hügeln', 'hügellandschaft', 'hügelig*' ],
		],
	],

	'park' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Parcs', 'en' => 'Parks', 'de' => 'Parks' ],
		'synonyms' => [
			'fr' => [ 'parc(s)' ],
			'en' => [ 'park(s)', 'parkland' ],
			'de' => [ 'park(s)', '*park' ],
		],
		'except' => [
			'en' => [ 'car park(s)', 'theme park(s)' ],
			'fr' => [ 'parc d attractions' ],
			'de' => [ 'parkplatz', 'freizeitpark' ],
		],
	],

	'museum' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Musées', 'en' => 'Museums', 'de' => 'Museen' ],
		'synonyms' => [
			'fr' => [ 'musée(s)' ],
			'en' => [ 'museum(s)', 'gallery', 'galleries' ],
			'de' => [ 'museum', 'museen', '*museum', 'galerie' ],
		],
	],

	'bridge' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Ponts', 'en' => 'Bridges', 'de' => 'Brücken' ],
		'synonyms' => [
			'fr' => [ 'pont(s)', 'passerelle(s)' ],
			'en' => [ 'bridge(s)', 'footbridge(s)' ],
			'de' => [ 'brücke(n)', '*brücke(n)' ],
		],
	],

	'market' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Marchés', 'en' => 'Markets', 'de' => 'Märkte' ],
		'synonyms' => [
			'fr' => [ 'marché(s)', 'halles' ],
			'en' => [ 'market(s)', 'market hall(s)' ],
			'de' => [ 'markt', 'märkte', '*markt', 'markthalle(n)' ],
		],
		'except' => [
			'fr' => [ 'bon marché' ],
		],
	],

	'palace' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Palais', 'en' => 'Palaces', 'de' => 'Paläste' ],
		'synonyms' => [
			'fr' => [ 'palais' ],
			'en' => [ 'palace(s)' ],
			'de' => [ 'palast', 'paläste', '*palast', 'palais' ],
		],
	],

	'ruins' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Ruines', 'en' => 'Ruins', 'de' => 'Ruinen' ],
		'synonyms' => [
			'fr' => [ 'ruine(s)', 'vestiges' ],
			'en' => [ 'ruin(s)', 'ruined' ],
			'de' => [ 'ruine(n)', '*ruine(n)' ],
		],
	],

	'lighthouse' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Phares', 'en' => 'Lighthouses', 'de' => 'Leuchttürme' ],
		'synonyms' => [
			'fr' => [ 'phare(s)' ],
			'en' => [ 'lighthouse(s)' ],
			'de' => [ 'leuchtturm', 'leuchttürme' ],
		],
	],

	'canal' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Canaux', 'en' => 'Canals', 'de' => 'Kanäle' ],
		'synonyms' => [
			'fr' => [ 'canal', 'canaux' ],
			'en' => [ 'canal(s)' ],
			'de' => [ 'kanal', 'kanäle', 'gracht(en)' ],
		],
	],

	'square' => [
		'group'    => 'places',
		'labels'   => [ 'fr' => 'Places', 'en' => 'Squares', 'de' => 'Plätze' ],
		'synonyms' => [
			// "place" is also a seat or room: weaker evidence.
			'fr' => [ [ 'place(s)', 0.8 ], 'grandplace', 'plaza' ],
			'en' => [ 'square(s)', 'plaza(s)', 'piazza(s)' ],
			'de' => [ 'platz', 'plätze', '*platz', 'piazza' ],
		],
		'except' => [
			'fr' => [ 'place de parking', 'places de parking', 'sur place' ],
			'en' => [ 'square metre(s)', 'square meter(s)' ],
			'de' => [ 'parkplatz', 'spielplatz', 'campingplatz', 'stellplatz', 'zeltplatz', 'sportplatz', 'parkplätze', 'spielplätze' ],
		],
	],

	'flowers' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Fleurs', 'en' => 'Flowers', 'de' => 'Blumen' ],
		'synonyms' => [
			'fr' => [ 'fleur(s)', 'fleuri(e)(s)', 'lavande' ],
			'en' => [ 'flower(s)', 'flowering', 'blossom(s)', 'lavender' ],
			'de' => [ 'blume(n)', '*blumen', 'blüte(n)', 'blühend*', 'lavendel' ],
		],
	],

	'palm_trees' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Palmiers', 'en' => 'Palm trees', 'de' => 'Palmen' ],
		'synonyms' => [
			'fr' => [ 'palmier(s)', 'palmeraie(s)' ],
			'en' => [ 'palm tree(s)', 'palm lined', 'palms' ],
			'de' => [ 'palme(n)', 'palmen*' ],
		],
	],

	'street_art' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Street art', 'en' => 'Street art', 'de' => 'Street-Art' ],
		'synonyms' => [
			'fr' => [ 'street art', 'fresque(s)', 'graffiti(s)', 'art urbain' ],
			'en' => [ 'street art', 'mural(s)', 'graffiti' ],
			'de' => [ 'street art', 'wandbild*', 'wandmalerei(en)', 'graffiti' ],
		],
	],

	'sculpture' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Sculptures', 'en' => 'Sculptures', 'de' => 'Skulpturen' ],
		'synonyms' => [
			'fr' => [ 'sculpture(s)', 'statue(s)' ],
			'en' => [ 'sculpture(s)', 'statue(s)' ],
			'de' => [ 'skulptur(en)', 'statue(n)', '*statue' ],
		],
	],

	'night' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'De nuit', 'en' => 'By night', 'de' => 'Bei Nacht' ],
		'synonyms' => [
			'fr' => [ 'de nuit', 'la nuit', 'illuminé(e)(s)', 'nocturne(s)' ],
			'en' => [ 'at night', 'by night', 'illuminated', 'lit up' ],
			'de' => [ 'bei nacht', 'nachts', 'beleuchtet*', 'nächtlich*' ],
		],
	],

	'snow' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Neige', 'en' => 'Snow', 'de' => 'Schnee' ],
		'synonyms' => [
			'fr' => [ 'neige', 'enneigé(e)(s)', 'ski' ],
			'en' => [ 'snow', 'snowy', 'snow covered', 'snow capped', 'skiing', 'ski' ],
			'de' => [ 'schnee', 'verschneit*', 'schneebedeckt*', 'ski*' ],
		],
	],

	'autumn' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Automne', 'en' => 'Autumn', 'de' => 'Herbst' ],
		'synonyms' => [
			'fr' => [ 'automne', 'automnal(e)(s)', 'automnaux' ],
			'en' => [ 'autumn', 'autumnal', 'fall foliage' ],
			'de' => [ 'herbst', 'herbstlich*', '*herbst' ],
		],
	],

	'christmas' => [
		'group'    => 'visual',
		'labels'   => [ 'fr' => 'Noël', 'en' => 'Christmas', 'de' => 'Weihnachten' ],
		'synonyms' => [
			'fr' => [ 'noël', 'marché(s) de noël', 'sapin(s) de noël' ],
			'en' => [ 'christmas' ],
			'de' => [ 'weihnacht*', 'advent*' ],
		],
	],

	'campervan' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'En camping-car', 'en' => 'Campervan', 'de' => 'Wohnmobil' ],
		'synonyms' => [
			'fr' => [ 'camping car(s)', 'van(s)', 'fourgon(s) aménagé(s)' ],
			'en' => [ 'campervan(s)', 'camper van(s)', 'motorhome(s)', 'camper(s)' ],
			'de' => [ 'wohnmobil(e)', '*wohnmobil', 'campervan', 'camper' ],
		],
	],

	'food' => [
		'group'    => 'activities',
		'labels'   => [ 'fr' => 'Gastronomie', 'en' => 'Food', 'de' => 'Essen' ],
		'synonyms' => [
			'fr' => [ 'servi(e)(s)', 'assiette(s)', 'dégustation(s)', 'gâteau(x)', 'pâtisserie(s)', 'tapas' ],
			'en' => [ 'served', 'plate of', 'dish(es)', 'cake(s)', 'pastry', 'pastries', 'tapas' ],
			'de' => [ 'serviert', 'teller', 'gericht(e)', 'kuchen', '*kuchen', 'gebäck', 'tapas' ],
		],
	],
];
