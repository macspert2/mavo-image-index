<?php
/**
 * Stable procedural API for other mavo-* plugins.
 *
 * This is the contract. Nothing outside this plugin should name a table, a
 * class, or a meta key from it; a consumer guards with function_exists() and
 * degrades when the plugin is off, per sharing-between-plugins.md:
 *
 *   if ( function_exists( 'mavo_image_best_match' ) ) {
 *       $image = mavo_image_best_match( [ 'post_ids' => [ $id ], 'concepts' => [ 'hiking' ] ] );
 *   }
 *
 * Language: wherever $lang is optional, null means Polylang's current
 * language, else the default (fr). Unknown languages are treated as null.
 *
 * Concepts are facts about the picture and are language-neutral: evidence
 * from any language's alt text counts. Language decides labels, which alt
 * text is returned, and — in searches — which posts' usages count.
 *
 * Geography is approximate and always says so: every context carries
 * 'precision', 'source' and 'confidence' (see MII_Geo for the ladder).
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------- one image */

/**
 * Everything known about one image. [] when the ID is not an image.
 *
 * @param array $args lang (?string), post_id (int: prefer this usage's geo),
 *                    hubs (bool: add mavo-hubs memberships to each usage)
 * @return array{attachment_id:int,url:string,mime_type:string,alt:array,alt_text:string,
 *               alt_lang:?string,concepts:array,dimensions:array,usages:array,
 *               featured_for:int[],geo:array,dates:array,indexed:bool}|array{}
 */
function mavo_image_get( int $attachment_id, array $args = [] ): array {
	$args['lang'] = MII_Lang::resolve( $args['lang'] ?? null );
	$key          = 'image:' . $attachment_id . ':' . md5( serialize( $args ) );
	$cached       = MII_Cache::get( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$image = MII_Images::get_many( [ $attachment_id ], $args )[ $attachment_id ] ?? [];

	MII_Cache::set( $key, $image );

	return $image;
}

/**
 * Alt text in one language, exactly as curated. No fallback to another
 * language: null means there is none in this one.
 */
function mavo_image_get_alt( int $attachment_id, ?string $lang = null ): ?string {
	return MII_Alt::get( $attachment_id, MII_Lang::resolve( $lang ) );
}

/**
 * The image's concepts, labelled in $lang, strongest first.
 *
 * @return array<int,array{slug:string,label:string,source:string,confidence:float,langs:string[],sources:string[]}>
 */
function mavo_image_get_concepts( int $attachment_id, ?string $lang = null ): array {
	return MII_Images::concepts_for( [ $attachment_id ], MII_Lang::resolve( $lang ) )[ $attachment_id ] ?? [];
}

/**
 * Does the image show this concept?
 *
 * With $lang, only evidence from that language's alt text counts — useful to
 * check a link label written in that language. Without it, any language.
 */
function mavo_image_has_concept( int $attachment_id, string $concept, ?string $lang = null ): bool {
	$concept  = MII_Concepts::sanitize_slug( $concept );
	$evidence = null === $lang ? null : MII_Lang::normalize( $lang );

	if ( null !== $lang && null === $evidence ) {
		return false;
	}

	foreach ( MII_Images::concepts_for( [ $attachment_id ], null, $evidence )[ $attachment_id ] ?? [] as $found ) {
		if ( $found['slug'] === $concept ) {
			return true;
		}
	}

	return false;
}

/**
 * Where the image is used.
 *
 * @param array $args lang (?string: only usages in posts of this language;
 *                    default all), role (?string)
 * @return array<int,array{post_id:int,lang:string,role:string,source:string,position:int,post_date:?string}>
 */
function mavo_image_get_usages( int $attachment_id, array $args = [] ): array {
	$lang = isset( $args['lang'] ) ? MII_Lang::normalize( (string) $args['lang'] ) : null;
	$rows = MII_Usage::for_attachments( [ $attachment_id ], $lang )[ $attachment_id ] ?? [];
	$out  = [];

	foreach ( $rows as $row ) {
		if ( ! empty( $args['role'] ) && $row['role'] !== $args['role'] ) {
			continue;
		}

		$out[] = [
			'post_id'   => (int) $row['post_id'],
			'lang'      => (string) $row['lang'],
			'role'      => (string) $row['role'],
			'source'    => (string) $row['source'],
			'position'  => (int) $row['position'],
			'post_date' => $row['post_date'] ?? null,
		];
	}

	return $out;
}

/**
 * Approximate geography of an image, inherited from the posts that use it.
 *
 * Returns the preferred context — the one from $args['post_id'] when that
 * post uses the image — with every candidate under 'candidates'. With no
 * usable geography: precision 'unknown', confidence 0.
 *
 * @param array $args post_id (int), lang (?string: only usages in this language)
 * @return array{lat:?float,lng:?float,precision:string,source:string,confidence:float,
 *               post_id:?int,lang:?string,place:?array,candidates:array}
 */
function mavo_image_get_geo_context( int $attachment_id, array $args = [] ): array {
	$lang  = isset( $args['lang'] ) ? MII_Lang::normalize( (string) $args['lang'] ) : null;
	$rows  = MII_Usage::for_attachments( [ $attachment_id ] )[ $attachment_id ] ?? [];
	$names = MII_Geo::place_names( array_column( $rows, 'geo_place' ) );

	$context = MII_Geo::summarize( $rows, [ 'post_id' => (int) ( $args['post_id'] ?? 0 ), 'lang' => $lang ], $names );

	/** One image's geography: the preferred context and its candidates. */
	return (array) apply_filters( 'mavo_image_geo_context', $context, $attachment_id, $args );
}

/* ---------------------------------------------------------------- search */

/**
 * Images matching concepts, text, dimensions, usage and context.
 *
 * @param array $args {
 *     @type string      $lang                      Default current language.
 *     @type string[]    $concepts                  Concept slugs.
 *     @type string      $concept_operator          'AND' (default) | 'OR'.
 *     @type string[]    $exclude_concepts
 *     @type string      $text                      Whole-word phrase in $lang's alt text.
 *     @type int         $place                     mavo-geotag-plus place (post_tag term ID).
 *     @type bool        $include_place_descendants Default true.
 *     @type int         $hub                       mavo-hubs hub post ID.
 *     @type bool        $include_hub_descendants   Default false: direct members only.
 *     @type int[]       $post_ids                  Only images used in these posts.
 *     @type int[]       $exclude                   Attachment IDs to leave out.
 *     @type bool        $same_language             Default true: only images used in a
 *                                                  post of $lang. False includes unused images.
 *     @type string      $orientation               landscape | portrait | square.
 *     @type int         $min_width, $min_height
 *     @type bool|null   $featured                  true: is a featured image; false: is not.
 *     @type string      $role                      featured | content | gallery | hero.
 *     @type bool        $prefer_featured           Rank featured images higher.
 *     @type string      $after, $before            Attachment upload date bounds.
 *     @type int         $limit                     Default 20, max 100.
 *     @type int         $offset
 *     @type string      $orderby                   relevance | date | random | id.
 *     @type string      $order                     DESC | ASC (not for relevance).
 *     @type int         $seed                      For random: same seed, same order.
 *                                                  Default changes daily.
 *     @type bool        $hubs                      Add mavo-hubs memberships to usages.
 * }
 * @return array[] Image objects (see mavo_image_get) plus score and matched_concepts.
 */
function mavo_image_search( array $args = [] ): array {
	return MII_Search::run( $args );
}

/** The single best image for a context, or null. Same arguments as search. */
function mavo_image_best_match( array $args = [] ): ?array {
	$args['limit']   = 1;
	$args['offset']  = 0;
	$args['orderby'] = $args['orderby'] ?? 'relevance';

	return MII_Search::run( $args )[0] ?? null;
}

/* ------------------------------------------------------------ dictionary */

/**
 * Add a concept, or extend an existing one (synonyms append, labels replace).
 * Call it on the mavo_image_register_concepts action.
 *
 * @param array $definition labels, synonyms, except (per language), implies
 *                          (slugs), more_text (per language), group
 */
function mavo_register_image_concept( string $slug, array $definition ): void {
	MII_Concepts::register( $slug, $definition );
}

/**
 * Every concept: slug => [ label, group ], labels in $lang.
 *
 * @return array<string,array{label:string,group:string}>
 */
function mavo_image_concepts( ?string $lang = null ): array {
	$out = [];

	foreach ( MII_Concepts::all() as $slug => $def ) {
		$out[ $slug ] = [ 'label' => MII_Concepts::label( $slug, $lang ), 'group' => $def['group'] ];
	}

	return $out;
}

/**
 * How many images carry each concept, among images used in posts of $lang.
 *
 * @return array<string,int> slug => images; concepts with none are absent
 */
function mavo_image_concept_counts( ?string $lang = null ): array {
	return MII_Search::concept_counts( MII_Lang::resolve( $lang ) );
}

/**
 * The results-page URL for a concept in a language ('' for the page itself).
 * '' when no results page is configured or translated into that language.
 */
function mavo_image_results_url( string $concept = '', ?string $lang = null, int $page = 1 ): string {
	return MII_Results::url( MII_Concepts::sanitize_slug( $concept ), MII_Lang::resolve( $lang ), $page );
}

/* ------------------------------------------------------------ maintenance */

/** Reindex one image now. Returns indexed | unchanged | removed | failed. */
function mavo_image_reindex_attachment( int $attachment_id ): string {
	return MII_Indexer::index( [ $attachment_id ], true )[ $attachment_id ] ?? 'removed';
}

/** Re-extract one post's image usages (and their geography) now. */
function mavo_image_reindex_post_usages( int $post_id ): void {
	MII_Usage::index_posts( [ $post_id ] );
}

/**
 * Something about a post's context changed that no hook here can see — say,
 * a plugin moved its location by SQL. Its usages are refreshed at the end of
 * the request.
 */
function mavo_image_mark_post_context_stale( int $post_id ): void {
	MII_Sync::queue_post( $post_id );
}
