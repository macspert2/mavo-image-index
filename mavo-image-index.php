<?php
/**
 * Plugin Name: Mavo Image Index
 * Plugin URI:  https://mamanvoyage.com
 * Description: Shared image-intelligence layer. Indexes multilingual alt text into language-neutral concepts, records where each image is used and in which role, and inherits approximate geography from the posts that use it. Other mavo-* plugins query it through a small procedural API. Its own front end is one cross-link shortcode and the results page that link leads to.
 * Version:     1.1.5
 * Author:      Mavo
 * Text Domain: mavo-image-index
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MII_VERSION',     '1.1.5' );
define( 'MII_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MII_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MII_PLUGIN_FILE', __FILE__ );

require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-db.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-lang.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-alt.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-concepts.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-matcher.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-cache.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-indexer.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-geo.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-usage.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-images.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-search.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-status.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-rebuild.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-sync.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-shortcode.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-popularity.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-results.php';
require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-place.php';
require_once MII_PLUGIN_DIR . 'includes/api.php';

register_activation_hook( __FILE__, static function () {
	MII_DB::install();
	MII_Results::schedule_refresh();
} );
// Rules added during this request would survive a flush; dropping the stored
// rules makes WordPress rebuild them on the next request, without ours.
register_deactivation_hook( __FILE__, static function () {
	delete_option( 'rewrite_rules' );
} );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'mavo-image-index', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	MII_DB::maybe_upgrade();
	MII_Sync::init();

	// On init, after every plugin has had plugins_loaded to hook
	// mavo_image_register_concepts.
	add_action( 'init', [ 'MII_Shortcode', 'init' ] );
	MII_Results::init();
	MII_Place::init();

	if ( is_admin() ) {
		require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-admin.php';
		MII_Admin::init();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once MII_PLUGIN_DIR . 'includes/class-mavo-image-index-cli.php';
		WP_CLI::add_command( 'mavo-image-index', 'MII_CLI' );
	}
} );
