<?php
/**
 * Plugin Name:       Ubac Retention Lock
 * Description:       Locks WooCommerce's automatic personal-data retention so it cannot quietly anonymise or bin your order history. The six retention delays are read as empty, any attempt to set one is refused and recorded, and the daily cleanup is unhooked. Nothing is ever deleted by this plugin — it stops deletions.
 * Version:           1.2.2
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-retention
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package UbacRetention
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_RETENTION_VERSION', '1.2.2' );
define( 'UBAC_RETENTION_FILE', __FILE__ );
define( 'UBAC_RETENTION_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_RETENTION_URL', plugin_dir_url( __FILE__ ) );
define( 'UBAC_RETENTION_PAGE', 'ubac-retention' );

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
		if ( 0 !== strpos( $class, 'Ubac_Retention_' ) ) {
			return;
		}

		$file = UBAC_RETENTION_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

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
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UBAC_RETENTION_FILE, true );
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

add_action( 'plugins_loaded', array( 'Ubac_Retention_Lock', 'init' ), 5 );

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
						esc_html__( 'Ubac Retention Lock needs WooCommerce. It is doing nothing until WooCommerce is active.', 'ubac-retention' )
					);
				}
			);

			return;
		}

		if ( is_admin() ) {
			Ubac_Retention_Settings::init();
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
				esc_url( admin_url( 'admin.php?page=' . UBAC_RETENTION_PAGE ) ),
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
require_once UBAC_RETENTION_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-retention' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-retention',
				'name'       => __( 'Retention lock', 'ubac-retention' ),
				'tagline'    => __( 'Stops anything deleting the records you are required to keep.', 'ubac-retention' ),
				'file'       => UBAC_RETENTION_FILE,
				'version'    => UBAC_RETENTION_VERSION,
				'textdomain' => 'ubac-retention',
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
				'tour_screen' => 'woocommerce_page_ubac-retention',
				'tour'        => array(
					array(
						'selector' => 'input[name="ubac_retention_on"]',
						'title'    => __( 'Switch the lock on', 'ubac-retention' ),
						'text'     => __( 'It is off until you decide. Nothing is locked before then.', 'ubac-retention' ),
					),
					array(
						'selector' => '.ubac-retention__table',
						'title'    => __( 'What is stored', 'ubac-retention' ),
						'text'     => __( 'The six delays WooCommerce would act on, read past the lock.', 'ubac-retention' ),
					),
					array(
						'selector' => '.ubac-retention__state',
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
