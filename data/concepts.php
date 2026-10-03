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
			// French "tour" is a tower only when feminine: "le tour" is a trip
			// round something, and Tours is a city. Hence articles.
			'fr' => [ 'la tour', 'une tour', 'les tours', 'des tours', 'cette tour', 'tour de guet', 'tour médiévale', 'tour eiffel', 'donjon(s)' ],
			'en' => [ 'tower(s)', 'watchtower(s)' ],
			'de' => [ 'turm', 'türme', '*turm', '*türme' ],
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
			'fr' => [ 'monument(s)', [ 'emblématique(s)', 0.8 ], [ 'incontournable(s)', 0.7 ] ],
			'en' => [ 'landmark(s)', 'monument(s)', [ 'iconic', 0.8 ] ],
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

	/* ------------------------------------------------------------------ family */

	'family' => [
		'group'    => 'family',
		'labels'   => [ 'fr' => 'En famille', 'en' => 'Family', 'de' => 'Familie' ],
		'synonyms' => [
			'fr' => [ 'famille(s)', 'parents' ],
			'en' => [ 'family', 'families', 'parents' ],
			'de' => [ 'familie(n)', 'familien*', 'eltern' ],
		],
	],

	'children' => [
		'group'    => 'family',
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

	'family_from_behind' => [
		'group'    => 'family',
		'labels'   => [ 'fr' => 'Famille de dos', 'en' => 'Family from behind', 'de' => 'Familie von hinten' ],
		'synonyms' => [
			'fr' => [ 'de dos', 'vu(e)(s) de dos' ],
			'en' => [ 'from behind', 'seen from behind', 'from the back' ],
			'de' => [ 'von hinten', 'rückenansicht', 'von hinten gesehen' ],
		],
		'implies' => [ 'family' ],
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
];
