# Contributing

Thanks for helping make EFRIS integration easier for Ugandan merchants!

## Reporting bugs
Open an issue using the **Bug report** template. Include:
- WordPress, WooCommerce and PHP versions
- The connection mode (Offline Enabler or direct)
- The relevant lines from **WooCommerce → Status → Logs → nexa-efris**

**Never post your TIN, device number, private keys, passwords or customer data.** Remove them from logs before sharing.

## EFRIS spec corrections
If you've done UAT with URA and found a field or code that differs from what the plugin sends, please open an issue with the interface code (e.g. T109), the field, and the correct value from the URA API document.

## Pull requests
1. Fork the repo and create a branch from `main`.
2. Follow the WordPress coding standards, and prefix functions, classes and hooks with `nexa_efris` / `Nexa_EFRIS_`.
3. Make sure `php -l` passes on all files (CI checks this).
4. Describe what you changed and how you tested it, ideally against the EFRIS test environment.
