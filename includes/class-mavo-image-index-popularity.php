<?php
/**
 * How popular an article is, for ordering the browse rows.
 *
 * Primary: views in the same calendar month last year, from
 * wp_rpp_monthly_snapshots (recent-post-popularity, in mavo-stats) — seasonal
 * on purpose, so October's rows lead with what readers wanted in October.
 * Columns: post_id, snapshot_month (1st of month), views; post_id 0 is a
 * site-wide total and never read.
 *
 * Summed over the article's Polylang translation group. View tracking for
 * EN and DE started less than a year ago, so their own last-year rows are
 * empty (the home page's popular-last-year.php documents the same); counted
 * per post they would all be zero. Summed, an English article borrows its
 * French twin's history, and French ordering is all but unchanged.
 *
 * Tie-break: the `views` post meta, which recent-post-popularity keeps as a
 * rolling ~90-day total — "popular now", not lifetime (see
 * TVF_Popular_Snapshots::get_most_viewed()).
 *
 * The table belongs to another plugin, so its existence is checked rather
 * than assumed; without it only the tie-break remains.
 */

defined( 'ABSPATH' ) || exit;

class MII_Popularity {

	private static ?bool $available = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rpp_monthly_snapshots';
	}

	/** First of this calendar month, one year ago — as the home page uses. */
	public static function same_month_last_year(): string {
		return gmdate( 'Y-m-01', strtotime( '-1 year' ) );
	}

	/**
	 * @param int[] $post_ids
	 * @return array<int,array{season:int,recent:int}> post_id => counts
	 */
	public static function for_posts( array $post_ids, ?string $month = null ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$out      = [];

		if ( ! $post_ids ) {
			return $out;
		}

		$groups = self::translation_groups( $post_ids );
		$all    = array_values( array_unique( array_merge( $post_ids, ...array_values( $groups ) ) ) );
		$season = [];

		if ( self::available() ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT post_id, views FROM ' . self::table() . '
				  WHERE snapshot_month = %s AND post_id IN (' . MII_DB::in_ints( $all ) . ')',
				$month ?? self::same_month_last_year()
			), ARRAY_A ) as $row ) {
				$season[ (int) $row['post_id'] ] = (int) $row['views'];
			}
		}

		$recent = [];
		foreach ( (array) $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			  WHERE meta_key = 'views' AND post_id IN (" . MII_DB::in_ints( $post_ids ) . ')',
			ARRAY_A
		) as $row ) {
			$recent[ (int) $row['post_id'] ] = (int) $row['meta_value'];
		}

		foreach ( $post_ids as $post_id ) {
			$sum = 0;

			foreach ( $groups[ $post_id ] ?? [ $post_id ] as $member ) {
				$sum += $season[ $member ] ?? 0;
			}

			$out[ $post_id ] = [ 'season' => $sum, 'recent' => $recent[ $post_id ] ?? 0 ];
		}

		/** Popularity per post: [ post_id => [ season, recent ] ]. */
		return (array) apply_filters( 'mavo_image_results_popularity', $out, $post_ids );
	}

	/** For tests. */
	public static function reset(): void {
		self::$available = null;
	}

	/* -------------------------------------------------------------- private */

	/**
	 * Each post's translation group, in one query: Polylang stores the group
	 * as a 'post_translations' term whose description is a serialized
	 * lang => post_id map.
	 *
	 * @return array<int,int[]> post_id => every post in its group (itself included)
	 */
	private static function translation_groups( array $post_ids ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT tr.object_id, tt.description
			   FROM {$wpdb->term_relationships} tr
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_translations'
			  WHERE tr.object_id IN (" . MII_DB::in_ints( $post_ids ) . ')',
			ARRAY_A
		);

		$out = [];

		foreach ( (array) $rows as $row ) {
			$map = maybe_unserialize( (string) $row['description'] );

			if ( is_array( $map ) ) {
				$out[ (int) $row['object_id'] ] = array_values( array_unique( array_merge(
					[ (int) $row['object_id'] ],
					array_filter( array_map( 'intval', $map ) )
				) ) );
			}
		}

		return $out;
	}

	private static function available(): bool {
		global $wpdb;

		if ( null === self::$available ) {
			$table           = self::table();
			self::$available = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}

		return self::$available;
	}
}
