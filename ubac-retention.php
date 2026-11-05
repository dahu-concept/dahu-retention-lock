<?php
/**
 * Plugin Name:       Ubac Retention Lock
 * Description:       Locks WooCommerce's automatic personal-data retention so it cannot quietly anonymise or bin your order history. The six retention delays are read as empty, any attempt to set one is refused and recorded, and the daily cleanup is unhooked. Nothing is ever deleted by this plugin — it stops deletions.
 * Version:           1.2.1
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-retention
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package DahuRetentionLock
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_RETENTION_LOCK_VERSION', '1.2.1' );
define( 'DAHU_RETENTION_LOCK_FILE', __FILE__ );
define( 'DAHU_RETENTION_LOCK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_RETENTION_LOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_RETENTION_LOCK_PAGE', 'dahu-retention-lock' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Autoloader
 * ---------------------------------------------------------------------- */

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Dahu_Retention_Lock_' ) ) {
			return;
		}

		$file = DAHU_RETENTION_LOCK_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * HPOS
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DAHU_RETENTION_LOCK_FILE, true );
		}
	}
);

/* -------------------------------------------------------------------------
 * Bootstrap
 *
 * The lock itself goes on early — priority 5 on `plugins_loaded` — because
 * WooCommerce reads these options during its own start-up, and a filter
 * registered after the read it was meant to catch protects nothing.
 *
 * The settings screen waits for `is_admin()`, as usual.
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', array( 'Dahu_Retention_Lock_Lock', 'init' ), 5 );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'Dahu Retention Lock needs WooCommerce. It is doing nothing until WooCommerce is active.', 'ubac-retention' )
					);
				}
			);

			return;
		}

		if ( is_admin() ) {
			Dahu_Retention_Lock_Settings::init();
		}
	},
	20
);

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . DAHU_RETENTION_LOCK_PAGE ) ),
				esc_html__( 'Settings', 'ubac-retention' )
			)
		);

		return $links;
	}
);

/* -------------------------------------------------------------------------
 * Deactivation
 *
 * Nothing to clean up, and that is the point: this plugin writes no order
 * data and deletes none. Turning it off restores WooCommerce's own
 * behaviour on the next request — including, if a delay is still stored in
 * the database, the deletions that delay asks for. The settings screen says
 * so in as many words, with the stored values on display.
 * ---------------------------------------------------------------------- */

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_RETENTION_LOCK_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-retention-lock' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-retention-lock',
				'name'       => __( 'Retention lock', 'ubac-retention' ),
				'tagline'    => __( 'Stops anything deleting the records you are required to keep.', 'ubac-retention' ),
				'file'       => DAHU_RETENTION_LOCK_FILE,
				'version'    => DAHU_RETENTION_LOCK_VERSION,
				'textdomain' => 'dahu-retention-lock',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M24.0 48.0 H88.0 A10.0 10.0 0 0 1 98.0 58.0 V96.0 A10.0 10.0 0 0 1 88.0 106.0 H24.0 A10.0 10.0 0 0 1 14.0 96.0 V58.0 A10.0 10.0 0 0 1 24.0 48.0 Z',
						'M34 48 L34 30 a22 22 0 0 1 44 0 L78 48',
						'M14 70 L98 70',
						'M38 88 L50 88 M64 88 L76 88',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-retention' ),
					'done'   => __( 'Done', 'ubac-retention' ),
					'skip'   => __( 'Skip', 'ubac-retention' ),
					'of'     => __( 'of', 'ubac-retention' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-retention' ),
				),

				/*
				 * The one-time tour. Each selector points at something the
				 * screen renders on a fresh install: the switch, the first
				 * table, the cleanup list. The refusal record is left out on
				 * purpose -- its table only exists once something was refused.
				 */
				'tour_screen' => 'woocommerce_page_dahu-retention-lock',
				'tour'        => array(
					array(
						'selector' => 'input[name="dahu_retention_lock_on"]',
						'title'    => __( 'Switch the lock on', 'ubac-retention' ),
						'text'     => __( 'It is off until you decide. Nothing is locked before then.', 'ubac-retention' ),
					),
					array(
						'selector' => '.dahu-retention-lock__table',
						'title'    => __( 'What is stored', 'ubac-retention' ),
						'text'     => __( 'The six delays WooCommerce would act on, read past the lock.', 'ubac-retention' ),
					),
					array(
						'selector' => '.dahu-retention-lock__state',
						'title'    => __( 'The daily cleanup', 'ubac-retention' ),
						'text'     => __( 'Whether the job that deletes is still scheduled.', 'ubac-retention' ),
					),
				),
				'points'     => array(
				array(
					'title' => __( 'A rule, not a habit', 'ubac-retention' ),
					'text'  => __( 'Say how long, and the rule holds for every path.', 'ubac-retention' ),
				),
				array(
					'title' => __( 'Refusals are visible', 'ubac-retention' ),
					'text'  => __( 'A blocked deletion says who tried and why it was refused.', 'ubac-retention' ),
				),
				array(
					'title' => __( 'Nothing silent', 'ubac-retention' ),
					'text'  => __( 'The lock never pretends a deletion happened.', 'ubac-retention' ),
				),
				),
			)
		);
	}
);
