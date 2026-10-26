=== Nextial Order Archive ===
Contributors: nextial
Tags: archive orders, old orders, hpos, database cleanup, woocommerce orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Archive old orders automatically and keep the WooCommerce Orders screen fast (HPOS). Search archived orders and restore any order in one click.

== Description ==

Stores with years of orders get a slow Orders screen, slow searches and heavy database queries. Nextial Order Archive moves old, finished orders out of the live WooCommerce order tables into archive tables, so WooCommerce works on the orders that matter today.

* **Rules**: choose the statuses (completed, refunded, cancelled, failed…) and the age, for example "completed orders older than 12 months".
* **Preview first**: see how many orders match, and which ones stay live, before anything moves.
* **Background batches**: Action Scheduler moves small batches one after another, so the store never slows down. Stop at any time.
* **Automatic**: optionally run the rule every night.
* **Search the archive** by order number, e-mail or customer name.
* **Restore in one click**: the order comes back with the same ID, items, notes and refunds. A restored order is never archived again automatically.
* **Reports keep their history**: WooCommerce Analytics tables are not touched.

= Safe by design =

* Every batch runs inside one database transaction: rows are copied, every row count is checked, and only then are the live rows removed. Any difference rolls the batch back and nothing is moved.
* An order moves with everything that belongs to it: addresses, operational data, meta, line items and their meta, order notes and child refunds.
* Order IDs stay reserved (HPOS placeholder posts are kept), so a restored order gets its own ID back and no new order can take it.
* Orders linked to WooCommerce Subscriptions and orders with downloads the customer can still use are never archived. Processing and on-hold orders are never archived.
* Requires High-Performance Order Storage (HPOS) with compatibility mode off, so orders live in one place only. The plugin checks this and does nothing otherwise.
* Uninstalling the plugin never deletes the archive.

= What to know =

* Archived orders are not shown in WooCommerce → Orders or in the customer's My Account page. Restore an order to show it again.
* Other plugins that keep their own data about orders (for example invoices or shipping labels) keep it; they will not find the order while it is archived.
* Make a database backup before the first run.

== Installation ==

1. Install and activate the plugin, then open **WooCommerce → Order Archive**.
2. Choose statuses and age, then press **Save and preview**: nothing is moved.
3. Make a database backup, then press **Archive matching orders now**.
4. Optional: tick **Run this rule every night**.

== Frequently Asked Questions ==

= Does it work without HPOS? =

No. The plugin needs High-Performance Order Storage, the default order storage of WooCommerce for new stores. Turn it on in WooCommerce → Settings → Advanced → Features and turn compatibility mode off.

= Can I get an order back? =

Yes, from WooCommerce → Order Archive → Archived orders → Restore. It returns with the same ID and all its data.

= What happens to reports? =

Nothing: WooCommerce Analytics keeps its own tables, which this plugin never changes.

= What if I uninstall the plugin? =

Settings are removed, the archive tables are kept. Reinstall the plugin to search or restore archived orders.

== Changelog ==

= 0.1.0 =
* First release: rules by status and age, preview, background batches with transaction and row-count checks, nightly runs, archive search, one-click restore.
