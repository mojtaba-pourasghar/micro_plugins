=== Micro Sync Pro ===
Contributors: microstudio
Tags: woocommerce, price, stock, inventory, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional WooCommerce product price and inventory synchronization from CSV, XLSX, and remote data sources.

== Description ==

Micro Sync Pro keeps WooCommerce prices and stock in line with your supplier's price list — ideal for stores affected by currency and gold price fluctuations.

* Sources: CSV upload, Excel (XLSX) upload, remote CSV URL, remote JSON URL / supplier API (Bearer, Basic or API-key header).
* Exact SKU matching for simple products and variations. Duplicate SKUs in the file, ambiguous matches and unmatched rows are reported; products are never created.
* Field mapping with per-field opt-in: regular price, sale price, stock quantity, stock status.
* Pricing rules: price multiplier (e.g. Rial → Toman or exchange rate), profit margin, rounding (1 … 10,000; nearest/up/down), minimum/maximum price, per-category overrides.
* Preview (dry run) → confirm and apply exactly what was previewed.
* Background batch processing (Action Scheduler, WP-Cron fallback) with live progress, cancellation, duplicate-run protection and automatic resume of stalled runs.
* Full history with execution ID, timings, counters and per-record results; CSV export; one-click rollback with conflict detection.
* Scheduled synchronization (hourly, twice daily, daily, weekly).
* Logs with retention; credentials and full URLs are never logged.
* Persian digits/separators and Windows-1256 CSV files supported. Full Persian (fa_IR) translation and RTL admin.

== Installation ==

1. Upload the `micro-sync-pro` folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload.
2. Activate WooCommerce, then activate Micro Sync Pro.
3. Go to Micro Sync → Data Sources → Add data source, upload a file or enter a URL.
4. Review the suggested field mapping and save it.
5. Micro Sync → Synchronization → Preview (dry run). Review the results and click "Confirm and apply changes".
6. Optionally configure pricing rules (Settings) and a schedule (Schedule).

For reliable schedules on low-traffic sites, disable WP-Cron's page-load trigger and call `wp-cron.php` from a server cron job every 5 minutes.

== Frequently Asked Questions ==

= Does it create products? =
No. Version 1 only updates existing products and variations matched by exact SKU.

= Can I sync internal (private network) supplier servers? =
Private, loopback and reserved addresses are blocked to prevent SSRF. A developer can allow a specific trusted host with the `micro_sync_pro_trusted_hosts` filter.

= Is it compatible with HPOS? =
Yes. The plugin does not read or write orders and declares compatibility with High-Performance Order Storage.

== Hooks ==

* `micro_sync_pro_capability` (filter) — capability required (default `manage_woocommerce`).
* `micro_sync_pro_trusted_hosts` (filter) — host names allowed despite private IPs.
* `micro_sync_pro_allowed_ports` (filter) — remote ports (default 80, 443, 8080, 8443).
* `micro_sync_pro_storage_dir` (filter) — move file storage outside the web root.
* `micro_sync_pro_supported_product_types` (filter) — product types that may be synced.
* `micro_sync_pro_use_action_scheduler` (filter) — force WP-Cron.
* `micro_sync_pro_run_finished` (action) — fires with the finished run (e.g. for notifications).

== Changelog ==

= 1.0.0 =
* Initial release.
