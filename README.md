# DahuConcept Retention Lock

Stops WooCommerce deleting your order history on a timer.

WooCommerce can anonymise orders and bin them by itself, on a delay you set in **Settings → Accounts & Privacy → Personal data retention**. Six fields, a daily job, and no record of what it took.

That is a good feature for a shop that chose it. It is a dangerous one for a shop that does not know it is there: a delay typed once goes on anonymising years of history, quietly — and the after-sales trail, the accounting and the customer relationship go with it. There is no undo, and nothing tells you it happened.

This plugin locks those six fields shut. **It never deletes anything itself. It stops deletions.**

MIT licensed.

---

## What it does

Three layers, and they are not equals.

**1. Read.** The six retention options are read as empty. Whatever is stored in the database, WooCommerce sees no delay and deletes nothing.

**2. Write.** An attempt to save a delay is refused, recorded, and said out loud on the screen where it was typed. A setting blocked in silence is a setting somebody finds years later without understanding it.

**3. Job.** WooCommerce's daily cleanup is unhooked and unscheduled.

**Layer 1 is the one that protects.** The other two are visibility and tidiness. That is measured rather than assumed: WooCommerce's job pushes six tasks onto a background queue, and each task re-reads its own option before doing anything — so tasks already queued when the lock goes on read empty and stop by themselves.

### The six

| Setting | What it deletes |
|---|---|
| Orders awaiting payment | Binned |
| Failed orders | Binned |
| Cancelled orders | Binned |
| **Refunded orders** | Anonymised |
| Completed orders | Anonymised |
| Inactive customer accounts | Deleted |

Six, not five. The refunded-orders field sits in the middle of a long screen and is the easy one to miss — and a lock that misses it leaves refunded orders being anonymised while reporting itself as on.

The list is checked against WooCommerce itself at run time. If a future version adds a retention setting this plugin does not know about, the settings screen says so by name rather than letting it through quietly.

---

## Off by default

Putting a lock on a core feature is a deliberate act. Activating this plugin changes nothing until you switch the lock on.

---

## What the settings screen shows

**WooCommerce → Retention lock.** Requires `manage_woocommerce`.

- the switch;
- **what is really stored**, read straight from the database past the lock, next to what WooCommerce currently reads. A lock whose effect cannot be checked earns nobody's confidence;
- whether the cleanup callback is still attached, and whether the job is still scheduled on either scheduler;
- the record of every refused delay: when, what, and who.

If delays are stored while the lock is on, the screen says how many, warns that lifting the lock — or deactivating the plugin — puts them back into force on the next request, and gives you a button to clear them.

**That button is not a convenience, it is the way out.** While the lock is on, WooCommerce's own field renders blank (it reads the same filtered value), and a save there is discarded as a no-op, because `update_option()` compares the submitted value against an "old value" it also read through the lock. So the one screen that looks like the place to remove a delay is the one place it cannot be done. The button removes the delays and touches nothing else.

---

## Two schedulers

WooCommerce schedules its cleanup through **Action Scheduler** on modern versions, and through WP-Cron on older ones. `wp_clear_scheduled_hook()` finds nothing at all on an Action Scheduler job — and reports success. This plugin checks and clears both, and shows you both.

Unhooking the callback is what stops the work; unscheduling is tidiness and it writes to the database, so it can be turned off:

```php
add_filter( 'ubac_retention_unschedule', '__return_false' );
```

The callback is removed by name, not with `remove_all_actions()`. Everything on that hook is personal-data cleanup by definition, so the blunt version would probably be harmless — "probably harmless" is not a reason to take someone else's callback off a hook they own.

---

## What it does not cover

**Erasure requests.** WordPress's own *Tools → Erase Personal Data*, and WooCommerce's "Remove personal data" bulk action, are explicit, one-off, human-initiated. They are not what this plugin is for. Somebody chose those, and the law may require them.

What this plugin stops is the *automatic, recurring, silent* kind.

---

## Installing

Upload the folder to `wp-content/plugins/`, activate it, then switch the lock on at **WooCommerce → Retention lock**.

Requires WordPress 6.0, PHP 7.4, WooCommerce 7.0.

---

## Uninstalling

Two options go: the switch and the record.

**WooCommerce's stored delays are not touched.** They are the shop's settings, not this plugin's, and this plugin never wrote them. Clearing them on the way out would be a deletion made by a plugin whose whole purpose is to stop deletions nobody asked for.

Which means uninstalling puts any stored delay straight back into force. The settings screen says so, with those delays on display, while the plugin is still there to say it.

---

## Licence

MIT. See `LICENSE`.

Hugo Vial-Jaime — Dahu-Concept — <contact@dahu-concept.fr>
