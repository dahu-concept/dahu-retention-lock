=== Dahu Retention Lock ===
Contributors: dahuconcept
Tags: data retention, order history, personal data, privacy, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Stops WooCommerce anonymising and binning your order history on a timer. It never deletes anything itself — it stops deletions.

== Description ==

WooCommerce can anonymise orders and bin them by itself, on a delay set in
Settings → Accounts & Privacy → Personal data retention. Six fields, a daily
job, and no record of what it took.

That is a good feature for a shop that chose it. It is a dangerous one for a
shop that does not know it is there: a delay typed once goes on anonymising
years of history, quietly — and the after-sales trail, the accounting and the
customer relationship go with it. There is no undo, and nothing tells you it
happened.

This plugin locks those six fields shut.

= Three layers =

1. **Read** — the six retention options are read as empty. Whatever is stored,
   WooCommerce sees no delay and deletes nothing.
2. **Write** — an attempt to save a delay is refused, recorded, and said out
   loud on the screen where it was typed.
3. **Job** — WooCommerce's daily cleanup is unhooked and unscheduled.

Layer 1 is the one that protects. The other two are visibility and tidiness.

= The six settings =

Orders awaiting payment, failed orders and cancelled orders are binned;
refunded orders and completed orders are anonymised; inactive customer
accounts are deleted.

Six, not five. The refunded-orders field sits in the middle of a long screen
and is the easy one to miss — and a lock that misses it leaves refunded orders
being anonymised while reporting itself as on. The list is checked against
WooCommerce itself at run time, so a retention setting added by a future
version is reported by name rather than let through quietly.

= Off by default =

Putting a lock on a core feature is a deliberate act. Activating the plugin
changes nothing until you switch the lock on.

= What the settings screen shows =

The switch; what is really stored, read straight from the database past the
lock, beside what WooCommerce currently reads; whether the cleanup callback is
still attached and whether the job is still scheduled on either scheduler; and
the record of every refused delay — when, what, and who.

= What it does not cover =

Erasure requests. WordPress's own Tools → Erase Personal Data, and
WooCommerce's "Remove personal data" bulk action, are explicit, one-off and
human-initiated. Somebody chose those, and the law may require them. What this
plugin stops is the automatic, recurring, silent kind.

= External services =

None. This plugin makes no network request of any kind and sends nothing
anywhere.

== Installation ==

1. Upload the folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Go to WooCommerce → Retention lock and switch the lock on.

== Frequently Asked Questions ==

= Does it delete anything? =

No. It is the opposite: it stops WooCommerce deleting. It writes two options
of its own and never touches order data.

= What happens if I deactivate it? =

Any delay still stored in WooCommerce goes back into force on the next
request. The settings screen shows you what is stored and warns you about this
while the plugin is still there to warn you. Clear those delays in
WooCommerce's own screen first, then lift the lock.

= Can I still erase a particular customer's data? =

Yes. Erasure requests and the "Remove personal data" bulk action are untouched
— they are deliberate, one-off actions.

= WooCommerce says my settings were saved, but nothing changed. =

That is the lock refusing a retention delay. You will have seen an error
beside the form, and the attempt is listed on the plugin's own screen with the
date and who made it.

== Screenshots ==

1. The settings screen: what is stored, what WooCommerce reads, the state of
   the daily job, and the record of refused delays.

== Changelog ==

= 1.2.1 =
* With the lock off, the screen no longer states that the six retention settings are locked. It says the lock is off, both in the notice shown when WooCommerce's code cannot be read and on the cleanup hook line.
* The refusal log says which of the two reasons it is empty for.

= 1.2.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.1.0 =
* Admin notices land where they were meant to. The screen was missing the
  marker WordPress uses to place them, so a refusal notice was moved into the
  middle of the first card.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
