<?php
/**
 * Uninstall.
 *
 * Two options go: the switch and the record of refusals.
 *
 * **WooCommerce's stored delays are not touched.** They are the shop's
 * settings, not this plugin's, and this plugin never wrote them. Clearing
 * them on the way out would be a deletion made by a plugin whose whole
 * purpose is to stop deletions somebody did not ask for.
 *
 * Which means uninstalling puts any stored delay straight back into force.
 * The settings screen says so, with those delays on display, while the
 * plugin is still there to say it.
 *
 * @package UbacRetention
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clears this plugin's options for one site.
 *
 * A function rather than the same loop written twice: the single-site branch
 * and the per-site branch of a network have to delete exactly the same things,
 * and two copies of a list are two chances for them to drift. Eleven other
 * plugins of this family already answer to `<slug>_uninstall_site()`.
 *
 * The last three options are the welcome screen's own flags: interface state,
 * not the shop's data. `includes/ubac-welcome.php` writes them with
 * update_option(), so they outlive the plugin unless they are deleted here,
 * and a reinstall would never greet anybody again — the screen and the guided
 * tour staying silent for good, with nothing on screen to explain why.
 *
 * @return void
 */
function ubac_retention_uninstall_site() {

	$options = array(
		'ubac_retention_on',
		'ubac_retention_log',
		'ubac_welcome_seen_ubac-retention',
		'ubac_welcome_pending_ubac-retention',
		'ubac_welcome_tour_ubac-retention',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {

	// `number => 0`: without it WP_Site_Query stops at the first hundred sites
	// and the options stay behind on every other one.
	$ubac_retention_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_retention_sites as $ubac_retention_site ) {
		switch_to_blog( (int) $ubac_retention_site );
		ubac_retention_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_retention_uninstall_site();
}
