# Security policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | yes       |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Use GitHub's private vulnerability reporting
(*Security > Advisories > Report a vulnerability* on the repository), or contact the maintainer
by email. Please include the affected version, a description of the issue, steps to reproduce and
its potential impact. You will receive an acknowledgement within 5 working days; a fix and an
advisory are published once the issue is confirmed, and reporters are credited unless they prefer
otherwise.

## Security design

### Secrets

- The OAuth2 client secret, the basic authentication password and the webhook secret are stored
  encrypted in `llx_const` with Dolibarr's `dolEncrypt()` (AES-256-CTR, key derived from the instance
  unique id). Restoring a database on another instance requires re-entering them.
- **Prerequisites**: the PHP `openssl` extension and a non-empty `$dolibarr_main_instance_unique_id`
  in `conf.php`. Without them `dolEncrypt()` stores the value in clear: the setup page then displays an
  alert, as it does for any secret found unencrypted in the database. Keep `conf.php` read-only.
- Secrets are never displayed back in the setup page (write-only fields) and never written to logs.
- The webhook secret must contain at least 32 characters; the setup page can generate a random one
  (64 hexadecimal characters), displayed once.
- The three secrets are deleted from the database when the module is disabled.
- The OAuth2 access token is kept in memory only, for the duration of a run.

### Outgoing calls to Webmecanik

- Only `https://` instance URLs without credentials, query string or fragment are accepted.
- cURL is restricted to the HTTPS protocol, verifies the certificate and the host name,
  does not follow redirects (prevents leaking credentials to another host), has connection and total
  timeouts and aborts responses larger than 20 MB.
- The instance host name must resolve to public IP addresses only (loopback, private, link-local,
  carrier-grade NAT and reserved ranges are refused), and the address checked is pinned for the call
  (no DNS rebinding). An administrator can allow a private address for an instance hosted internally.
- The outgoing proxy configured in Dolibarr (`MAIN_PROXY_*`) is used when enabled.
- Network errors are displayed as a generic message; the cURL details go to the logs only.
- Only paths under `/api/` of the configured instance can be called.
- Logs contain the HTTP method, path, status code, size and duration only: never query strings,
  request bodies or responses (which contain personal data).

### Incoming webhook (`public/webhook.php`)

- Disabled by default; returns 404 when disabled and 503 when no secret is configured.
- POST only, body limited to 1 MB, JSON depth limited.
- Each request must carry the `Webhook-Signature` header computed by Webmecanik
  (`base64(HMAC-SHA256(body, secret))`), compared in constant time.
- Invalid signatures are rate limited per IP address (HTTP 429 after 20 failures in 10 minutes) and
  logged once per window, not once per request.
- The payload is only used to extract contact ids (at most 50 per call). Contact data are then read
  from the Webmecanik API with the configured credentials: a replayed or forged payload cannot inject
  data, and replaying a valid payload is harmless (idempotent import).
- The segment filter of the setup applies to the webhook as to the scheduled job.
- A short-lived lock per contact prevents two concurrent imports (webhook and scheduled job) from
  creating two leads; API calls made by the webhook use short timeouts.

### Input normalisation

All data entering a lead (form, REST API, Webmecanik import, webhook) goes through the same rules:

- control characters removed, length bounded;
- single-line fields lose their line feeds and HTML tags (only message, interests and private note
  may contain line feeds), so that no line can be injected in the plain text reminder emails;
- emails must be valid **and** made of safe characters only (quoted local parts such as
  `"<svg/onload=...>"@example.com`, valid per RFC, are refused);
- phone numbers are reduced to digits, spaces and `+ ( ) . / -`.

### User interface

- Every state-changing action checks the dedicated permission and the CSRF token explicitly
  (in addition to Dolibarr's own check, which can be disabled by configuration); forms that modify
  data require POST.
- Row-level visibility: without the *read all leads* permission a user only sees leads assigned to
  or created by him, in lists, cards, statistics, widget and REST API. Leads imported from Webmecanik
  have no author, so running the synchronisation does not grant visibility on the imported leads.
- Linked records follow the Dolibarr rules: a third party, contact or project is displayed, linked
  or used (agenda events, prefilled creation forms, REST API ids) only if the user is allowed to see
  it (`societe/lire` and, without `societe/client/voir`, only the third parties the user is sales
  representative of; private contacts; project visibility). The assigned sales representative is added
  to the third party only by a user allowed to modify it (`societe/creer`).
- Every query is restricted to the entities allowed for the current user (multi-company).
- External users (linked to a third party) have no access.
- All output is escaped with `dol_escape_htmltag()`. The email and phone links of the lead card are
  built by the module from escaped values (`dol_print_email()` and `dol_print_phone()` do not escape
  their argument). All SQL values are cast or escaped, sort fields and orders are white-listed,
  user-provided URLs are restricted to http(s) and opened with `rel="noopener noreferrer"`.
- Linking a lead to a third party or project after creating it in another screen requires an explicit
  confirmation (the token carried by the return URL is not trusted).

### Personal data (GDPR)

- Data minimisation: reminder emails only contain the lead reference, the company and a link.
- Anonymisation erases name, email, phone, town, website, message, interests, notes and the
  Webmecanik id while keeping statistical data (industry, country, language, UTM tags, dates, status,
  and the company unless the *also erase the company* option is set).
- Anonymisation is an erasure request: a salted hash (HMAC-SHA256, secret salt per entity) of the
  email and of the Webmecanik id is kept in an opt-out list and the contact is never imported again,
  whatever its activity in Webmecanik. Deleting a lead can do the same (option of the confirmation).
  An administrator can remove an email from the list when the person gives a new consent.
  The contact must also be erased in Webmecanik, which the module does not do.
- The scheduled purge applies the retention period to unconverted leads that are not linked to a third
  party, including assigned leads without status change during that period. It is not an erasure
  request: purged contacts can be imported again if they show new activity. Converted leads and leads
  linked to a third party are kept: the relationship is then managed by the Dolibarr records.
- The synchronisation history contains counters and technical messages only.
- Logs contain no personal data, **except at the `LOG_DEBUG` level**, where Dolibarr itself logs every
  SQL query. Do not leave the debug level enabled in production.

### Known limitations

- Encryption at rest of the lead table itself relies on your database configuration.
- The webhook endpoint is public by nature; protect it further with your web server or WAF
  (IP allow-list of your Webmecanik instance, global rate limiting) if needed.
- The module id (500030) is in the range of non-registered modules until it is registered with
  Dolibarr: permission ids could collide with another unregistered module.
