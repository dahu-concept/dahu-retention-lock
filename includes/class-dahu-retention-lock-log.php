<?php
/**
 * The record of what the lock refused.
 *
 * @package DahuRetentionLock
 */

defined( 'ABSPATH' ) || exit;

/**
 * A bounded list in one option. No table, no file.
 *
 * What is being recorded is rare by nature: somebody typing a retention
 * delay into a screen the lock is holding shut. A shop that sees a hundred
 * of these has a different problem from the one this plugin solves. So the
 * list is capped, oldest entries fall off, and nothing has to be installed
 * or cleaned up.
 *
 * Refusals only. Unscheduling the daily job used to be recorded here too,
 * and on a shop with one stuck Action Scheduler action that wrote an entry
 * on every single page load — wiping the fifty slots of real refusals in
 * fifty requests. The job's state belongs on the screen, where it is read
 * live, not in a record of what somebody tried to do.
 */
class Dahu_Retention_Lock_Log {

	const OPTION = 'dahu_retention_lock_log';

	/**
	 * How many entries are kept.
	 */
	const MAX = 50;

	/**
	 * Records a refused delay.
	 *
	 * @param string $option Option name.
	 * @param string $label  What that option deletes.
	 * @param string $number The delay that was refused.
	 * @param string $unit   Its unit.
	 */
	public static function record( $option, $label, $number, $unit ) {
		self::append(
			array(
				'type'   => 'refused',
				'option' => (string) $option,
				'label'  => (string) $label,
				'value'  => trim( $number . ' ' . $unit ),
			)
		);
	}

	/**
	 * Appends one entry.
	 *
	 * @param array $entry Entry without its timestamp and user.
	 */
	private static function append( array $entry ) {
		$entry['time'] = time();
		$entry['user'] = get_current_user_id();

		$log = self::all();
		array_unshift( $log, $entry );

		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empties the record.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Who made an entry, for display.
	 *
	 * @param int $user_id User id, possibly 0.
	 * @return string
	 */
	public static function who( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			// A delay can be written by WP-CLI or by code, with nobody
			// signed in. Saying "the site" is truer than leaving it blank.
			return __( 'the site', 'dahu-retention-lock' );
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : sprintf(
			/* translators: %d: a numeric user id. */
			__( 'user #%d', 'dahu-retention-lock' ),
			$user_id
		);
	}
}
