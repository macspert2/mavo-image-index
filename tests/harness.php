<?php
/**
 * WordPress stubs over an in-memory SQLite database, so the plugin's real SQL
 * — search, status, usage diffing — runs in the tests rather than a mock of
 * it. The plugin's SQL is kept to what MySQL and SQLite share for exactly
 * this reason; MD5() is supplied below, as MySQL has it built in.
 *
 * Polylang, mavo-geotag-plus and mavo-hubs are NOT stubbed here: a test that
 * wants them includes stubs-integrations.php, so the tests that leave them
 * out prove the plugin degrades without them (function_exists() cannot be
 * undone within one process).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'MII_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MII_PLUGIN_URL', 'https://example.test/wp-content/plugins/mavo-image-index/' );
define( 'MII_VERSION', 'test' );

/* ------------------------------------------------------------------ wpdb */

class Test_WPDB {
	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $last_error         = '';
	public int $insert_id             = 0;
	public array $queries             = [];
	public int $num_queries           = 0;
	public PDO $pdo;

	public function __construct() {
		$this->pdo = class_exists( 'Pdo\\Sqlite' ) ? PDO::connect( 'sqlite::memory:' ) : new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );

		$md5 = static fn( $v ) => null === $v ? null : md5( (string) $v );
		if ( method_exists( $this->pdo, 'createFunction' ) ) {
			$this->pdo->createFunction( 'MD5', $md5, 1 );
		} else {
			$this->pdo->sqliteCreateFunction( 'MD5', $md5, 1 );
		}
		$this->schema();
	}

	private function schema(): void {
		$this->pdo->exec( "
			CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT DEFAULT 'post', post_status TEXT DEFAULT 'publish',
				post_mime_type TEXT DEFAULT '', post_content TEXT DEFAULT '', post_title TEXT DEFAULT '', post_date TEXT DEFAULT '2024-01-01 00:00:00' );
			CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
			CREATE INDEX wp_postmeta_post_id ON wp_postmeta ( post_id );
			CREATE INDEX wp_postmeta_meta_key ON wp_postmeta ( meta_key );
			CREATE INDEX wp_posts_type ON wp_posts ( post_type, post_status );
			CREATE TABLE wp_terms ( term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT );
			CREATE TABLE wp_term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT );
			CREATE TABLE wp_term_relationships ( object_id INTEGER, term_taxonomy_id INTEGER );
			CREATE TABLE wp_geo_mashup_locations ( id INTEGER PRIMARY KEY, lat REAL, lng REAL );
			CREATE TABLE wp_geo_mashup_location_relationships ( object_name TEXT, object_id INTEGER, location_id INTEGER );

			CREATE TABLE wp_mavo_image_items ( attachment_id INTEGER PRIMARY KEY, mime_type TEXT DEFAULT '', width INTEGER DEFAULT 0,
				height INTEGER DEFAULT 0, aspect_ratio REAL DEFAULT 0, orientation TEXT DEFAULT '', attachment_date TEXT, indexed_at TEXT );
			CREATE TABLE wp_mavo_image_semantics ( id INTEGER PRIMARY KEY AUTOINCREMENT, attachment_id INTEGER, lang TEXT, alt_text TEXT,
				alt_norm TEXT, alt_hash TEXT DEFAULT '', dict_version TEXT DEFAULT '', status TEXT DEFAULT 'indexed', error TEXT DEFAULT '',
				source_updated_at TEXT, indexed_at TEXT, UNIQUE ( attachment_id, lang ) );
			CREATE TABLE wp_mavo_image_concepts ( id INTEGER PRIMARY KEY AUTOINCREMENT, attachment_id INTEGER, lang TEXT, concept TEXT,
				source TEXT DEFAULT 'alt', confidence REAL DEFAULT 1, matched TEXT DEFAULT '', indexed_at TEXT,
				UNIQUE ( attachment_id, lang, concept, source ) );
			CREATE TABLE wp_mavo_image_usage ( id INTEGER PRIMARY KEY AUTOINCREMENT, attachment_id INTEGER, post_id INTEGER, lang TEXT DEFAULT '',
				role TEXT, source TEXT DEFAULT '', position INTEGER DEFAULT 0, geo_precision TEXT DEFAULT 'unknown', geo_confidence REAL DEFAULT 0,
				geo_lat REAL, geo_lng REAL, geo_place INTEGER, created_at TEXT, updated_at TEXT, UNIQUE ( attachment_id, post_id, role ) );
		" );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback( '/%%|%[dsf]/', function ( $m ) use ( &$i, $args ) {
			if ( '%%' === $m[0] ) {
				return '%';
			}
			$v = $args[ $i++ ] ?? null;
			return match ( $m[0] ) {
				'%d' => (string) (int) $v,
				'%f' => (string) (float) $v,
				default => $this->pdo->quote( (string) $v ),
			};
		}, $query );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	private function run( string $sql ) {
		$this->num_queries++;
		$this->queries[] = $sql;

		if ( preg_match( "/^\s*SHOW TABLES LIKE '([^']+)'/i", $sql, $m ) ) {
			$name = stripslashes( $m[1] );
			$stmt = $this->pdo->query( 'SELECT name FROM sqlite_master WHERE type = ' . $this->pdo->quote( 'table' ) . ' AND name = ' . $this->pdo->quote( $name ) );
			return $stmt;
		}

		$stmt = $this->pdo->query( $sql );

		if ( false === $stmt ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() ) . " in: $sql";
			fwrite( STDERR, "SQL ERROR: {$this->last_error}\n" );
		} else {
			$this->last_error = '';
		}

		return $stmt;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$stmt = $this->run( $sql );

		if ( ! $stmt ) {
			return null;
		}

		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		return ARRAY_A === $output ? $rows : array_map( static fn( $r ) => (object) $r, $rows );
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->get_results( $sql, $output );
		return $rows[0] ?? null;
	}

	public function get_var( $sql ) {
		$stmt = $this->run( $sql );
		if ( ! $stmt ) {
			return null;
		}
		$v = $stmt->fetchColumn();
		return false === $v ? null : $v;
	}

	public function get_col( $sql ) {
		$stmt = $this->run( $sql );
		return $stmt ? $stmt->fetchAll( PDO::FETCH_COLUMN, 0 ) : [];
	}

	public function query( $sql ) {
		$stmt = $this->run( $sql );
		return $stmt ? $stmt->rowCount() : false;
	}

	private function write( string $verb, string $table, array $data ) {
		$cols = array_keys( $data );
		$sql  = "$verb INTO $table (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')';
		$stmt = $this->pdo->prepare( $sql );
		$this->num_queries++;

		if ( ! $stmt || ! $stmt->execute( array_values( $data ) ) ) {
			$this->last_error = implode( ' ', ( $stmt ?: $this->pdo )->errorInfo() );
			fwrite( STDERR, "SQL ERROR: {$this->last_error}\n" );
			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function insert( $table, $data, $format = null ) {
		return $this->write( 'INSERT', $table, $data );
	}

	public function replace( $table, $data, $format = null ) {
		return $this->write( 'REPLACE', $table, $data );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set  = implode( ',', array_map( static fn( $c ) => "$c = ?", array_keys( $data ) ) );
		$cond = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
		$stmt = $this->pdo->prepare( "UPDATE $table SET $set WHERE $cond" );
		$this->num_queries++;
		return $stmt && $stmt->execute( array_merge( array_values( $data ), array_values( $where ) ) ) ? $stmt->rowCount() : false;
	}

	public function delete( $table, $where, $format = null ) {
		$cond = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
		$stmt = $this->pdo->prepare( "DELETE FROM $table WHERE $cond" );
		$this->num_queries++;
		return $stmt && $stmt->execute( array_values( $where ) ) ? $stmt->rowCount() : false;
	}
}

$GLOBALS['wpdb'] = new Test_WPDB();

/* ----------------------------------------------------------------- hooks */

$GLOBALS['MOCK_HOOKS']   = [];
$GLOBALS['MOCK_ACTIONS'] = [];
$GLOBALS['MOCK_OPTIONS'] = [];
$GLOBALS['MOCK_CACHE']   = [];
$GLOBALS['MOCK_SHORTCODES'] = [];
$GLOBALS['MOCK_CURRENT_POST'] = 0;
$GLOBALS['MOCK_URL_MAP'] = [];
$GLOBALS['MOCK_META_CACHE'] = [];

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['MOCK_HOOKS'][ $tag ][ $priority ][] = [ $cb, $args ];
	ksort( $GLOBALS['MOCK_HOOKS'][ $tag ] );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $priority, $args );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['MOCK_HOOKS'][ $tag ] );
}
function apply_filters( $tag, $value, ...$rest ) {
	foreach ( $GLOBALS['MOCK_HOOKS'][ $tag ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $cb, $n ] ) {
			$value = $cb( ...array_slice( array_merge( [ $value ], $rest ), 0, max( 1, $n ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['MOCK_ACTIONS'][] = array_merge( [ $tag ], $args );
	foreach ( $GLOBALS['MOCK_HOOKS'][ $tag ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $cb, $n ] ) {
			$cb( ...array_slice( $args, 0, $n ) );
		}
	}
}

/* --------------------------------------------------------- core functions */

function get_option( $k, $d = false ) { return $GLOBALS['MOCK_OPTIONS'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['MOCK_OPTIONS'][ $k ] = $v; return true; }
function wp_cache_get( $k, $g = '', $force = false, &$found = null ) {
	$found = array_key_exists( "$g:$k", $GLOBALS['MOCK_CACHE'] );
	return $found ? $GLOBALS['MOCK_CACHE'][ "$g:$k" ] : false;
}
function wp_cache_set( $k, $v, $g = '', $ttl = 0 ) { $GLOBALS['MOCK_CACHE'][ "$g:$k" ] = $v; return true; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_html_class( $v ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) {
	$s = (string) $s;
	if ( '' === $s || ! preg_match( '#^(https?:)?//|^/#i', $s ) ) { return ''; }
	return str_replace( [ '"', "'", '<', '>' ], [ '%22', '%27', '%3C', '%3E' ], $s );
}
function esc_url_raw( $s ) { return esc_url( $s ); }
function __( $s, $d = '' ) { return $s; }
function wp_list_pluck( $list, $field ) { return array_map( static fn( $i ) => is_array( $i ) ? $i[ $field ] : $i->$field, $list ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
/** As core: one query loads every key for every uncached post. */
function update_meta_cache( $type, $ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => ! isset( $GLOBALS['MOCK_META_CACHE'][ $id ] ) ) );
	if ( ! $ids ) { return true; }
	foreach ( $ids as $id ) { $GLOBALS['MOCK_META_CACHE'][ $id ] = []; }
	foreach ( $wpdb->get_results( 'SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (' . implode( ',', $ids ) . ') ORDER BY meta_id', ARRAY_A ) as $row ) {
		$GLOBALS['MOCK_META_CACHE'][ (int) $row['post_id'] ][ $row['meta_key'] ][] = $row['meta_value'];
	}
	return true;
}
function _prime_post_caches( $ids, $a = true, $b = true ) {}
function wp_next_scheduled( $hook ) { return $GLOBALS['MOCK_CRON'][ $hook ] ?? false; }
function wp_schedule_single_event( $t, $hook ) { $GLOBALS['MOCK_CRON'][ $hook ] = $t; return true; }
function get_the_ID() { return $GLOBALS['MOCK_CURRENT_POST']; }
function add_shortcode( $tag, $cb ) { $GLOBALS['MOCK_SHORTCODES'][ $tag ] = $cb; }
function shortcode_atts( $pairs, $atts, $tag = '' ) {
	$out = [];
	foreach ( $pairs as $k => $d ) { $out[ $k ] = array_key_exists( $k, (array) $atts ) ? $atts[ $k ] : $d; }
	return $out;
}
function attachment_url_to_postid( $url ) { return $GLOBALS['MOCK_URL_MAP'][ $url ] ?? 0; }
function wp_get_attachment_url( $id ) { return "https://example.test/wp-content/uploads/$id.jpg"; }
function get_permalink( $id ) { return "https://example.test/?p=" . ( is_object( $id ) ? $id->ID : (int) $id ); }

function get_post_meta( $id, $key = '', $single = false ) {
	update_meta_cache( 'post', [ $id ] );
	$values = $GLOBALS['MOCK_META_CACHE'][ (int) $id ][ $key ] ?? [];
	if ( $single ) { return $values[0] ?? ''; }
	return $values;
}
function wp_get_attachment_metadata( $id ) {
	$v = get_post_meta( $id, '_wp_attachment_metadata', true );
	return '' === $v ? false : unserialize( $v );
}
function get_post_status( $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT post_status FROM wp_posts WHERE ID = %d', $id ) ) ?: false;
}
function get_terms( $args ) {
	global $wpdb;
	$ids = implode( ',', array_map( 'intval', (array) $args['include'] ) ) ?: '0';
	return array_map( static fn( $r ) => (object) $r, $wpdb->get_results( "SELECT term_id, name FROM wp_terms WHERE term_id IN ($ids)", ARRAY_A ) );
}

class WP_Post {
	public $ID;
	public $post_type;
	public function __construct( $id = 0, $type = 'post' ) { $this->ID = $id; $this->post_type = $type; }
}

/* ----------------------------------------------------------------- plugin */

require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-db.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-lang.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-alt.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-concepts.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-matcher.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-cache.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-indexer.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-geo.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-usage.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-images.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-search.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-status.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-rebuild.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-sync.php';
require MII_PLUGIN_DIR . 'includes/class-mavo-image-index-shortcode.php';
require MII_PLUGIN_DIR . 'includes/api.php';

/* --------------------------------------------------------------- fixtures */

function mii_meta( int $post_id, string $key, $value ): void {
	global $wpdb;
	unset( $GLOBALS['MOCK_META_CACHE'][ $post_id ] );
	$wpdb->delete( 'wp_postmeta', [ 'post_id' => $post_id, 'meta_key' => $key ] );
	if ( null !== $value ) {
		$wpdb->insert( 'wp_postmeta', [ 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => is_array( $value ) ? serialize( $value ) : (string) $value ] );
	}
}

/** An image attachment. $alts: lang => alt text (fr goes to _wp_attachment_image_alt). */
function mii_image( int $id, int $w, int $h, array $alts = [], string $date = '2024-01-01 00:00:00', string $mime = 'image/jpeg' ): void {
	global $wpdb;
	$wpdb->replace( 'wp_posts', [ 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mime, 'post_date' => $date ] );
	mii_meta( $id, '_wp_attachment_metadata', [ 'width' => $w, 'height' => $h ] );
	foreach ( $alts as $lang => $alt ) {
		mii_meta( $id, MII_Alt::meta_key( $lang ), $alt );
	}
}

function mii_post( int $id, string $content = '', array $opts = [] ): void {
	global $wpdb;
	$wpdb->replace( 'wp_posts', [
		'ID'           => $id,
		'post_type'    => $opts['type'] ?? 'post',
		'post_status'  => $opts['status'] ?? 'publish',
		'post_content' => $content,
		'post_date'    => $opts['date'] ?? '2024-02-01 00:00:00',
	] );
	if ( isset( $opts['thumb'] ) ) {
		mii_meta( $id, '_thumbnail_id', $opts['thumb'] );
	}
	if ( isset( $opts['lang'] ) ) {
		mii_term_rel( $id, mii_term( 'lang-' . $opts['lang'], 'language', $opts['lang'] ) );
	}
}

/** A term; returns its term_taxonomy_id (== term_id here). */
function mii_term( string $name, string $taxonomy = 'post_tag', ?string $slug = null, ?int $id = null ): int {
	global $wpdb;
	$slug     = $slug ?? strtolower( $name );
	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT t.term_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s', $slug, $taxonomy ) );
	if ( $existing ) { return (int) $existing; }
	$id = $id ?? ( 1000 + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_terms' ) );
	$wpdb->insert( 'wp_terms', [ 'term_id' => $id, 'name' => $name, 'slug' => $slug ] );
	$wpdb->insert( 'wp_term_taxonomy', [ 'term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy ] );
	return $id;
}

function mii_term_rel( int $object_id, int $tt_id ): void {
	global $wpdb;
	$wpdb->insert( 'wp_term_relationships', [ 'object_id' => $object_id, 'term_taxonomy_id' => $tt_id ] );
}

function mii_geomashup( int $post_id, float $lat, float $lng ): void {
	global $wpdb;
	$wpdb->insert( 'wp_geo_mashup_locations', [ 'lat' => $lat, 'lng' => $lng ] );
	$wpdb->insert( 'wp_geo_mashup_location_relationships', [ 'object_name' => 'post', 'object_id' => $post_id, 'location_id' => $wpdb->insert_id ] );
}

/* -------------------------------------------------------------- assertions */

$GLOBALS['MII_FAILS']  = 0;
$GLOBALS['MII_PASSES'] = 0;

function check( string $label, bool $ok, $detail = null ): void {
	if ( $ok ) {
		$GLOBALS['MII_PASSES']++;
		return;
	}
	$GLOBALS['MII_FAILS']++;
	echo "  FAIL: $label" . ( null !== $detail ? ' — ' . ( is_string( $detail ) ? $detail : json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) : '' ) . "\n";
}

function same( string $label, $expected, $actual ): void {
	check( $label, $expected === $actual, [ 'expected' => $expected, 'actual' => $actual ] );
}

function concepts_of( string $text, string $lang ): array {
	$out = array_column( MII_Matcher::match( $text, $lang ), 'concept' );
	sort( $out );
	return $out;
}

function done(): void {
	printf( "  %d passed, %d failed\n", $GLOBALS['MII_PASSES'], $GLOBALS['MII_FAILS'] );
	exit( $GLOBALS['MII_FAILS'] ? 1 : 0 );
}
