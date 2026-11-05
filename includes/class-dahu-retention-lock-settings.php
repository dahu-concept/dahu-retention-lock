<?php
/**
 * The settings screen: the switch, what is really stored, and the record.
 *
 * @package DahuRetentionLock
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option holds the switch. Off by default: putting a lock on a core
 * feature is a deliberate act, and a plugin that does it the moment it is
 * activated is a plugin that surprises somebody.
 */
class Dahu_Retention_Lock_Settings {

	const OPTION     = 'dahu_retention_lock_on';
	const GROUP      = 'dahu_retention_lock';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Is the lock on?
	 *
	 * @return bool
	 */
	public static function is_on() {
		return '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_clear_delays' ) );

		// `options.php` demands `manage_options` unless the group says
		// otherwise. Without this a shop manager sees the whole screen, the
		// switch, the stored delays and the record — and is told "Sorry, you
		// are not allowed to manage options for this site" when they save.
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'page_capability' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_screen_notice' ) );
		add_action( 'after_plugin_row_' . plugin_basename( DAHU_RETENTION_LOCK_FILE ), array( __CLASS__, 'plugins_screen_warning' ), 10, 2 );
	}

	/**
	 * The screen's stylesheet, on the two screens that use it.
	 *
	 * The WooCommerce accounts tab is one of them: the notice this plugin
	 * puts there carries its own class, and a notice styled on one screen
	 * and bare on the other is worse than one that is bare on both.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( 'woocommerce_page_' . DAHU_RETENTION_LOCK_PAGE !== $hook && ! self::on_woocommerce_privacy_screen() ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them, and a shop with six of these installed
		// downloads one copy.
		wp_enqueue_style(
			'dahu-ui',
			DAHU_RETENTION_LOCK_URL . 'assets/css/dahu-ui.css',
			array(),
			DAHU_RETENTION_LOCK_VERSION
		);

		wp_enqueue_style(
			'dahu-retention-lock-admin',
			DAHU_RETENTION_LOCK_URL . 'assets/css/admin.css',
			array( 'dahu-ui' ),
			DAHU_RETENTION_LOCK_VERSION
		);
	}

	/**
	 * The submenu entry.
	 */
	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Retention lock', 'ubac-retention' ),
			__( 'Retention lock', 'ubac-retention' ),
			self::CAPABILITY,
			DAHU_RETENTION_LOCK_PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers the switch.
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => '0',
			)
		);
	}

	/**
	 * '1' or '0', never anything else.
	 *
	 * @param mixed $input Submitted value.
	 * @return string
	 */
	public static function sanitize( $input ) {
		return '1' === (string) $input ? '1' : '0';
	}

	/**
	 * The capability `options.php` should demand for this screen's group.
	 *
	 * @return string
	 */
	public static function page_capability() {
		return self::CAPABILITY;
	}

	/**
	 * Clearing the delays WooCommerce has stored.
	 *
	 * This is the escape route. While the lock is on, WooCommerce's own
	 * field renders blank and a save of it is discarded as a no-op, so the
	 * screen that looks like the place to clear a delay is the one place it
	 * cannot be done.
	 */
	public static function handle_clear_delays() {
		if ( empty( $_POST['dahu_retention_lock_clear_delays'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ubac-retention' ) );
		}

		check_admin_referer( 'dahu_retention_lock_clear_delays' );

		$cleared = Dahu_Retention_Lock_Lock::clear_stored();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => DAHU_RETENTION_LOCK_PAGE,
					'cleared' => count( $cleared ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Emptying the record.
	 */
	public static function handle_clear() {
		if ( empty( $_POST['dahu_retention_lock_clear'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ubac-retention' ) );
		}

		check_admin_referer( 'dahu_retention_lock_clear' );

		Dahu_Retention_Lock_Log::clear();

		wp_safe_redirect( add_query_arg( 'page', DAHU_RETENTION_LOCK_PAGE, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Are we on WooCommerce's own privacy settings tab?
	 *
	 * @return bool
	 */
	private static function on_woocommerce_privacy_screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which settings tab is open.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		return 'wc-settings' === $page && 'account' === $tab;
	}

	/**
	 * The notice on WooCommerce's own retention screen.
	 *
	 * WooCommerce goes on showing those six fields; it does not know they
	 * have no effect. Without this, the next person types a delay, watches
	 * the page reload, and believes the feature is running.
	 */
	public static function woocommerce_screen_notice() {
		if ( ! self::is_on() || ! current_user_can( self::CAPABILITY ) || ! self::on_woocommerce_privacy_screen() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning dahu-retention-lock__notice"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
			esc_html__( 'Personal data retention: these fields are locked.', 'ubac-retention' ),
			esc_html__( 'All six delays below are read as empty and the daily cleanup is unhooked. You can type a value: it will not be saved, and the attempt will be recorded.', 'ubac-retention' ),
			wp_kses_post(
				sprintf(
					/* translators: %s: a link to this plugin's settings screen. */
					__( 'The lock is there because a delay typed here goes on anonymising your history <em>continuously and without warning</em>. To lift it, switch it off in %s — once you have written the retention policy that replaces it.', 'ubac-retention' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . DAHU_RETENTION_LOCK_PAGE ) ) . '">' . esc_html__( 'WooCommerce → Retention lock', 'ubac-retention' ) . '</a>'
				)
			)
		);
	}

	/**
	 * A row under this plugin on the Plugins screen, when switching it off
	 * would re-arm something.
	 *
	 * Deactivating all plugins is the first move of every WordPress
	 * troubleshooting session. With a delay stored, that move silently puts
	 * WooCommerce's anonymisation back in force and it lands unattended
	 * within a day, with nobody connecting the two. The warning belongs
	 * where the deactivation happens, not only on a screen somebody would
	 * have to think to visit.
	 *
	 * @param string $file Plugin file.
	 */
	public static function plugins_screen_warning( $file = '' ) {
		if ( ! self::is_on() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$pending = array_filter(
			Dahu_Retention_Lock_Lock::stored(),
			static function ( $row ) {
				return '' !== $row['stored'];
			}
		);

		if ( ! $pending ) {
			return;
		}

		printf(
			'<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%s <a href="%s">%s</a></p></div></td></tr>',
			esc_html(
				sprintf(
					/* translators: %d: how many delays are stored. */
					_n( 'Deactivating this plugin puts %d stored WooCommerce retention delay back into force, and your order history starts being anonymised again.', 'Deactivating this plugin puts %d stored WooCommerce retention delays back into force, and your order history starts being anonymised again.', count( $pending ), 'ubac-retention' ),
					count( $pending )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . DAHU_RETENTION_LOCK_PAGE ) ),
			esc_html__( 'Clear them first', 'ubac-retention' )
		);
	}

	/**
	 * The screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'ubac-retention' ) );
		}

		$on      = self::is_on();
		$stored  = Dahu_Retention_Lock_Lock::stored();
		$unknown = Dahu_Retention_Lock_Lock::unknown();
		$job     = Dahu_Retention_Lock_Lock::job_state();
		$hooked      = Dahu_Retention_Lock_Lock::cleanup_hooked();
		$wc_callback = Dahu_Retention_Lock_Lock::woocommerce_callback_present();
		$log     = Dahu_Retention_Lock_Log::all();
		$pending = array_filter( $stored, static function ( $row ) { return '' !== $row['stored']; } );
		?>
		<div class="wrap dahu-ui">
			<div class="dahu-ui-head">
				<h1 class="dahu-ui-head__title"><?php esc_html_e( 'Retention lock', 'ubac-retention' ); ?></h1>
				<p class="dahu-ui-head__sub"><?php esc_html_e( 'Stops anything deleting the records you are required to keep.', 'ubac-retention' ); ?></p>
			</div>
			<?php
			/*
			 * Not decoration. wp-admin/js/common.js MOVES every .notice to
			 * just after .wp-header-end, and without that marker it falls
			 * back to the page's first h1 or h2 — which on this screen is the
			 * first card's title. A refusal notice therefore landed in the
			 * middle of a card, somewhere nobody designed.
			 */
			?>
			<hr class="wp-header-end">

			<?php settings_errors( 'dahu_retention_lock' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="dahu-ui-card">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'The lock', 'ubac-retention' ); ?></th>
						<td>
							<label class="dahu-retention-lock__check">
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( $on ); ?> />
								<?php esc_html_e( 'Lock WooCommerce\'s personal data retention', 'ubac-retention' ); ?>
							</label>

							<?php if ( $pending ) : ?>
								<p class="description dahu-retention-lock__warning">
									<?php
									printf(
										/* translators: %d: how many delays are still stored. */
										esc_html( _n( 'Switching the lock off, or deactivating this plugin, would put %d stored delay back into force on the next request.', 'Switching the lock off, or deactivating this plugin, would put %d stored delays back into force on the next request.', count( $pending ), 'ubac-retention' ) ),
										count( $pending )
									);
									?>
									<?php esc_html_e( 'They are listed below. Use the button under the table to clear them — while the lock is on, WooCommerce\'s own field shows blank and a save there cannot remove them.', 'ubac-retention' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				</div>

				<?php submit_button( null, 'dahu-ui-btn dahu-ui-btn--go' ); ?>
			</form>

			<div class="dahu-ui-card">
			<div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e( 'What WooCommerce would do', 'ubac-retention' ); ?></h2></div>

			<p class="description">
				<?php esc_html_e( 'Read straight from the database, past the lock. This is what is stored, not what WooCommerce currently sees.', 'ubac-retention' ); ?>
			</p>

			<table class="widefat striped dahu-retention-lock__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'What it would delete', 'ubac-retention' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stored delay', 'ubac-retention' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What WooCommerce reads', 'ubac-retention' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stored as $option => $row ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $row['label'] ); ?>
								<br /><code class="dahu-retention-lock__key"><?php echo esc_html( $option ); ?></code>
							</td>
							<td>
								<?php if ( '' === $row['stored'] ) : ?>
									<span class="dahu-retention-lock__muted"><?php esc_html_e( 'none', 'ubac-retention' ); ?></span>
								<?php else : ?>
									<strong class="dahu-retention-lock__warning"><?php echo esc_html( $row['stored'] ); ?></strong>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $on ) : ?>
									<span class="dahu-retention-lock__ok"><?php esc_html_e( 'nothing — locked', 'ubac-retention' ); ?></span>
								<?php elseif ( '' === $row['stored'] ) : ?>
									<span class="dahu-retention-lock__muted"><?php esc_html_e( 'nothing', 'ubac-retention' ); ?></span>
								<?php else : ?>
									<strong class="dahu-retention-lock__warning"><?php echo esc_html( $row['stored'] ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pending ) : ?>
				<form method="post" class="dahu-retention-lock__clear">
					<?php wp_nonce_field( 'dahu_retention_lock_clear_delays' ); ?>
					<input type="hidden" name="dahu_retention_lock_clear_delays" value="1" />
					<?php submit_button( __( 'Clear the stored delays', 'ubac-retention' ), 'secondary', 'submit', false ); ?>
					<span class="description">
						<?php esc_html_e( 'Removes the delays above from WooCommerce\'s settings. Nothing else is touched, and no order data is changed.', 'ubac-retention' ); ?>
					</span>
				</form>
			<?php endif; ?>

			<?php if ( ! Dahu_Retention_Lock_Lock::can_check_woocommerce() ) : ?>
				<div class="notice notice-warning inline dahu-retention-lock__notice">
					<p><strong><?php esc_html_e( 'WooCommerce\'s own code could not be read, so this plugin cannot tell you whether it is still locking everything.', 'ubac-retention' ); ?></strong></p>
					<?php if ( $on ) : ?>
						<p><?php esc_html_e( 'The six settings above are still locked. What cannot be checked is whether this version of WooCommerce has added a seventh.', 'ubac-retention' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'The lock is off, so none of the six settings above is locked either.', 'ubac-retention' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $unknown ) : ?>
				<div class="notice notice-warning inline dahu-retention-lock__notice">
					<p>
						<strong><?php esc_html_e( 'This version of WooCommerce has retention settings this plugin does not know about.', 'ubac-retention' ); ?></strong>
					</p>
					<p><code><?php echo esc_html( implode( ', ', $unknown ) ); ?></code></p>
					<p><?php esc_html_e( 'They are not locked. Please report them so the list can be updated — a lock that quietly misses a field is worse than no lock, because nobody looks again.', 'ubac-retention' ); ?></p>
				</div>
			<?php endif; ?>

			</div>

			<div class="dahu-ui-card">
			<div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e( 'The daily cleanup', 'ubac-retention' ); ?></h2></div>

			<ul class="dahu-retention-lock__state">
				<li>
					<?php esc_html_e( 'Anything still attached to the cleanup hook:', 'ubac-retention' ); ?>
					<?php if ( $hooked ) : ?>
						<strong class="dahu-retention-lock__warning"><?php esc_html_e( 'yes', 'ubac-retention' ); ?></strong>
						<?php if ( $wc_callback ) : ?>
							<?php esc_html_e( '— WooCommerce\'s own, which this plugin knows how to remove.', 'ubac-retention' ); ?>
						<?php elseif ( $on ) : ?>
							<?php esc_html_e( '— but not WooCommerce\'s own. Something else is on that hook; the six settings are still locked, and that callback is not this plugin\'s to remove.', 'ubac-retention' ); ?>
						<?php else : ?>
							<?php esc_html_e( '— but not WooCommerce\'s own, and the lock is off. That callback is not this plugin\'s to remove.', 'ubac-retention' ); ?>
						<?php endif; ?>
					<?php else : ?>
						<strong class="dahu-retention-lock__ok"><?php esc_html_e( 'no', 'ubac-retention' ); ?></strong>
					<?php endif; ?>
				</li>
				<?php if ( ! empty( $job['action_scheduler_running'] ) ) : ?>
					<li>
						<strong class="dahu-retention-lock__warning"><?php esc_html_e( 'A cleanup run is in progress, or was left that way.', 'ubac-retention' ); ?></strong>
						<?php esc_html_e( 'An in-progress action cannot be cancelled; it will finish or be cleaned up by Action Scheduler. It finds nothing to delete while the lock is on.', 'ubac-retention' ); ?>
					</li>
				<?php endif; ?>
				<li>
					<?php esc_html_e( 'Scheduled through Action Scheduler:', 'ubac-retention' ); ?>
					<?php if ( null === $job['action_scheduler'] ) : ?>
						<span class="dahu-retention-lock__muted"><?php esc_html_e( 'Action Scheduler is not available', 'ubac-retention' ); ?></span>
					<?php else : ?>
						<strong class="<?php echo $job['action_scheduler'] ? 'dahu-retention-lock__warning' : 'dahu-retention-lock__ok'; ?>">
							<?php echo $job['action_scheduler'] ? esc_html__( 'yes', 'ubac-retention' ) : esc_html__( 'no', 'ubac-retention' ); ?>
						</strong>
					<?php endif; ?>
				</li>
				<li>
					<?php esc_html_e( 'Scheduled through WP-Cron:', 'ubac-retention' ); ?>
					<strong class="<?php echo $job['wp_cron'] ? 'dahu-retention-lock__warning' : 'dahu-retention-lock__ok'; ?>">
						<?php echo $job['wp_cron'] ? esc_html__( 'yes', 'ubac-retention' ) : esc_html__( 'no', 'ubac-retention' ); ?>
					</strong>
				</li>
			</ul>

			</div>

			<div class="dahu-ui-card">
			<div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title"><?php esc_html_e( 'What the lock refused', 'ubac-retention' ); ?></h2></div>

			<?php if ( ! $log ) : ?>
				<?php if ( $on ) : ?>
					<p class="dahu-ui-hint"><?php esc_html_e( 'Nothing refused yet; every deletion the lock turns away is listed here.', 'ubac-retention' ); ?></p>
				<?php else : ?>
					<p class="dahu-ui-hint"><?php esc_html_e( 'The lock is off, so nothing is being refused.', 'ubac-retention' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<table class="widefat striped dahu-retention-lock__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'ubac-retention' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What', 'ubac-retention' ); ?></th>
							<th scope="col"><?php esc_html_e( 'By', 'ubac-retention' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
								<td>
									<?php
									printf(
										/* translators: 1: the setting's name, 2: the delay that was refused. */
										esc_html__( 'Refused: %1$s (%2$s)', 'ubac-retention' ),
										esc_html( $entry['label'] ),
										esc_html( $entry['value'] )
									);
									?>
								</td>
								<td><?php echo esc_html( Dahu_Retention_Lock_Log::who( isset( $entry['user'] ) ? $entry['user'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post">
					<?php wp_nonce_field( 'dahu_retention_lock_clear' ); ?>
					<input type="hidden" name="dahu_retention_lock_clear" value="1" />
					<?php submit_button( __( 'Clear the record', 'ubac-retention' ), 'secondary', 'submit', true ); ?>
				</form>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
