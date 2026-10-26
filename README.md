# Nextial Order Archive for WooCommerce

Keeps the WooCommerce Orders screen fast: moves old, finished orders out of the live HPOS tables into archive tables, keeps them searchable and restores any order in one click.

- Rules by status and age, preview before anything moves, optional nightly run
- Background batches (Action Scheduler); each batch is one database transaction with row-count checks, rolled back on any difference
- Orders move with addresses, meta, items, notes and refunds; IDs stay reserved; Analytics untouched
- Orders linked to subscriptions or with active downloads are never archived
- Requires HPOS with compatibility mode off

Page: https://nextial.it/order-archive/

License: GPL-2.0-or-later
