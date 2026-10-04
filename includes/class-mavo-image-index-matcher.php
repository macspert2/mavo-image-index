<?php
/**
 * Alt text in, canonical concepts out.
 *
 * Matching is over whole tokens, never substrings. Text is normalized to
 * lowercase NFC with every run of non-letters collapsed to one space — which
 * also splits French elisions, so "l'eau turquoise" contains "eau turquoise".
 * A phrase then matches a run of consecutive tokens. "mer" cannot fire inside
 * "merveilleux" because "merveilleux" is one token and it is not "mer".
 *
 * Accents are deliberately NOT folded. French côte (coast) and côté (side),
 * marche (walk) and marché (market) differ only by an accent; folding would
 * merge them. Alt text on this site is written by hand with its accents, and
 * the dictionary is too.
 *
 * Two controlled wildcards, both token-anchored and needing a four-letter
 * stem — no regex from the dictionary ever reaches preg_*:
 *
 *   *strand   token ends with "strand"     — German compounds (Sandstrand)
 *   türkis*   token starts with "türkis"   — inflected stems (türkisfarbenem)
 *
 * Wildcard matches weigh 0.9 × the phrase's confidence.
 */

defined( 'ABSPATH' ) || exit;

class MII_Matcher {

	/**
	 * Part of the dictionary version: bump when matching rules change, so
	 * every row indexed under the old rules becomes stale.
	 */
	const VERSION = '2';

	const MIN_STEM        = 4;
	const WILDCARD_WEIGHT = 0.9;
	const IMPLIED_WEIGHT  = 0.9;
	const MAX_VARIANTS    = 32;

	/** @var array<string,array> lang => compiled patterns */
	private static array $compiled = [];
	private static string $compiled_version = '';

	/**
	 * Concepts found in a text.
	 *
	 * @return array<int,array{concept:string,confidence:float,phrase:string,matched:string,implied_by:?string,source:string}>
	 *         Strongest first. One entry per concept.
	 */
	public static function match( string $text, string $lang ): array {
		$tokens = self::tokens( $text );

		if ( ! $tokens ) {
			return [];
		}

		$compiled = self::compiled( $lang );
		$hits     = [];
		$found    = [];   // concept => best hit
		$vetoes   = [];   // concept => [ [start, end], … ]
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$candidates = array_merge( $compiled['exact'][ $tokens[ $i ] ] ?? [], $compiled['wild'] );

			foreach ( $candidates as $p ) {
				$len = self::match_at( $p['tokens'], $tokens, $i );

				if ( ! $len ) {
					continue;
				}

				if ( $p['except'] ) {
					$vetoes[ $p['concept'] ][] = [ $i, $i + $len ];
					continue;
				}

				$hits[] = [
					'concept'    => $p['concept'],
					'confidence' => $p['weight'],
					'phrase'     => $p['phrase'],
					'matched'    => implode( ' ', array_slice( $tokens, $i, $len ) ),
					'start'      => $i,
					'end'        => $i + $len,
				];
			}
		}

		foreach ( $hits as $hit ) {
			foreach ( $vetoes[ $hit['concept'] ] ?? [] as [ $start, $end ] ) {
				if ( $hit['start'] < $end && $start < $hit['end'] ) {
					continue 2;
				}
			}

			$best = $found[ $hit['concept'] ] ?? null;

			if ( ! $best || $hit['confidence'] > $best['confidence']
				|| ( $hit['confidence'] === $best['confidence'] && strlen( $hit['matched'] ) > strlen( $best['matched'] ) )
			) {
				$found[ $hit['concept'] ] = $hit;
			}
		}

		$out = [];

		foreach ( $found as $concept => $hit ) {
			$out[ $concept ] = [
				'concept'    => $concept,
				'confidence' => round( (float) $hit['confidence'], 3 ),
				'phrase'     => $hit['phrase'],
				'matched'    => $hit['matched'],
				'implied_by' => null,
				'source'     => 'alt',
			];
		}

		$out = self::add_implied( $out );

		uasort( $out, static function ( $a, $b ) {
			return [ $b['confidence'], $a['concept'] ] <=> [ $a['confidence'], $b['concept'] ];
		} );

		/** Concepts found in one alt text, before they are stored. */
		return array_values( (array) apply_filters( 'mavo_image_matcher_output', array_values( $out ), $text, $lang ) );
	}

	/**
	 * Lowercase NFC, punctuation and apostrophes to single spaces.
	 *
	 * Used for alt text, for dictionary phrases (keeping '*'), and for the
	 * stored alt_norm column that raw text search runs against.
	 */
	public static function normalize( string $text, bool $keep_wildcards = false ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( class_exists( 'Normalizer' ) ) {
			$text = (string) Normalizer::normalize( $text, Normalizer::FORM_C );
		}

		$text = self::join_names( $text );
		$text = mb_strtolower( $text, 'UTF-8' );
		$keep = $keep_wildcards ? '\*' : '';
		$text = (string) preg_replace( '/[^\p{L}\p{N}' . $keep . ']+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Hyphenated proper names become one token: Camaret-sur-Mer is a town,
	 * not the sea; Mont-Saint-Michel not a mountain; Port-Vendres not a port.
	 * A compound counts as a name when a part after the first is capitalised,
	 * or when the first is and the compound does not open the text (where any
	 * word is capitalised). Lowercase compounds — vieille-ville, sous-bois,
	 * half-timbered — still split into their words. Found in the alt-text
	 * corpus, 2026-10-04: "-sur-Mer" towns alone made a few dozen false seas.
	 */
	private static function join_names( string $text ): string {
		return (string) preg_replace_callback(
			'/[\p{L}\p{N}]+(?:[-\x{2010}\x{2011}][\p{L}\p{N}]+)+/u',
			static function ( array $m ): string {
				[ $word, $offset ] = $m[0];
				$parts             = preg_split( '/[-\x{2010}\x{2011}]/u', $word );

				foreach ( $parts as $i => $part ) {
					if ( preg_match( '/^\p{Lu}/u', $part ) && ( $i > 0 || $offset > 0 ) ) {
						return implode( '', $parts );
					}
				}

				return $word;
			},
			$text,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/** @return string[] */
	public static function tokens( string $text ): array {
		$norm = self::normalize( $text );

		return '' === $norm ? [] : explode( ' ', $norm );
	}

	/**
	 * Expand "eau(x) turquoise(s)" into its variants and parse wildcards.
	 *
	 * @param string|array $phrase 'phrase' or [ 'phrase', confidence ]
	 * @return array<int,array{tokens:array,weight:float,phrase:string}>
	 */
	public static function compile_phrase( $phrase ): array {
		$weight = 1.0;

		if ( is_array( $phrase ) ) {
			$weight = isset( $phrase[1] ) ? max( 0.0, min( 1.0, (float) $phrase[1] ) ) : 1.0;
			$phrase = (string) ( $phrase[0] ?? '' );
		}

		$phrase = (string) $phrase;
		$out    = [];

		foreach ( self::expand( $phrase ) as $variant ) {
			$norm = self::normalize( $variant, true );

			if ( '' === $norm ) {
				continue;
			}

			$tokens = [];
			$wild   = false;

			foreach ( explode( ' ', $norm ) as $raw ) {
				$lead = str_starts_with( $raw, '*' );
				$tail = strlen( $raw ) > 1 && str_ends_with( $raw, '*' );
				$core = trim( $raw, '*' );

				if ( '' === $core ) {
					continue;
				}

				// A short stem would match half the language. Treat it as exact.
				if ( ( $lead || $tail ) && mb_strlen( $core, 'UTF-8' ) < self::MIN_STEM ) {
					$lead = $tail = false;
				}

				$mode = $lead && $tail ? 'contains' : ( $lead ? 'suffix' : ( $tail ? 'prefix' : 'exact' ) );
				$wild = $wild || 'exact' !== $mode;

				$tokens[] = [ $core, $mode ];
			}

			if ( $tokens ) {
				$out[] = [
					'tokens' => $tokens,
					'weight' => round( $weight * ( $wild ? self::WILDCARD_WEIGHT : 1.0 ), 3 ),
					'phrase' => $phrase,
				];
			}
		}

		return $out;
	}

	/** For tests, and after the registry changes mid-request. */
	public static function reset(): void {
		self::$compiled         = [];
		self::$compiled_version = '';
	}

	/* -------------------------------------------------------------- private */

	/**
	 * Compiled patterns for one language, indexed by first token so a text
	 * only tests the phrases that can start at each position. Patterns that
	 * start with a wildcard cannot be indexed and are tried everywhere; there
	 * are few.
	 */
	private static function compiled( string $lang ): array {
		$version = MII_Concepts::version();

		if ( $version !== self::$compiled_version ) {
			self::$compiled         = [];
			self::$compiled_version = $version;
		}

		if ( isset( self::$compiled[ $lang ] ) ) {
			return self::$compiled[ $lang ];
		}

		$index = [ 'exact' => [], 'wild' => [] ];

		foreach ( MII_Concepts::all() as $slug => $def ) {
			foreach ( [ 'synonyms' => false, 'except' => true ] as $key => $is_except ) {
				foreach ( $def[ $key ][ $lang ] ?? [] as $phrase ) {
					foreach ( self::compile_phrase( $phrase ) as $p ) {
						$p['concept'] = $slug;
						$p['except']  = $is_except;

						[ $first, $mode ] = $p['tokens'][0];

						if ( 'exact' === $mode ) {
							$index['exact'][ $first ][] = $p;
						} else {
							$index['wild'][] = $p;
						}
					}
				}
			}
		}

		return self::$compiled[ $lang ] = $index;
	}

	/** Length of the match of $pattern at $tokens[$i], or 0. */
	private static function match_at( array $pattern, array $tokens, int $i ): int {
		$n = count( $pattern );

		if ( $i + $n > count( $tokens ) ) {
			return 0;
		}

		foreach ( $pattern as $k => [ $core, $mode ] ) {
			$token = $tokens[ $i + $k ];

			$ok = match ( $mode ) {
				'exact'    => $token === $core,
				'prefix'   => str_starts_with( $token, $core ),
				'suffix'   => str_ends_with( $token, $core ),
				'contains' => str_contains( $token, $core ),
			};

			if ( ! $ok ) {
				return 0;
			}
		}

		return $n;
	}

	/** "a(b)(c)" → a, ab, ac, abc. Capped, in case of a pathological entry. */
	private static function expand( string $phrase ): array {
		if ( ! preg_match( '/\(([^()]*)\)/u', $phrase, $m, PREG_OFFSET_CAPTURE ) ) {
			return [ $phrase ];
		}

		$before = substr( $phrase, 0, $m[0][1] );
		$after  = substr( $phrase, $m[0][1] + strlen( $m[0][0] ) );
		$out    = [];

		foreach ( self::expand( $before . $after ) as $v ) {
			$out[] = $v;
		}
		foreach ( self::expand( $before . $m[1][0] . $after ) as $v ) {
			$out[] = $v;
		}

		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_VARIANTS );
	}

	/** Follow 'implies' links, weakening at each step. Cycles are harmless. */
	private static function add_implied( array $found ): array {
		$queue = array_keys( $found );

		while ( $queue ) {
			$slug = array_shift( $queue );
			$def  = MII_Concepts::get( $slug );

			foreach ( $def['implies'] ?? [] as $implied ) {
				$confidence = round( $found[ $slug ]['confidence'] * self::IMPLIED_WEIGHT, 3 );

				if ( isset( $found[ $implied ] ) && $found[ $implied ]['confidence'] >= $confidence ) {
					continue;
				}

				$found[ $implied ] = [
					'concept'    => $implied,
					'confidence' => $confidence,
					'phrase'     => $found[ $slug ]['phrase'],
					'matched'    => $found[ $slug ]['matched'],
					'implied_by' => $slug,
					'source'     => 'alt',
				];

				$queue[] = $implied;
			}
		}

		return $found;
	}
}
