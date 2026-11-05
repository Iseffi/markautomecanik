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
- Secrets are never displayed back in the setup page (write-only fields) and never written to logs.
- The OAuth2 access token is kept in memory only, for the duration of a run.

### Outgoing calls to Webmecanik

- Only `https://` instance URLs without credentials, query string or fragment are accepted.
- cURL is restricted to the HTTPS protocol, verifies the certificate and the host name,
  does not follow redirects (prevents leaking credentials to another host), has connection and total
  timeouts and aborts responses larger than 20 MB.
- Only paths under `/api/` of the configured instance can be called.
- Logs contain the HTTP method, path, status code, size and duration only: never query strings,
  request bodies or responses (which contain personal data).

### Incoming webhook (`public/webhook.php`)

- Disabled by default; returns 404 when disabled and 503 when no secret is configured.
- POST only, body limited to 1 MB, JSON depth limited.
- Each request must carry the `Webhook-Signature` header computed by Webmecanik
  (`base64(HMAC-SHA256(body, secret))`), compared in constant time.
- The payload is only used to extract contact ids (at most 50 per call). Contact data are then read
  from the Webmecanik API with the configured credentials: a replayed or forged payload cannot inject
  data, and replaying a valid payload is harmless (idempotent import).

### User interface

- Every state-changing action checks the dedicated permission and the CSRF token explicitly
  (in addition to Dolibarr's own check, which can be disabled by configuration); forms that modify
  data require POST.
- Row-level visibility: without the *read all leads* permission a user only sees leads assigned to
  or created by him, in lists, cards, statistics, widget and REST API.
- Every query is restricted to the entities allowed for the current user (multi-company).
- External users (linked to a third party) have no access.
- All output is escaped (`dol_escape_htmltag`), all SQL values are cast or escaped, sort fields and
  orders are white-listed, user-provided URLs are restricted to http(s) and opened with
  `rel="noopener noreferrer"`.
- Linking a lead to a third party or project after creating it in another screen requires an explicit
  confirmation (the token carried by the return URL is not trusted).

### Personal data (GDPR)

- Data minimisation: reminder emails only contain the lead reference, the company and a link.
- Anonymisation erases name, email, phone, town, website, message, interests, notes and the
  Webmecanik id while keeping statistical data; it can be run manually or by the purge scheduled job.
- The synchronisation history contains counters and technical messages only.

### Known limitations

- Encryption at rest of the lead table itself relies on your database configuration.
- The webhook endpoint is public by nature; protect it further with your web server or WAF
  (IP allow-list of your Webmecanik instance, rate limiting) if needed.
