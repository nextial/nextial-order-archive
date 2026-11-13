# Nextial Order Archive for WooCommerce

**Keeps the WooCommerce Orders screen fast on stores with years of orders.** Old, finished orders are moved out of the live HPOS tables into archive tables, stay searchable, and come back with one click — same order ID, items, notes and refunds.

Free on WordPress.org: **https://wordpress.org/plugins/nextial-order-archive/**
90-second setup video: **https://youtu.be/QfCGkjeeZkA**

## What it does

- **Rules** by status and age ("completed orders older than 12 months"), with a **preview** before anything moves and an optional nightly run.
- **Background batches** (Action Scheduler): each batch is one database transaction with row-count checks, rolled back on any difference. Stop at any time.
- **Search the archive** by order number, e-mail or customer name; **restore one order, the ones you tick, or everything that matches** in background batches.
- **Customers keep their orders**: a read-only "Archived orders" tab in My Account (can be switched off).
- **Privacy requests include archived orders**: the WordPress tools under Tools → Export/Erase Personal Data find them too, with the same rules WooCommerce uses for live orders.
- Orders move with addresses, meta, items, notes and refunds; IDs stay reserved; WooCommerce Analytics is untouched; orders linked to subscriptions or with active downloads are never archived.

## Before you install

WooCommerce with **High-Performance Order Storage (HPOS)** on and compatibility mode off, and a database backup before the first run.

## Pro

[Order Archive Pro](https://nextial.it/order-archive/) adds cold storage outside the database (compressed files, still searchable), data retention (anonymise or delete after N years, legal hold, log), archived orders in the normal WooCommerce order search, summary e-mails, WP-CLI and CSV export.

## Other Nextial plugins

- [Nextial Order Events for WooCommerce](https://github.com/nextial/nextial-order-events-for-woocommerce) — new orders, stock and refunds to ChatGPT in real time ([WordPress.org](https://wordpress.org/plugins/nextial-order-events-for-woocommerce/))
- [Nextial Form Events](https://github.com/nextial/nextial-form-events) — form entries to ChatGPT in real time ([WordPress.org](https://wordpress.org/plugins/nextial-form-events/))

## Support

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/nextial-order-archive/) or open an issue here. The released version lives on WordPress.org; this repository mirrors it.

License: GPL-2.0-or-later. WooCommerce is a trademark of Automattic Inc. This is an independent plugin by [nextial](https://nextial.it).
