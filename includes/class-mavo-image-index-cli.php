<?php
/**
 * wp mavo-image-index — status, rebuilds, dictionary tools and search.
 */

defined( 'ABSPATH' ) || exit;

class MII_CLI {

	/** Words too common to be worth suggesting as concept phrases. */
	const STOPWORDS = [
		'fr' => [ 'de', 'la', 'le', 'les', 'des', 'du', 'et', 'à', 'au', 'aux', 'en', 'un', 'une', 'sur', 'dans', 'avec', 'par', 'pour', 'd', 'l', 'vue', 'est', 'qui', 'sous', 'entre', 'son', 'sa', 'ses' ],
		'en' => [ 'the', 'of', 'and', 'a', 'an', 'in', 'on', 'with', 'at', 'to', 'by', 'for', 'from', 'view', 'its', 'is', 'over', 'under', 'between' ],
		'de' => [ 'der', 'die', 'das', 'den', 'dem', 'des', 'und', 'mit', 'im', 'in', 'am', 'an', 'auf', 'ein', 'eine', 'einem', 'einer', 'eines', 'von', 'vom', 'zum', 'zur', 'blick', 'bei', 'über', 'unter', 'zwischen' ],
	];

	/**
	 * Index coverage and freshness per language.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 */
	public function status( $args, $assoc ) {
		$status = MII_Status::summary();

		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = [];
		foreach ( $status['languages'] as $lang => $row ) {
			$rows[] = [ 'lang' => $lang ] + $row;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'lang', 'with_alt', 'indexed', 'stale', 'failed', 'missing' ] );

		WP_CLI::line( sprintf( 'Images: %d (%d not yet indexed)', $status['images'], $status['items_missing'] ) );
		WP_CLI::line( sprintf( 'Usage rows: %d over %d images %s', $status['usage_rows'], $status['used_images'], wp_json_encode( $status['usage_by_role'] ) ) );
		WP_CLI::line( sprintf( 'Concepts: %d defined, %d used, %d rows', $status['concepts_defined'], $status['concepts_used'], $status['concept_rows'] ) );
		WP_CLI::line( sprintf( 'Schema %d, dictionary %s', $status['db_version'], $status['dict_version'] ) );

		foreach ( $status['last_rebuild'] as $mode => $time ) {
			WP_CLI::line( sprintf( 'Last %s rebuild: %s', $mode, wp_date( 'Y-m-d H:i', (int) $time ) ) );
		}
	}

	/**
	 * Rebuild the index, in batches.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Every image (forced), then every post's usages.
	 *
	 * [--stale]
	 * : Only images whose alt text or dictionary version changed, or that failed.
	 *
	 * [--usages]
	 * : Every published post's usages.
	 *
	 * [--attachment=<id>]
	 * : One image, and the usages of the posts using it.
	 *
	 * [--batch-size=<n>]
	 * : Rows per batch.
	 * ---
	 * default: 250
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-image-index rebuild --all --batch-size=250
	 *     wp mavo-image-index rebuild --stale
	 *     wp mavo-image-index rebuild --attachment=54491
	 */
	public function rebuild( $args, $assoc ) {
		if ( ! empty( $assoc['attachment'] ) ) {
			$id     = absint( $assoc['attachment'] );
			$result = mavo_image_reindex_attachment( $id );

			MII_Usage::index_posts( array_column( mavo_image_get_usages( $id ), 'post_id' ) );
			WP_CLI::success( sprintf( 'Attachment %d: %s', $id, $result ) );
			return;
		}

		$modes = [];

		if ( ! empty( $assoc['all'] ) ) {
			$modes = [ 'images', 'usages' ];
		} else {
			if ( ! empty( $assoc['stale'] ) ) {
				$modes[] = 'stale';
			}
			if ( ! empty( $assoc['usages'] ) ) {
				$modes[] = 'usages';
			}
		}

		if ( ! $modes ) {
			WP_CLI::error( 'Say what to rebuild: --all, --stale, --usages or --attachment=<id>.' );
		}

		$batch  = max( 1, (int) ( $assoc['batch-size'] ?? 250 ) );
		$failed = [];

		foreach ( $modes as $mode ) {
			$cursor   = 0;
			$progress = null;
			$done     = 0;

			do {
				$step = MII_Rebuild::step( $mode, $cursor, $batch );

				if ( null === $progress ) {
					$progress = WP_CLI\Utils\make_progress_bar( "Rebuilding $mode", (int) ( $step['total'] ?? 0 ) );
				}

				$progress->tick( $step['processed'] );
				$done   += $step['processed'];
				$failed  = array_merge( $failed, $step['failed'] );
				$cursor  = $step['cursor'];

				self::free_memory();
			} while ( ! $step['done'] );

			$progress->finish();
			WP_CLI::log( sprintf( '%s: %d processed', $mode, $done ) );
		}

		if ( $failed ) {
			WP_CLI::warning( sprintf( '%d failed: %s', count( $failed ), implode( ', ', array_slice( $failed, 0, 50 ) ) ) );
		} else {
			WP_CLI::success( 'Done.' );
		}
	}

	/**
	 * List the concept dictionary, with how many images carry each concept.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<lang>]
	 * : Language for labels.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 */
	public function concepts( $args, $assoc ) {
		global $wpdb;

		$lang   = MII_Lang::resolve( $assoc['lang'] ?? null );
		$counts = [];

		foreach ( (array) $wpdb->get_results( 'SELECT concept, COUNT(DISTINCT attachment_id) AS n FROM ' . MII_DB::concepts() . ' GROUP BY concept', ARRAY_A ) as $row ) {
			$counts[ $row['concept'] ] = (int) $row['n'];
		}

		$rows = [];
		foreach ( MII_Concepts::all() as $slug => $def ) {
			$rows[] = [
				'concept'  => $slug,
				'group'    => $def['group'],
				'label'    => MII_Concepts::label( $slug, $lang ),
				'images'   => $counts[ $slug ] ?? 0,
				'synonyms' => count( $def['synonyms'][ $lang ] ?? [] ),
				'implies'  => implode( ',', $def['implies'] ),
			];
		}

		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'concept', 'group', 'label', 'images', 'synonyms', 'implies' ] );
		WP_CLI::line( 'Dictionary version: ' . MII_Concepts::version() );
	}

	/**
	 * Run the matcher on a piece of text.
	 *
	 * ## OPTIONS
	 *
	 * --text=<text>
	 * : The alt text to test.
	 *
	 * [--lang=<lang>]
	 * : Its language.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-image-index test --lang=fr --text="Plage de sable aux eaux turquoise"
	 */
	public function test( $args, $assoc ) {
		$lang    = MII_Lang::resolve( $assoc['lang'] ?? null );
		$text    = (string) ( $assoc['text'] ?? '' );
		$matches = MII_Matcher::match( $text, $lang );

		WP_CLI::line( 'Normalized: ' . MII_Matcher::normalize( $text ) );

		if ( ! $matches ) {
			WP_CLI::line( 'No concepts matched.' );
			return;
		}

		foreach ( $matches as &$m ) {
			$m['implied_by'] = (string) $m['implied_by'];
		}

		WP_CLI\Utils\format_items( 'table', $matches, [ 'concept', 'matched', 'phrase', 'implied_by', 'source', 'confidence' ] );
	}

	/**
	 * Search the index.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<lang>]
	 * : Language. Defaults to the default language.
	 *
	 * [--concept=<slug>]
	 * : A concept; repeat for several.
	 *
	 * [--or]
	 * : Any of the concepts rather than all.
	 *
	 * [--exclude=<slug>]
	 * : A concept to exclude; repeat for several.
	 *
	 * [--text=<text>]
	 * : Whole-word phrase in the alt text.
	 *
	 * [--place=<term_id>]
	 * : mavo-geotag-plus place, with descendants.
	 *
	 * [--hub=<post_id>]
	 * : mavo-hubs hub, with descendants.
	 *
	 * [--post=<post_id>]
	 * : Only images used in this post; repeat for several.
	 *
	 * [--orientation=<orientation>]
	 * : landscape, portrait or square.
	 *
	 * [--min-width=<px>]
	 * : Minimum width.
	 *
	 * [--any-language]
	 * : Do not restrict to images used in posts of --lang.
	 *
	 * [--orderby=<orderby>]
	 * : relevance, date, random or id.
	 * ---
	 * default: relevance
	 * ---
	 *
	 * [--limit=<n>]
	 * : How many.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-image-index search --lang=fr --concept=beach --concept=turquoise_water
	 */
	public function search( $args, $assoc ) {
		$lang = $assoc['lang'] ?? MII_Lang::default_language();

		$results = mavo_image_search( [
			'lang'                    => $lang,
			'concepts'                => self::repeated( 'concept', $assoc ),
			'concept_operator'        => ! empty( $assoc['or'] ) ? 'OR' : 'AND',
			'exclude_concepts'        => self::repeated( 'exclude', $assoc ),
			'text'                    => $assoc['text'] ?? null,
			'place'                   => $assoc['place'] ?? null,
			'hub'                     => $assoc['hub'] ?? null,
			'include_hub_descendants' => true,
			'post_ids'                => self::repeated( 'post', $assoc ),
			'orientation'             => $assoc['orientation'] ?? null,
			'min_width'               => $assoc['min-width'] ?? null,
			'same_language'           => empty( $assoc['any-language'] ),
			'orderby'                 => $assoc['orderby'] ?? 'relevance',
			'limit'                   => (int) ( $assoc['limit'] ?? 20 ),
		] );

		$rows = [];
		foreach ( $results as $r ) {
			$rows[] = [
				'id'          => $r['attachment_id'],
				'score'       => $r['score'],
				'concepts'    => implode( ',', array_column( $r['concepts'], 'slug' ) ),
				'size'        => $r['dimensions']['width'] . '×' . $r['dimensions']['height'],
				'posts'       => implode( ',', array_unique( array_column( $r['usages'], 'post_id' ) ) ),
				'geo'         => $r['geo']['precision'],
				'alt'         => mb_strimwidth( (string) $r['alt_text'], 0, 70, '…' ),
			];
		}

		if ( ! $rows ) {
			WP_CLI::line( 'No images.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'score', 'concepts', 'size', 'posts', 'geo', 'alt' ] );
	}

	/**
	 * The most frequent words and word pairs in indexed alt text that no
	 * concept matched — the candidates for new synonyms.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<lang>]
	 * : Language.
	 *
	 * [--top=<n>]
	 * : How many.
	 * ---
	 * default: 60
	 * ---
	 */
	public function vocab( $args, $assoc ) {
		global $wpdb;

		$lang  = MII_Lang::resolve( $assoc['lang'] ?? MII_Lang::default_language() );
		$stop  = array_flip( self::STOPWORDS[ $lang ] ?? [] );
		$count = [];
		$last  = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, alt_text FROM ' . MII_DB::semantics() . ' WHERE lang = %s AND id > %d ORDER BY id LIMIT 500',
				$lang,
				$last
			), ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['id'];
				$tokens  = MII_Matcher::tokens( (string) $row['alt_text'] );
				$covered = [];

				foreach ( MII_Matcher::match( (string) $row['alt_text'], $lang ) as $m ) {
					foreach ( explode( ' ', $m['matched'] ) as $t ) {
						$covered[ $t ] = true;
					}
				}

				$prev = null;
				foreach ( $tokens as $t ) {
					$useful = ! isset( $stop[ $t ] ) && ! isset( $covered[ $t ] ) && mb_strlen( $t ) > 2 && ! ctype_digit( $t );

					if ( $useful ) {
						$count[ $t ] = ( $count[ $t ] ?? 0 ) + 1;

						if ( null !== $prev ) {
							$pair           = $prev . ' ' . $t;
							$count[ $pair ] = ( $count[ $pair ] ?? 0 ) + 1;
						}
					}

					$prev = $useful ? $t : null;
				}
			}
		} while ( $rows );

		arsort( $count );

		$out = [];
		foreach ( array_slice( $count, 0, (int) ( $assoc['top'] ?? 60 ), true ) as $phrase => $n ) {
			$out[] = [ 'phrase' => $phrase, 'count' => $n ];
		}

		WP_CLI\Utils\format_items( 'table', $out, [ 'phrase', 'count' ] );
	}

	/* -------------------------------------------------------------- private */

	/**
	 * A flag given several times (--concept=a --concept=b) or as a list
	 * (--concept=a,b). WP-CLI keeps only the last of a repeated flag, so the
	 * raw argv is read as well.
	 */
	private static function repeated( string $flag, array $assoc ): array {
		$out = isset( $assoc[ $flag ] ) ? [ (string) $assoc[ $flag ] ] : [];

		foreach ( (array) ( $GLOBALS['argv'] ?? [] ) as $arg ) {
			if ( str_starts_with( $arg, "--$flag=" ) ) {
				$out[] = substr( $arg, strlen( $flag ) + 3 );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', implode( ',', $out ) ) ) ) ) );
	}

	/** Keep a long rebuild flat in memory. */
	private static function free_memory(): void {
		global $wpdb;

		$wpdb->queries = [];

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
