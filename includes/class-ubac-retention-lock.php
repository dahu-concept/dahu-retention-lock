<?php
/**
 * The lock itself: three layers over WooCommerce's personal-data retention.
 *
 * @package UbacRetention
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce can delete and anonymise order data on its own, on a delay set
 * in Settings → Accounts & Privacy → Personal data retention. Six fields, a
 * daily job, no record of what it removed.
 *
 * That is a good feature for a shop that chose it. It is a dangerous one for
 * a shop that does not know it is there: a delay typed once goes on
 * anonymising years of history, quietly — and the after-sales trail, the
 * accounting and the customer relationship go with it.
 *
 * This class does not disable the feature. It locks it:
 *
 *   1. READ  — the six options are read as empty. Whatever is stored,
 *      WooCommerce sees no delay and deletes nothing.
 *   2. WRITE — an attempt to save a delay is refused, recorded, and said out
 *      loud on screen. A setting blocked in silence is a setting somebody
 *      finds years later without understanding it.
 *   3. JOB   — the daily cleanup is unhooked and unscheduled.
 *
 * **Layer 1 is the one that protects.** The other two are visibility and
 * tidiness. That is measured, not assumed: WooCommerce's job pushes six
 * tasks onto a background queue, and each task re-reads its option through
 * `get_option()` before doing anything. Tasks already queued when the lock
 * goes on therefore read empty and stop by themselves. Saying the three
 * layers are "redundant" would be comfortable and wrong — it would leave a
 * reader thinking any one of them would do.
 *
 * Nothing here deletes or edits any data. It stops WooCommerce from doing so.
 */
class Ubac_Retention_Lock {

	/**
	 * The hook WooCommerce fires to start the daily cleanup.
	 */
	const CLEANUP_HOOK = 'woocommerce_cleanup_personal_data';

	/**
	 * Which of the six options have a row, or null before it is looked up.
	 *
	 * @var array<string,bool>|null
	 */
	private static $rows = null;

	/**
	 * The six options, and what each one deletes.
	 *
	 * Six, not five. `woocommerce_anonymize_refunded_orders` is easy to miss
	 * — it sits between the cancelled and the completed field on a long
	 * screen — and a lock that misses it leaves refunded orders being
	 * anonymised while reporting itself as on. The list is checked against
	 * WooCommerce at run time by `unknown()` below, so a field added in a
	 * future version is reported rather than silently unguarded.
	 *
	 * @return array<string,string> Option name => what it does.
	 */
	public static function options() {
		return array(
			'woocommerce_trash_pending_orders'       => __( 'Bin orders awaiting payment', 'ubac-retention' ),
			'woocommerce_trash_failed_orders'        => __( 'Bin failed orders', 'ubac-retention' ),
			'woocommerce_trash_cancelled_orders'     => __( 'Bin cancelled orders', 'ubac-retention' ),
			'woocommerce_anonymize_refunded_orders'  => __( 'Anonymise refunded orders', 'ubac-retention' ),
			'woocommerce_anonymize_completed_orders' => __( 'Anonymise completed orders', 'ubac-retention' ),
			'woocommerce_delete_inactive_accounts'   => __( 'Delete inactive customer accounts', 'ubac-retention' ),
		);
	}

	/**
	 * The retention options WooCommerce actually reads, read from its source.
	 *
	 * Not from a list written here: a list written here is a list that goes
	 * out of date in silence, which is the failure this plugin exists to
	 * prevent, one level up. WooCommerce gains a retention field, this
	 * plugin keeps reporting itself as locked, and that field goes on
	 * deleting.
	 *
	 * Read at the tokenizer, never at a regular expression — this project
	 * has paid seven times for the difference. A name in a comment or a
	 * docblock is not a call.
	 *
	 * @return string[] Option names, newest list first. Empty when
	 *                  WooCommerce's file cannot be read, which is its own
	 *                  answer: nothing can be compared.
	 */
	public static function woocommerce_options() {
		if ( ! defined( 'WC_ABSPATH' ) ) {
			return array();
		}

		return self::options_read_by( WC_ABSPATH . 'includes/class-wc-privacy.php' );
	}

	/**
	 * The `woocommerce_*` options a PHP file passes to `get_option()`.
	 *
	 * Split out from the method above so it can be pointed at a file, which
	 * is the only way to check it against anything but the one WooCommerce
	 * that happens to be installed. The bench used to assert this behaviour
	 * against its own copy of the loop — a test of a transcription, which
	 * stayed green while the real scanner was narrowed to one spelling.
	 *
	 * @param string $file Path to read.
	 * @return string[] Option names.
	 */
	public static function options_read_by( $file ) {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$source = (string) file_get_contents( $file );
		$found  = array();
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		// `get_option` followed by `(` followed by a quoted string. Not a
		// substring search for one exact spelling: the first version matched
		// only `get_option( 'x' )`, and missed `get_option('x')`,
		// `get_option( "x" )` and `get_option( 'x', array() )` — three of the
		// four ways WooCommerce could reasonably write the same call, each
		// miss silent. A detector whose promise is "it will tell you when
		// WooCommerce moves" cannot be that fragile.
		for ( $i = 0; $i < $count - 2; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 'get_option' !== $tokens[ $i ][1] ) {
				continue;
			}

			$j = $i + 1;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}

			if ( $j >= $count || ! is_string( $tokens[ $j ] ) || '(' !== $tokens[ $j ] ) {
				continue;
			}

			$j++;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}

			if ( $j >= $count || ! is_array( $tokens[ $j ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
				continue;
			}

			$value = trim( $tokens[ $j ][1], "'\"" );

			if ( 0 === strpos( $value, 'woocommerce_' ) ) {
				$found[ $value ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Retention options WooCommerce reads that this plugin does not lock.
	 *
	 * @return string[] Option names, or an empty array.
	 */
	public static function unknown() {
		return array_values( array_diff( self::woocommerce_options(), array_keys( self::options() ) ) );
	}

	/**
	 * Could WooCommerce's source be read at all?
	 *
	 * `unknown()` returns an empty array both when nothing is missing and
	 * when nothing could be read — open_basedir, a hardening plugin, a
	 * vendored WooCommerce. The screen has to be able to tell those apart,
	 * because the second one is the drift detector silently not working.
	 *
	 * @return bool
	 */
	public static function can_check_woocommerce() {
		return array() !== self::woocommerce_options();
	}

	/**
	 * Is the lock on?
	 *
	 * @return bool
	 */
	public static function is_on() {
		return Ubac_Retention_Settings::is_on();
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! self::is_on() ) {
			return;
		}

		foreach ( array_keys( self::options() ) as $option ) {
			add_filter( 'option_' . $option, array( __CLASS__, 'read' ), 999 );
			add_filter( 'default_option_' . $option, array( __CLASS__, 'read_default' ), 999, 2 );
			add_filter( 'pre_update_option_' . $option, array( __CLASS__, 'refuse' ), 999, 3 );
		}

		// `add_option()` has no `pre_` filter of its own. Its only guard is
		// to compare the filtered default with `get_option()`, so a lock
		// that filters both sides makes them equal and turns `add_option()`
		// from a safe no-op into a silent overwrite of the live row. The
		// default filter above is therefore row-aware; this records and
		// undoes the write that still gets through on a row that did not
		// exist yet.
		add_action( 'added_option', array( __CLASS__, 'undo_added' ), 1, 2 );

		// The row cache above has to follow the table. Without this, an
		// option deleted or added earlier in the same request leaves
		// `add_option()`'s guard reading a state that no longer exists.
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $event ) {
			add_action( $event, array( __CLASS__, 'forget_rows' ), 99 );
		}

		add_action( 'init', array( __CLASS__, 'unhook_cleanup' ), 20 );
	}

	/**
	 * What WooCommerce reads instead of whatever is stored.
	 *
	 * `array( 'number' => '', 'unit' => 'days' )` rather than `false` or an
	 * empty array. All four disable the feature — `wc_parse_relative_date_option()`
	 * casts to an array and fills the defaults, and every call site then
	 * checks `empty( $option['number'] )`. But WooCommerce's settings screen
	 * and any third-party code may read `$option['number']` directly, and the
	 * explicit shape is the one that survives that without a notice.
	 *
	 * @return array{number:string,unit:string}
	 */
	public static function read() {
		return array(
			'number' => '',
			'unit'   => 'days',
		);
	}

	/**
	 * The same, but only for an option that has no row.
	 *
	 * `default_option_{$option}` is consulted by `get_option()` when the row
	 * is missing — which is the case worth covering, because another plugin
	 * may filter a non-empty default into it — and also by `add_option()`'s
	 * existence guard, where returning the locked shape breaks the guard.
	 * So: the locked shape when there is genuinely no row, and the caller's
	 * own default when there is one.
	 *
	 * @param mixed  $default_value What WordPress was going to use.
	 * @param string $option        Option name.
	 * @return mixed
	 */
	public static function read_default( $default_value, $option = '' ) {
		return self::row_exists( $option ) ? $default_value : self::read();
	}

	/**
	 * Does this option have a row of its own?
	 *
	 * One query for all six, once per request. Read straight from the
	 * table, because every other way of asking goes back through the
	 * filters above.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function row_exists( $option ) {
		global $wpdb;

		if ( null === self::$rows ) {
			$names  = array_keys( self::options() );
			$ph     = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- generated placeholders only.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ( {$ph} )", $names ) );

			self::$rows = array_fill_keys( (array) $found, true );
		}

		return isset( self::$rows[ $option ] );
	}

	/**
	 * Forgets the cached row list.
	 *
	 * Public because the cache can go stale from outside: anything that
	 * writes these options with `$wpdb` directly — a migration, an import,
	 * a test — leaves it describing a table that has moved. The three
	 * option actions below cover the ordinary paths; this covers the rest.
	 */
	public static function forget_rows() {
		self::$rows = null;
	}

	/**
	 * Undoes and records a delay that `add_option()` slipped through.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  What was written.
	 */
	public static function undo_added( $option, $value ) {
		$options = self::options();

		if ( ! isset( $options[ $option ] ) || ! is_array( $value ) || empty( $value['number'] ) ) {
			return;
		}

		delete_option( $option );
		self::forget_rows();

		Ubac_Retention_Log::record( $option, $options[ $option ], (string) $value['number'], isset( $value['unit'] ) ? (string) $value['unit'] : '' );
	}

	/**
	 * Refuses a delay, records it, and says so on screen.
	 *
	 * Returning the old value cancels the update with no error of its own —
	 * which is why the record and the notice matter. WordPress would
	 * otherwise answer "Settings saved" to somebody who had just been
	 * overruled.
	 *
	 * @param mixed  $new    Submitted value.
	 * @param mixed  $old    Stored value.
	 * @param string $option Option name.
	 * @return mixed The old value, always.
	 */
	public static function refuse( $new, $old, $option ) {
		if ( ! is_array( $new ) || empty( $new['number'] ) ) {
			// Somebody clearing a delay, or saving the screen without
			// touching it. Nothing to refuse and nothing to report: a lock
			// that cries out when a field is emptied is a lock that gets
			// switched off.
			return $old;
		}

		$labels = self::options();
		$label  = isset( $labels[ $option ] ) ? $labels[ $option ] : $option;
		$unit   = isset( $new['unit'] ) ? (string) $new['unit'] : '';

		Ubac_Retention_Log::record( $option, $label, (string) $new['number'], $unit );

		$message = sprintf(
			/* translators: 1: the setting's name, 2: the delay that was refused, e.g. "36 months". */
			__( 'Ubac Retention Lock refused a retention delay: %1$s (%2$s). Nothing was saved, and the attempt was recorded.', 'ubac-retention' ),
			$label,
			trim( $new['number'] . ' ' . $unit )
		);

		// WooCommerce's settings screens do not use the WordPress settings
		// API, so `add_settings_error()` would be queued and never shown
		// there — which is precisely the screen somebody types a delay into.
		// Measured, not assumed.
		if ( class_exists( 'WC_Admin_Settings' ) && method_exists( 'WC_Admin_Settings', 'add_error' ) ) {
			WC_Admin_Settings::add_error( $message );
		} elseif ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'ubac_retention', 'ubac_retention_refused', $message, 'error' );
		}

		return $old;
	}

	/**
	 * Takes WooCommerce's daily cleanup off its hook, and unschedules it.
	 *
	 * Two schedulers, because WooCommerce has used both. Modern versions
	 * schedule this through Action Scheduler
	 * (`as_schedule_recurring_action()`), where `wp_clear_scheduled_hook()`
	 * finds nothing at all and reports success — measured on WooCommerce
	 * 11.1.2, and the reason this method does not stop at the WP-Cron call.
	 *
	 * The callback is removed by name rather than with
	 * `remove_all_actions()`. Everything on that hook is personal-data
	 * cleanup by definition, so the blunt version would probably be
	 * harmless; "probably harmless" is not a reason to take someone else's
	 * callback off a hook they own.
	 */
	public static function unhook_cleanup() {
		if ( ! self::is_on() ) {
			return;
		}

		if ( class_exists( 'WC_Privacy' ) ) {
			$instance = self::privacy_instance();

			if ( $instance ) {
				remove_action( self::CLEANUP_HOOK, array( $instance, 'queue_cleanup_personal_data' ) );
			}
		}

		/**
		 * Filters whether the lock also unschedules the cleanup job.
		 *
		 * Unhooking the callback is what stops the work. Unscheduling is
		 * tidiness, and it writes to the database, so it can be turned off.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $unschedule Default true.
		 */
		if ( ! apply_filters( 'ubac_retention_unschedule', true ) ) {
			return;
		}

		// Only where somebody is looking. The two scheduler lookups are two
		// uncached queries, and running them on every front-end page view of
		// a shop, for ever, to tidy a job that is already unhooked, is a
		// cost with no reader.
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( as_has_scheduled_action( self::CLEANUP_HOOK ) ) {
				as_unschedule_all_actions( self::CLEANUP_HOOK );
			}
		}

		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			// `wp_unschedule_hook()` and not `wp_clear_scheduled_hook()`:
			// the second matches only the no-argument event.
			wp_unschedule_hook( self::CLEANUP_HOOK );
		}
	}

	/**
	 * WooCommerce's privacy object, whichever way this version exposes it.
	 *
	 * The callback was added as `array( $this, … )` from inside WC_Privacy's
	 * constructor, so removing it needs that same instance — a class name
	 * will not match.
	 *
	 * @return object|null
	 */
	private static function privacy_instance() {
		global $wp_filter;

		if ( ! isset( $wp_filter[ self::CLEANUP_HOOK ] ) ) {
			return null;
		}

		foreach ( $wp_filter[ self::CLEANUP_HOOK ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;

				if ( is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof WC_Privacy ) {
					return $function[0];
				}
			}
		}

		return null;
	}

	/**
	 * Is WooCommerce's cleanup job still hooked up?
	 *
	 * For the settings screen: a lock whose effect cannot be checked does not
	 * earn anybody's confidence.
	 *
	 * @return bool
	 */
	public static function cleanup_hooked() {
		return false !== has_action( self::CLEANUP_HOOK );
	}

	/**
	 * Is WooCommerce's own callback — the one this class knows how to
	 * remove — still on the hook?
	 *
	 * Separate from `cleanup_hooked()` on purpose. The two used to be the
	 * same lookup, which meant the panel could never disagree with the
	 * removal: if a future WooCommerce hooked a closure or a renamed class,
	 * `remove_action()` would never fire, the callback would stay, and the
	 * panel would print a green "removed" about it. A check that cannot
	 * contradict the thing it checks carries no information.
	 *
	 * @return bool
	 */
	public static function woocommerce_callback_present() {
		return null !== self::privacy_instance();
	}

	/**
	 * Is the cleanup job still scheduled, on either scheduler?
	 *
	 * @return array{action_scheduler:bool|null,action_scheduler_running:bool,wp_cron:bool}
	 */
	public static function job_state() {
		$scheduled = null;
		$running   = false;

		// `as_next_scheduled_action()` and not `as_has_scheduled_action()`.
		// The second answers for RUNNING **or** PENDING, while
		// `as_unschedule_all_actions()` cancels PENDING only — so an action
		// left `in-progress` by a queue run that timed out, the ordinary
		// residue of a busy shop, would be seen for ever and never
		// cancelled. The first distinguishes the two: `true` means running,
		// a timestamp means pending.
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next      = as_next_scheduled_action( self::CLEANUP_HOOK );
			$running   = true === $next;
			$scheduled = is_int( $next ) && $next > 0;
		}

		return array(
			'action_scheduler'         => $scheduled,
			'action_scheduler_running' => $running,
			'wp_cron'                  => (bool) wp_next_scheduled( self::CLEANUP_HOOK ),
		);
	}

	/**
	 * Removes every stored delay. The escape route this lock owes the shop.
	 *
	 * Without it the lock is a trap. `update_option()` reads its "old value"
	 * through `get_option()`, i.e. through this class's own read filter, so
	 * the old value is always the empty shape; a write that clears a field
	 * therefore equals it, and `update_option()` discards the write as a
	 * no-op. The row survives. And because WooCommerce renders that field
	 * from `get_option()` too, it shows blank while 36 months sits in the
	 * table — so the shop cannot even see what it is failing to clear.
	 *
	 * Measured, not deduced. The practical effect was that the lock turned a
	 * pre-existing danger into one that could not be defused, and then told
	 * the shop to go and defuse it somewhere it could not be done.
	 *
	 * @return string[] The options that were cleared.
	 */
	public static function clear_stored() {
		$cleared = array();

		foreach ( self::stored() as $option => $row ) {
			if ( '' === $row['stored'] ) {
				continue;
			}

			delete_option( $option );
			$cleared[] = $option;
		}

		self::forget_rows();

		return $cleared;
	}

	/**
	 * The delays actually stored in the database.
	 *
	 * Read straight from the options table: `get_option()` would come back
	 * through this class's own read filter and show empty, which is the one
	 * thing this panel must not do.
	 *
	 * @return array<string,array{label:string,stored:string}>
	 */
	public static function stored() {
		global $wpdb;

		$out = array();

		foreach ( self::options() as $option => $label ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option )
			);

			$value  = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
			$stored = '';

			if ( is_array( $value ) && ! empty( $value['number'] ) ) {
				$stored = trim( $value['number'] . ' ' . ( isset( $value['unit'] ) ? $value['unit'] : '' ) );
			}

			$out[ $option ] = array(
				'label'  => $label,
				'stored' => $stored,
			);
		}

		return $out;
	}
}
