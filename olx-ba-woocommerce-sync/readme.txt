=== OLX.ba WooCommerce Sync ===
Contributors: codex
Requires at least: 6.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.5.0
License: GPLv2 or later

Publishes WooCommerce products to OLX.ba listings.

== Description ==

This plugin connects WooCommerce to the OLX.ba API. It can create or update listings, upload product image URLs on first sync, optionally publish listings after they are created, send products one by one or in bulk from the WooCommerce products table, render required category attributes on the product edit screen, apply category-level default attribute profiles, use multiple OLX.ba profiles, enforce profile listing limits, map WooCommerce categories to OLX.ba categories, and process a sync queue.

The first version intentionally keeps category, city, country, and attribute mapping explicit. Use the OLX.ba API documentation to find the IDs required by your account and category.

== Setup ==

1. Upload the `olx-ba-woocommerce-sync` folder to `wp-content/plugins`.
2. Activate WooCommerce and this plugin.
3. Open WooCommerce > OLX.ba Sync.
4. Enter OLX.ba username, password, device name, default country, city, and category IDs.
5. Click Connect OLX.ba.
6. Open a product, enable Sync to OLX.ba, override IDs if needed, and click Sync now.
7. From Products, use the OLX.ba column or row action to send a product one by one, or use the bulk action for selected products.

== Notes ==

OLX.ba creates new listings as drafts. Enable "Publish listings after sync" if listings should be activated automatically.

The plugin uses WordPress' active locale. Turkish translations are bundled in `languages/olx-ba-woocommerce-sync-tr_TR.po` and `.mo`; other languages can be added from `languages/olx-ba-woocommerce-sync.pot`.
