# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-11-19
### Added
- Credit notes for refunds (T110), with hourly approval checks (T111) and protection against crediting a line twice.
- Excise duty per product, as a percentage of value or a fixed amount per unit.
- Orders in other currencies, with URA's exchange rate saved (T121).
- Positive fees as service lines; negative fees spread across the items.
- Branch support (T138) for invoices, stock-ins and credit notes.
- Built-in QR code generator; QR images cached in the uploads folder.
- Foreign buyer type (buyerType 2).

## [0.1.0] - 2026-11-19
### Added
- First release: settings tab, Offline Enabler and direct API modes, connection test (T101/T103), dictionary (T115), product registration (T130), stock-in (T131), invoice upload (T109) with retries, order meta box and list column, customer email and My Account display, buyer TIN field at checkout.
