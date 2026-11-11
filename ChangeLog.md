# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-11

Security hardening following the code review of version 1.0.1 by Webmecanik (issues #2, #3 and #4).

### Upgrade notes
- Disable and re-enable the module to create the new tables `llx_markautomecanik_optout` and
  `llx_markautomecanik_lock`.
- Disabling the module now deletes the Webmecanik credentials and the webhook secret: enter them again
  in the setup page afterwards.
- A webhook secret shorter than 32 characters still works but is flagged in the setup page: generate a
  new one and copy it into Webmecanik.

### Security
- Lead card: the email and phone links are built by the module from escaped values instead of
  `dol_print_email()` / `dol_print_phone()`, which do not escape their argument (stored XSS through the
  REST API, attribute injection in the `tel:` link on Dolibarr 19). (#2)
- Input normalisation for every source (form, REST API, import, webhook): HTML tags and line feeds
  removed from single-line fields (no more line injection in reminder emails), emails restricted to safe
  characters (quoted local parts refused), phone numbers restricted to digits and `+ ( ) . / -`. (#2, #3)
- Linked third parties, contacts and projects follow the Dolibarr visibility rules (`societe/lire`,
  `societe/client/voir`, private contacts, project visibility) when linking, confirming, displaying
  (card, list, agenda events) and in the REST API; the assigned sales representative is added to the
  third party only by a user with `societe/creer`. (#4)
- GDPR: anonymisation (and deletion, as an option) keeps a salted hash of the email and of the
  Webmecanik id in an opt-out list: the contact is never imported again. Administrators can lift the
  exclusion after a new consent. New option to also erase the company. The scheduled purge now also
  handles assigned leads left without status change during the retention period. (#3)
- The webhook applies the segment filter of the setup. (#3)
- Leads imported from Webmecanik have no author anymore: running the synchronisation no longer gives
  visibility on all imported leads (existing leads are migrated when the module is re-enabled). (#3)
- Webhook: per-contact import lock (no duplicate lead on concurrent calls), short API timeouts, rate
  limiting of invalid signatures per IP address with one log line per window. (#3)
- Secrets: alert in the setup page when a secret is stored unencrypted or encryption is unavailable,
  webhook secret of at least 32 characters with a generator, secrets deleted when the module is
  disabled. (#3)
- HTTP client: Dolibarr outgoing proxy (`MAIN_PROXY_*`) honoured, instance restricted to public IP
  addresses with DNS pinning (option for an internal instance), generic network error message. (#3)

### Changed
- Documentation: OAuth2 least privilege, encryption prerequisites, debug logs, supported PHP versions,
  release archive contents, upgrade procedure. (#3)

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
