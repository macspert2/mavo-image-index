<?php
/**
 * Tools → Image Index: what is indexed, what is stale, and the buttons that
 * fix it. Rebuilds run as a chain of small AJAX requests (MII_Rebuild::step),
 * never as one long one.
 */

defined( 'ABSPATH' ) || exit;

class MII_Admin {

	const PAGE_SLUG  = 'mavo-image-index';
	const CAPABILITY = 'manage_options';
	const AJAX       = 'mii_rebuild';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_' . self::AJAX, [ __CLASS__, 'ajax_rebuild' ] );
		add_action( 'admin_post_mii_rebuild_attachment', [ __CLASS__, 'handle_rebuild_attachment' ] );
		add_action( 'admin_post_mii_save_targets', [ __CLASS__, 'handle_save_targets' ] );
	}

	public static function add_page(): void {
		add_management_page(
			__( 'Image Index', 'mavo-image-index' ),
			__( 'Image Index', 'mavo-image-index' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mii-admin', MII_PLUGIN_URL . 'assets/admin.css', [], MII_VERSION );
		wp_enqueue_script( 'mii-admin', MII_PLUGIN_URL . 'assets/admin.js', [], MII_VERSION, true );
		wp_localize_script( 'mii-admin', 'MII_ADMIN', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX,
			'nonce'   => wp_create_nonce( self::AJAX ),
			'i18n'    => [
				'running' => __( 'Running %1$s: %2$d done', 'mavo-image-index' ),
				'of'      => __( 'of %d', 'mavo-image-index' ),
				'done'    => __( 'Finished. %d failed. Reload to see the new counts.', 'mavo-image-index' ),
				'error'   => __( 'Stopped: %s. Run it again to resume.', 'mavo-image-index' ),
			],
		] );
	}

	/* ---------------------------------------------------------------- AJAX */

	public static function ajax_rebuild(): void {
		check_ajax_referer( self::AJAX, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$mode   = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$cursor = absint( $_POST['cursor'] ?? 0 );

		if ( ! in_array( $mode, MII_Rebuild::MODES, true ) ) {
			wp_send_json_error( [ 'message' => 'unknown mode' ], 400 );
		}

		wp_send_json_success( MII_Rebuild::step( $mode, $cursor ) );
	}

	/* -------------------------------------------------------- form handlers */

	public static function handle_rebuild_attachment(): void {
		self::guard( 'mii_rebuild_attachment' );

		$id     = absint( $_POST['attachment_id'] ?? 0 );
		$result = $id ? mavo_image_reindex_attachment( $id ) : 'removed';

		if ( $id && 'removed' !== $result ) {
			MII_Usage::index_posts( array_column( mavo_image_get_usages( $id ), 'post_id' ) );
		}

		self::back( [ 'mii_notice' => 'attachment', 'mii_id' => $id, 'mii_result' => $result ] );
	}

	public static function handle_save_targets(): void {
		self::guard( 'mii_save_targets' );

		$targets = MII_Shortcode::parse_targets( (string) wp_unslash( $_POST['targets'] ?? '' ) );

		update_option( MII_Shortcode::TARGETS_OPTION, $targets, false );

		self::back( [ 'mii_notice' => 'targets', 'mii_count' => count( $targets ) ] );
	}

	/* ---------------------------------------------------------------- page */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the image index.', 'mavo-image-index' ) );
		}

		$status = MII_Status::summary();
		$top    = MII_Status::top_concepts( 12 );
		$queue  = MII_Sync::stored_queue();
		?>
		<div class="wrap mii">
			<h1><?php esc_html_e( 'Image Index', 'mavo-image-index' ); ?></h1>

			<?php self::render_notice(); ?>

			<?php if ( 0 === $status['items'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'The index is empty. Run “Rebuild all” once; from then on it keeps itself current as images and posts are saved.', 'mavo-image-index' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'mavo-image-index' ); ?></h2>

			<table class="widefat striped mii__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'With alt text', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Indexed', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Stale', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Failed', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'No alt text', 'mavo-image-index' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status['languages'] as $lang => $row ) : ?>
						<tr>
							<th><?php echo esc_html( strtoupper( $lang ) ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $row['with_alt'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['indexed'] ) ); ?></td>
							<td class="<?php echo $row['stale'] ? 'mii__warn' : ''; ?>"><?php echo esc_html( number_format_i18n( $row['stale'] ) ); ?></td>
							<td class="<?php echo $row['failed'] ? 'mii__warn' : ''; ?>"><?php echo esc_html( number_format_i18n( $row['failed'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $row['missing'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<dl class="mii__facts">
				<dt><?php esc_html_e( 'Image attachments', 'mavo-image-index' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $status['images'] ) ); ?>
					<?php if ( $status['items_missing'] ) : ?>
						<span class="mii__warn"><?php printf( esc_html__( '(%s not yet indexed)', 'mavo-image-index' ), esc_html( number_format_i18n( $status['items_missing'] ) ) ); ?></span>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Usage rows', 'mavo-image-index' ); ?></dt>
				<dd>
					<?php echo esc_html( number_format_i18n( $status['usage_rows'] ) ); ?>
					<?php
					$parts = [];
					foreach ( $status['usage_by_role'] as $role => $n ) {
						$parts[] = $role . ' ' . number_format_i18n( $n );
					}
					echo $parts ? esc_html( '(' . implode( ', ', $parts ) . ')' ) : '';
					?>
					— <?php printf( esc_html__( '%s distinct images in use', 'mavo-image-index' ), esc_html( number_format_i18n( $status['used_images'] ) ) ); ?>
				</dd>
				<dt><?php esc_html_e( 'Concepts', 'mavo-image-index' ); ?></dt>
				<dd><?php printf( esc_html__( '%1$d defined, %2$d found in images, %3$s concept rows', 'mavo-image-index' ), (int) $status['concepts_defined'], (int) $status['concepts_used'], esc_html( number_format_i18n( $status['concept_rows'] ) ) ); ?></dd>
				<dt><?php esc_html_e( 'Last rebuild', 'mavo-image-index' ); ?></dt>
				<dd>
					<?php if ( ! $status['last_rebuild'] ) : ?>
						<?php esc_html_e( 'never', 'mavo-image-index' ); ?>
					<?php else : ?>
						<?php
						$parts = [];
						foreach ( $status['last_rebuild'] as $mode => $time ) {
							$parts[] = $mode . ': ' . wp_date( 'Y-m-d H:i', (int) $time );
						}
						echo esc_html( implode( ' · ', $parts ) );
						?>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Versions', 'mavo-image-index' ); ?></dt>
				<dd><?php printf( esc_html__( 'schema %1$d · dictionary %2$s', 'mavo-image-index' ), (int) $status['db_version'], '<code>' . esc_html( $status['dict_version'] ) . '</code>' ); ?></dd>
				<?php if ( $queue['attachments'] || $queue['posts'] ) : ?>
					<dt><?php esc_html_e( 'Deferred queue', 'mavo-image-index' ); ?></dt>
					<dd><?php printf( esc_html__( '%1$d images, %2$d posts waiting for WP-Cron', 'mavo-image-index' ), count( $queue['attachments'] ), count( $queue['posts'] ) ); ?></dd>
				<?php endif; ?>
			</dl>

			<h2><?php esc_html_e( 'Rebuild', 'mavo-image-index' ); ?></h2>

			<p class="mii__actions">
				<button type="button" class="button button-primary" data-mii-modes="images,usages"><?php esc_html_e( 'Rebuild all', 'mavo-image-index' ); ?></button>
				<button type="button" class="button" data-mii-modes="stale"><?php esc_html_e( 'Rebuild stale', 'mavo-image-index' ); ?></button>
				<button type="button" class="button" data-mii-modes="usages"><?php esc_html_e( 'Rebuild usages', 'mavo-image-index' ); ?></button>
			</p>
			<p class="mii__progress" id="mii-progress" aria-live="polite"></p>
			<p class="description"><?php esc_html_e( 'Keep this tab open while a rebuild runs. Stopping is safe: running it again resumes.', 'mavo-image-index' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mii__inline">
				<input type="hidden" name="action" value="mii_rebuild_attachment">
				<?php wp_nonce_field( 'mii_rebuild_attachment' ); ?>
				<label for="mii-attachment"><?php esc_html_e( 'Rebuild one attachment:', 'mavo-image-index' ); ?></label>
				<input type="number" min="1" id="mii-attachment" name="attachment_id" class="small-text" required>
				<?php submit_button( __( 'Rebuild attachment', 'mavo-image-index' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_matcher(); ?>

			<h2><?php esc_html_e( 'Top concepts', 'mavo-image-index' ); ?></h2>
			<div class="mii__columns">
				<?php foreach ( $top as $lang => $counts ) : ?>
					<table class="widefat striped mii__table">
						<thead><tr><th colspan="2"><?php echo esc_html( sprintf( __( 'From %s alt text', 'mavo-image-index' ), strtoupper( $lang ) ) ); ?></th></tr></thead>
						<tbody>
							<?php if ( ! $counts ) : ?>
								<tr><td colspan="2"><?php esc_html_e( 'None yet.', 'mavo-image-index' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $counts as $concept => $n ) : ?>
								<tr><td><?php echo esc_html( MII_Concepts::label( $concept, $lang ) ); ?> <code><?php echo esc_html( $concept ); ?></code></td><td><?php echo esc_html( number_format_i18n( $n ) ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
			</div>

			<?php self::render_targets(); ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- private */

	private static function render_matcher(): void {
		$lang = MII_Lang::default_language();
		$text = '';
		$ran  = false;

		if ( isset( $_POST['mii_test'] ) && check_admin_referer( 'mii_test' ) ) {
			$lang = MII_Lang::normalize( sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) ) ) ?? $lang;
			$text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
			$ran  = true;
		}
		?>
		<h2 id="mii-test"><?php esc_html_e( 'Test the matcher', 'mavo-image-index' ); ?></h2>
		<form method="post" action="#mii-test" class="mii__test">
			<?php wp_nonce_field( 'mii_test' ); ?>
			<select name="lang">
				<?php foreach ( MII_Lang::languages() as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $option, $lang ); ?>><?php echo esc_html( strtoupper( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="text" class="large-text" value="<?php echo esc_attr( $text ); ?>" placeholder="<?php esc_attr_e( 'Plage de sable aux eaux turquoise sous les falaises', 'mavo-image-index' ); ?>">
			<?php submit_button( __( 'Test', 'mavo-image-index' ), 'secondary', 'mii_test', false ); ?>
		</form>

		<?php if ( $ran ) : ?>
			<?php $matches = MII_Matcher::match( $text, $lang ); ?>
			<p class="description"><?php printf( esc_html__( 'Normalized: %s', 'mavo-image-index' ), '<code>' . esc_html( MII_Matcher::normalize( $text ) ) . '</code>' ); ?></p>
			<?php if ( ! $matches ) : ?>
				<p><?php esc_html_e( 'No concepts matched.', 'mavo-image-index' ); ?></p>
			<?php else : ?>
				<table class="widefat striped mii__table">
					<thead><tr>
						<th><?php esc_html_e( 'Concept', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Matched text', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Dictionary phrase', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Source', 'mavo-image-index' ); ?></th>
						<th><?php esc_html_e( 'Confidence', 'mavo-image-index' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $matches as $m ) : ?>
							<tr>
								<td><?php echo esc_html( MII_Concepts::label( $m['concept'], $lang ) ); ?> <code><?php echo esc_html( $m['concept'] ); ?></code></td>
								<td><?php echo esc_html( $m['matched'] ); ?></td>
								<td>
									<code><?php echo esc_html( $m['phrase'] ); ?></code>
									<?php if ( $m['implied_by'] ) : ?>
										<?php printf( esc_html__( '(implied by %s)', 'mavo-image-index' ), '<code>' . esc_html( $m['implied_by'] ) . '</code>' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $m['source'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $m['confidence'], 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private static function render_targets(): void {
		$lines = [];

		foreach ( MII_Shortcode::targets() as $key => $target ) {
			$lines[] = $key . ' = ' . $target;
		}
		?>
		<h2><?php esc_html_e( 'Link targets for [mavo_image_more]', 'mavo-image-index' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'One per line: “concept = target”. A target is a page ID (translated to the link’s language through Polylang) or a URL. Add “@en” to a concept for a language-specific target. “*” is the fallback for every concept, and may use {concept}, {label} and {lang}. Without a target, the shortcode prints nothing.', 'mavo-image-index' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mii_save_targets">
			<?php wp_nonce_field( 'mii_save_targets' ); ?>
			<textarea name="targets" rows="6" class="large-text code" placeholder="turquoise_water = 1234&#10;turquoise_water@de = https://…&#10;* = https://…/?s={label}"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
			<?php submit_button( __( 'Save targets', 'mavo-image-index' ), 'secondary' ); ?>
		</form>
		<?php
	}

	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = sanitize_key( $_GET['mii_notice'] ?? '' );

		if ( 'attachment' === $notice ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: attachment ID, 2: result */
					__( 'Attachment #%1$d: %2$s.', 'mavo-image-index' ),
					absint( $_GET['mii_id'] ?? 0 ),
					sanitize_key( $_GET['mii_result'] ?? '' )
				) )
			);
		} elseif ( 'targets' === $notice ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( '%d link targets saved.', 'mavo-image-index' ), absint( $_GET['mii_count'] ?? 0 ) ) )
			);
		}
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the image index.', 'mavo-image-index' ) );
		}

		check_admin_referer( $action );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args + [ 'page' => self::PAGE_SLUG ], admin_url( 'tools.php' ) ) );
		exit;
	}
}
