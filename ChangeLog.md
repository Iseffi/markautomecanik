# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-11-06

### Changed
- Dolibarr 21, 22, 23 and 24 support: the module APIs used (CommonObject, DolibarrModules, boxes, triggers,
  REST API, `dol_eval` expressions of menus / cron jobs) were checked against these versions; the unit test
  bootstrap now loads `blockedlog/lib/securitycore.lib.php`, where Dolibarr 24 moved `dolEncrypt()` /
  `dolDecrypt()`; CI unit tests now run against Dolibarr 19, 21, 22, 23 and 24.
- PHP 8.4 and 8.5 support: `curl_close()` is no longer called on PHP 8.0+ (no-op since 8.0,
  deprecated since 8.5); CI lint and unit tests now run on PHP 8.4 and 8.5.

## [1.0.0] - 2026-11-05

First public release, derived from the internal *leadform* module and dedicated to
Webmecanik Automation (the Plezi connector and all customer-specific code were removed).

### Added
- Incremental import of qualified Webmecanik contacts (points threshold, optional segment,
  custom field mapping, UTM tags) with de-duplication and re-engagement rules.
- OAuth2 client credentials and HTTP basic authentication.
- Optional real-time webhook with HMAC-SHA256 signature verification.
- Qualification workflow, sales assignment with email notification and agenda event,
  conversion to third party / contact / opportunity.
- Closed loop: lead status pushed to Webmecanik as tags.
- Reminders, GDPR anonymisation and scheduled purge.
- Dashboard widget, statistics, synchronisation history, REST API.
- Row-level visibility permissions, multi-company support, English and French translations.

### Security
- Encrypted secrets, hardened HTTPS client, systematic CSRF / permission checks,
  no personal data in logs. See SECURITY.md.
