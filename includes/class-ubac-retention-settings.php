<?php
/**
 * The settings screen: the switch, what is really stored, and the record.
 *
 * @package UbacRetention
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option holds the switch. Off by default: putting a lock on a core
 * feature is a deliberate act, and a plugin that does it the moment it is
 * activated is a plugin that surprises somebody.
 */
class Ubac_Retention_Settings {

	const OPTION     = 'ubac_retention_on';
	const GROUP      = 'ubac_retention';
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
		add_action( 'after_plugin_row_' . plugin_basename( UBAC_RETENTION_FILE ), array( __CLASS__, 'plugins_screen_warning' ), 10, 2 );
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
		if ( 'woocommerce_page_' . UBAC_RETENTION_PAGE !== $hook && ! self::on_woocommerce_privacy_screen() ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them, and a shop with six of these installed
		// downloads one copy.
		wp_enqueue_style(
			'ubac-ui',
			UBAC_RETENTION_URL . 'assets/css/ubac-ui.css',
			array(),
			UBAC_RETENTION_VERSION
		);

		wp_enqueue_style(
			'ubac-retention-admin',
			UBAC_RETENTION_URL . 'assets/css/admin.css',
			array( 'ubac-ui' ),
			UBAC_RETENTION_VERSION
		);
	}

	/**
	 * The submenu entry.
	 */
	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Retention lock', 'dahuconcept-retention-lock' ),
			__( 'Retention lock', 'dahuconcept-retention-lock' ),
			self::CAPABILITY,
			UBAC_RETENTION_PAGE,
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
		if ( empty( $_POST['ubac_retention_clear_delays'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dahuconcept-retention-lock' ) );
		}

		check_admin_referer( 'ubac_retention_clear_delays' );

		$cleared = Ubac_Retention_Lock::clear_stored();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => UBAC_RETENTION_PAGE,
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
		if ( empty( $_POST['ubac_retention_clear'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dahuconcept-retention-lock' ) );
		}

		check_admin_referer( 'ubac_retention_clear' );

		Ubac_Retention_Log::clear();

		wp_safe_redirect( add_query_arg( 'page', UBAC_RETENTION_PAGE, admin_url( 'admin.php' ) ) );
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
			'<div class="notice notice-warning ubac-retention__notice"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
			esc_html__( 'Personal data retention: these fields are locked.', 'dahuconcept-retention-lock' ),
			esc_html__( 'All six delays below are read as empty and the daily cleanup is unhooked. You can type a value: it will not be saved, and the attempt will be recorded.', 'dahuconcept-retention-lock' ),
			wp_kses_post(
				sprintf(
					/* translators: %s: a link to this plugin's settings screen. */
					__( 'The lock is there because a delay typed here goes on anonymising your history <em>continuously and without warning</em>. To lift it, switch it off in %s — once you have written the retention policy that replaces it.', 'dahuconcept-retention-lock' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . UBAC_RETENTION_PAGE ) ) . '">' . esc_html__( 'WooCommerce → Retention lock', 'dahuconcept-retention-lock' ) . '</a>'
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
			Ubac_Retention_Lock::stored(),
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
					_n( 'Deactivating this plugin puts %d stored WooCommerce retention delay back into force, and your order history starts being anonymised again.', 'Deactivating this plugin puts %d stored WooCommerce retention delays back into force, and your order history starts being anonymised again.', count( $pending ), 'dahuconcept-retention-lock' ),
					count( $pending )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . UBAC_RETENTION_PAGE ) ),
			esc_html__( 'Clear them first', 'dahuconcept-retention-lock' )
		);
	}

	/**
	 * The screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'dahuconcept-retention-lock' ) );
		}

		$on      = self::is_on();
		$stored  = Ubac_Retention_Lock::stored();
		$unknown = Ubac_Retention_Lock::unknown();
		$job     = Ubac_Retention_Lock::job_state();
		$hooked      = Ubac_Retention_Lock::cleanup_hooked();
		$wc_callback = Ubac_Retention_Lock::woocommerce_callback_present();
		$log     = Ubac_Retention_Log::all();
		$pending = array_filter( $stored, static function ( $row ) { return '' !== $row['stored']; } );
		?>
		<div class="wrap ubac-ui">
			<div class="ubac-ui-head">
				<h1 class="ubac-ui-head__title"><?php esc_html_e( 'Retention lock', 'dahuconcept-retention-lock' ); ?></h1>
				<p class="ubac-ui-head__sub"><?php esc_html_e( 'Stops anything deleting the records you are required to keep.', 'dahuconcept-retention-lock' ); ?></p>
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

			<?php settings_errors( 'ubac_retention' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="ubac-ui-card">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'The lock', 'dahuconcept-retention-lock' ); ?></th>
						<td>
							<label class="ubac-retention__check">
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( $on ); ?> />
								<?php esc_html_e( 'Lock WooCommerce\'s personal data retention', 'dahuconcept-retention-lock' ); ?>
							</label>

							<?php if ( $pending ) : ?>
								<p class="description ubac-retention__warning">
									<?php
									printf(
										/* translators: %d: how many delays are still stored. */
										esc_html( _n( 'Switching the lock off, or deactivating this plugin, would put %d stored delay back into force on the next request.', 'Switching the lock off, or deactivating this plugin, would put %d stored delays back into force on the next request.', count( $pending ), 'dahuconcept-retention-lock' ) ),
										count( $pending )
									);
									?>
									<?php esc_html_e( 'They are listed below. Use the button under the table to clear them — while the lock is on, WooCommerce\'s own field shows blank and a save there cannot remove them.', 'dahuconcept-retention-lock' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				</div>

				<?php submit_button( null, 'ubac-ui-btn ubac-ui-btn--go' ); ?>
			</form>

			<div class="ubac-ui-card">
			<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title"><?php esc_html_e( 'What WooCommerce would do', 'dahuconcept-retention-lock' ); ?></h2></div>

			<p class="description">
				<?php esc_html_e( 'Read straight from the database, past the lock. This is what is stored, not what WooCommerce currently sees.', 'dahuconcept-retention-lock' ); ?>
			</p>

			<table class="widefat striped ubac-retention__table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'What it would delete', 'dahuconcept-retention-lock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stored delay', 'dahuconcept-retention-lock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What WooCommerce reads', 'dahuconcept-retention-lock' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stored as $option => $row ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $row['label'] ); ?>
								<br /><code class="ubac-retention__key"><?php echo esc_html( $option ); ?></code>
							</td>
							<td>
								<?php if ( '' === $row['stored'] ) : ?>
									<span class="ubac-retention__muted"><?php esc_html_e( 'none', 'dahuconcept-retention-lock' ); ?></span>
								<?php else : ?>
									<strong class="ubac-retention__warning"><?php echo esc_html( $row['stored'] ); ?></strong>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $on ) : ?>
									<span class="ubac-retention__ok"><?php esc_html_e( 'nothing — locked', 'dahuconcept-retention-lock' ); ?></span>
								<?php elseif ( '' === $row['stored'] ) : ?>
									<span class="ubac-retention__muted"><?php esc_html_e( 'nothing', 'dahuconcept-retention-lock' ); ?></span>
								<?php else : ?>
									<strong class="ubac-retention__warning"><?php echo esc_html( $row['stored'] ); ?></strong>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pending ) : ?>
				<form method="post" class="ubac-retention__clear">
					<?php wp_nonce_field( 'ubac_retention_clear_delays' ); ?>
					<input type="hidden" name="ubac_retention_clear_delays" value="1" />
					<?php submit_button( __( 'Clear the stored delays', 'dahuconcept-retention-lock' ), 'secondary', 'submit', false ); ?>
					<span class="description">
						<?php esc_html_e( 'Removes the delays above from WooCommerce\'s settings. Nothing else is touched, and no order data is changed.', 'dahuconcept-retention-lock' ); ?>
					</span>
				</form>
			<?php endif; ?>

			<?php if ( ! Ubac_Retention_Lock::can_check_woocommerce() ) : ?>
				<div class="notice notice-warning inline ubac-retention__notice">
					<p><strong><?php esc_html_e( 'WooCommerce\'s own code could not be read, so this plugin cannot tell you whether it is still locking everything.', 'dahuconcept-retention-lock' ); ?></strong></p>
					<?php if ( $on ) : ?>
						<p><?php esc_html_e( 'The six settings above are still locked. What cannot be checked is whether this version of WooCommerce has added a seventh.', 'dahuconcept-retention-lock' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'The lock is off, so none of the six settings above is locked either.', 'dahuconcept-retention-lock' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $unknown ) : ?>
				<div class="notice notice-warning inline ubac-retention__notice">
					<p>
						<strong><?php esc_html_e( 'This version of WooCommerce has retention settings this plugin does not know about.', 'dahuconcept-retention-lock' ); ?></strong>
					</p>
					<p><code><?php echo esc_html( implode( ', ', $unknown ) ); ?></code></p>
					<p><?php esc_html_e( 'They are not locked. Please report them so the list can be updated — a lock that quietly misses a field is worse than no lock, because nobody looks again.', 'dahuconcept-retention-lock' ); ?></p>
				</div>
			<?php endif; ?>

			</div>

			<div class="ubac-ui-card">
			<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title"><?php esc_html_e( 'The daily cleanup', 'dahuconcept-retention-lock' ); ?></h2></div>

			<ul class="ubac-retention__state">
				<li>
					<?php esc_html_e( 'Anything still attached to the cleanup hook:', 'dahuconcept-retention-lock' ); ?>
					<?php if ( $hooked ) : ?>
						<strong class="ubac-retention__warning"><?php esc_html_e( 'yes', 'dahuconcept-retention-lock' ); ?></strong>
						<?php if ( $wc_callback ) : ?>
							<?php esc_html_e( '— WooCommerce\'s own, which this plugin knows how to remove.', 'dahuconcept-retention-lock' ); ?>
						<?php elseif ( $on ) : ?>
							<?php esc_html_e( '— but not WooCommerce\'s own. Something else is on that hook; the six settings are still locked, and that callback is not this plugin\'s to remove.', 'dahuconcept-retention-lock' ); ?>
						<?php else : ?>
							<?php esc_html_e( '— but not WooCommerce\'s own, and the lock is off. That callback is not this plugin\'s to remove.', 'dahuconcept-retention-lock' ); ?>
						<?php endif; ?>
					<?php else : ?>
						<strong class="ubac-retention__ok"><?php esc_html_e( 'no', 'dahuconcept-retention-lock' ); ?></strong>
					<?php endif; ?>
				</li>
				<?php if ( ! empty( $job['action_scheduler_running'] ) ) : ?>
					<li>
						<strong class="ubac-retention__warning"><?php esc_html_e( 'A cleanup run is in progress, or was left that way.', 'dahuconcept-retention-lock' ); ?></strong>
						<?php esc_html_e( 'An in-progress action cannot be cancelled; it will finish or be cleaned up by Action Scheduler. It finds nothing to delete while the lock is on.', 'dahuconcept-retention-lock' ); ?>
					</li>
				<?php endif; ?>
				<li>
					<?php esc_html_e( 'Scheduled through Action Scheduler:', 'dahuconcept-retention-lock' ); ?>
					<?php if ( null === $job['action_scheduler'] ) : ?>
						<span class="ubac-retention__muted"><?php esc_html_e( 'Action Scheduler is not available', 'dahuconcept-retention-lock' ); ?></span>
					<?php else : ?>
						<strong class="<?php echo $job['action_scheduler'] ? 'ubac-retention__warning' : 'ubac-retention__ok'; ?>">
							<?php echo $job['action_scheduler'] ? esc_html__( 'yes', 'dahuconcept-retention-lock' ) : esc_html__( 'no', 'dahuconcept-retention-lock' ); ?>
						</strong>
					<?php endif; ?>
				</li>
				<li>
					<?php esc_html_e( 'Scheduled through WP-Cron:', 'dahuconcept-retention-lock' ); ?>
					<strong class="<?php echo $job['wp_cron'] ? 'ubac-retention__warning' : 'ubac-retention__ok'; ?>">
						<?php echo $job['wp_cron'] ? esc_html__( 'yes', 'dahuconcept-retention-lock' ) : esc_html__( 'no', 'dahuconcept-retention-lock' ); ?>
					</strong>
				</li>
			</ul>

			</div>

			<div class="ubac-ui-card">
			<div class="ubac-ui-card__head"><h2 class="ubac-ui-card__title"><?php esc_html_e( 'What the lock refused', 'dahuconcept-retention-lock' ); ?></h2></div>

			<?php if ( ! $log ) : ?>
				<?php if ( $on ) : ?>
					<p class="ubac-ui-hint"><?php esc_html_e( 'Nothing refused yet; every deletion the lock turns away is listed here.', 'dahuconcept-retention-lock' ); ?></p>
				<?php else : ?>
					<p class="ubac-ui-hint"><?php esc_html_e( 'The lock is off, so nothing is being refused.', 'dahuconcept-retention-lock' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<table class="widefat striped ubac-retention__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'dahuconcept-retention-lock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What', 'dahuconcept-retention-lock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'By', 'dahuconcept-retention-lock' ); ?></th>
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
										esc_html__( 'Refused: %1$s (%2$s)', 'dahuconcept-retention-lock' ),
										esc_html( $entry['label'] ),
										esc_html( $entry['value'] )
									);
									?>
								</td>
								<td><?php echo esc_html( Ubac_Retention_Log::who( isset( $entry['user'] ) ? $entry['user'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post">
					<?php wp_nonce_field( 'ubac_retention_clear' ); ?>
					<input type="hidden" name="ubac_retention_clear" value="1" />
					<?php submit_button( __( 'Clear the record', 'dahuconcept-retention-lock' ), 'secondary', 'submit', true ); ?>
				</form>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
