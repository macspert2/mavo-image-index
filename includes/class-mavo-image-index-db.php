<?php
/**
 * The four tables, and nothing else that knows their shape.
 *
 * Custom tables rather than postmeta because every question this plugin
 * answers is a filter over thousands of rows — "landscape images over 960px
 * with both beach and turquoise_water, used in a French post under Greece" —
 * and postmeta can answer that only with LIKE scans over serialized values.
 *
 *   items      one row per image attachment: dimensions, orientation, date
 *   semantics  one row per attachment × language that has alt text
 *   concepts   one row per attachment × language × concept × source
 *   usage      one row per attachment × post × role, with a geo snapshot
 *
 * items is not in agent.md. It exists because orientation and min_width are
 * search filters, and filtering on them means an indexed column, not an
 * unserialize of _wp_attachment_metadata per candidate.
 */

defined( 'ABSPATH' ) || exit;

class MII_DB {

	/**
	 * Bump whenever install()'s CREATE TABLE statements change, so
	 * maybe_upgrade() re-runs dbDelta on sites that already have the tables.
	 *
	 * 2 — usage.geo_country / geo_region (tile eyebrows). Rows written before
	 *     carry NULL there until "Rebuild usages" or the post's next save.
	 */
	const DB_VERSION        = 2;
	const DB_VERSION_OPTION = 'mavo_image_index_db_version';

	public static function items(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_image_items';
	}

	public static function semantics(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_image_semantics';
	}

	public static function concepts(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_image_concepts';
	}

	public static function usage(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_image_usage';
	}

	/**
	 * One option read per request, and dbDelta only when the stored version
	 * differs. dbDelta is additive: it adds columns and indexes, never drops
	 * them, so an upgrade cannot lose indexed data.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is particular: one column per line, two spaces after
		// PRIMARY KEY, and KEY rather than INDEX.
		dbDelta( 'CREATE TABLE ' . self::items() . " (
			attachment_id   BIGINT UNSIGNED NOT NULL,
			mime_type       VARCHAR(100) NOT NULL DEFAULT '',
			width           INT UNSIGNED NOT NULL DEFAULT 0,
			height          INT UNSIGNED NOT NULL DEFAULT 0,
			aspect_ratio    DECIMAL(8,4) NOT NULL DEFAULT 0,
			orientation     VARCHAR(10) NOT NULL DEFAULT '',
			attachment_date DATETIME NULL DEFAULT NULL,
			indexed_at      DATETIME NOT NULL,
			PRIMARY KEY  (attachment_id),
			KEY orientation_width (orientation, width),
			KEY width (width),
			KEY attachment_date (attachment_date)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::semantics() . " (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id     BIGINT UNSIGNED NOT NULL,
			lang              VARCHAR(10) NOT NULL,
			alt_text          TEXT NOT NULL,
			alt_norm          TEXT NOT NULL,
			alt_hash          CHAR(32) NOT NULL DEFAULT '',
			dict_version      VARCHAR(32) NOT NULL DEFAULT '',
			status            VARCHAR(10) NOT NULL DEFAULT 'indexed',
			error             VARCHAR(255) NOT NULL DEFAULT '',
			source_updated_at DATETIME NULL DEFAULT NULL,
			indexed_at        DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_lang (attachment_id, lang),
			KEY lang (lang),
			KEY indexed_at (indexed_at)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::concepts() . " (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			lang          VARCHAR(10) NOT NULL,
			concept       VARCHAR(64) NOT NULL,
			source        VARCHAR(20) NOT NULL DEFAULT 'alt',
			confidence    DECIMAL(4,3) NOT NULL DEFAULT 1.000,
			matched       VARCHAR(191) NOT NULL DEFAULT '',
			indexed_at    DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_lang_concept_source (attachment_id, lang, concept, source),
			KEY attachment_id (attachment_id),
			KEY concept_lang (concept, lang),
			KEY concept_confidence (concept, confidence)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::usage() . " (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id  BIGINT UNSIGNED NOT NULL,
			post_id        BIGINT UNSIGNED NOT NULL,
			lang           VARCHAR(10) NOT NULL DEFAULT '',
			role           VARCHAR(20) NOT NULL,
			source         VARCHAR(20) NOT NULL DEFAULT '',
			position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			geo_precision  VARCHAR(20) NOT NULL DEFAULT 'unknown',
			geo_confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
			geo_lat        DECIMAL(10,7) NULL DEFAULT NULL,
			geo_lng        DECIMAL(10,7) NULL DEFAULT NULL,
			geo_place      BIGINT UNSIGNED NULL DEFAULT NULL,
			geo_country    BIGINT UNSIGNED NULL DEFAULT NULL,
			geo_region     BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at     DATETIME NOT NULL,
			updated_at     DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_post_role (attachment_id, post_id, role),
			KEY attachment_id (attachment_id),
			KEY post_lang (post_id, lang),
			KEY lang_role (lang, role),
			KEY geo_place (geo_place)
		) $charset_collate;" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** IN ( %d, %d, … ) for a list already cast to int. Never empty. */
	public static function in_ints( array $ids ): string {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return $ids ? implode( ',', $ids ) : '0';
	}

	/** IN ( 'a', 'b' ) for strings, each escaped through prepare(). */
	public static function in_strings( array $values ): string {
		global $wpdb;

		$values = array_values( array_unique( array_map( 'strval', $values ) ) );

		if ( ! $values ) {
			return "''";
		}

		return $wpdb->prepare( implode( ',', array_fill( 0, count( $values ), '%s' ) ), ...$values );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Throw on a failed write, so the indexer can mark one attachment failed
	 * and carry on rather than leave half its rows behind silently.
	 */
	public static function check( $result ): void {
		global $wpdb;

		if ( false === $result ) {
			throw new RuntimeException( $wpdb->last_error ?: 'database write failed' );
		}
	}
}
