=== Nexa EFRIS for WooCommerce ===
Contributors: nexablaze
Tags: efris, ura, uganda, fiscal receipts, e-invoicing
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fiscalise WooCommerce orders with URA EFRIS (Uganda): e-invoices and e-receipts, credit notes, and the FDN and QR code on orders and emails.

== Description ==

Connects WooCommerce to the Uganda Revenue Authority's Electronic Fiscal Receipting and Invoicing Solution (EFRIS) through the system-to-system API.

* Registers products and services in EFRIS (T130), with optional stock-in (T131)
* Issues fiscal invoices or receipts for paid orders automatically (T109), with retries
* Applies for credit notes on refunds (T110) and tracks URA approval (T111)
* Supports VAT categories, excise duty, shipping and fees, other currencies, branches, and B2B, B2C and foreign buyers
* Built-in QR code generator (no external service)
* Works with the URA Offline Enabler or the direct API (AES + RSA)
* Works with HPOS and checkout blocks

**Beta.** Not affiliated with or certified by URA. Complete UAT with URA before using it in production.

== Installation ==

1. Upload the plugin zip from Plugins → Add New → Upload Plugin and activate it.
2. Go to WooCommerce → Settings → EFRIS and enter your TIN and device number.
3. Click "Test connection", then "Fetch EFRIS dictionary".
4. Set the default commodity and unit codes, then register your products.
5. Test on the EFRIS test environment, complete UAT with URA, then switch to Production.

== Frequently Asked Questions ==

= Do I need the Offline Enabler? =
It is recommended. It handles encryption and keeps working during internet outages. Direct mode also works if you provide your private key file.

= Can a refund be credited automatically? =
Yes, if the refund lists items, shipping or fees, or refunds the full order. Amount-only partial refunds must be credited on the EFRIS portal.

== Screenshots ==

1. EFRIS settings and tools
2. Order with FDN, QR code and credit note status
3. EFRIS status column in the orders list
4. EFRIS fields on a product
5. Fiscal details in the customer email

== Changelog ==

= 0.2.0 =
* Credit notes for refunds (T110/T111), excise duty, other currencies, fees, branches, built-in QR generator.

= 0.1.0 =
* First release: settings, product registration, invoice upload, order and email display.
