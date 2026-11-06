# Webmecanik marketing automation for Dolibarr (MarkAutoMecanik)

[![CI](https://github.com/iseffi/markautomecanik/actions/workflows/ci.yml/badge.svg)](https://github.com/iseffi/markautomecanik/actions/workflows/ci.yml)
![Dolibarr](https://img.shields.io/badge/Dolibarr-19%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.5-777bb4)
![License](https://img.shields.io/badge/license-GPLv3-green)

*[Version française](README-fr.md)*

**MarkAutoMecanik** connects [Dolibarr ERP & CRM](https://www.dolibarr.org) to
[Webmecanik Automation](https://www.webmecanik.com) (the Mautic-based marketing automation platform).
Contacts that become *sales ready* in Webmecanik (points threshold, optional segment) are imported
into Dolibarr as **marketing leads**, qualified by your team, assigned to sales representatives and
converted into third parties, contacts and opportunities. The lead status can be pushed back to
Webmecanik as a tag to close the loop between marketing and sales.

## Features

- **Import of qualified contacts** from Webmecanik Automation
  - hourly scheduled job, incremental (only contacts modified since the last successful run);
  - points threshold and optional segment filter;
  - mapping of core fields (name, email, phone, company, town, country, language, website),
    UTM tags and up to three custom fields (message, interests, industry);
  - de-duplication by email / Webmecanik id: an open lead is refreshed instead of duplicated,
    a closed lead is re-created only when the contact shows new activity, an invalid lead is never re-created;
  - optional **real-time webhook** with HMAC-SHA256 signature verification.
- **Qualification workflow**: to process → assigned → converted / lost / invalid / archived,
  with mandatory lost reason, private notes and sales representative assignment
  (email notification and agenda event).
- **Conversion**: link to an existing third party (the assigned sales representative is added to it),
  create the third party, the contact and an opportunity (project) prefilled from the lead.
- **Closed loop**: lead status pushed to Webmecanik as tags (`dolibarr-converted`, `dolibarr-lost`, …).
- **Reminders**: daily email to sales representatives for leads waiting beyond the processing delay,
  and summary of late unassigned leads to a shared address.
- **GDPR**: one-click anonymisation and scheduled purge of unconverted leads after a retention period.
- **Dashboard widget**, **statistics** (per month, status and sales representative, conversion rate)
  and **synchronisation history**.
- **REST API** (`/api/index.php/markautomecanik/leads`) to read and create leads.
- Multi-company aware, English and French translations.

## Requirements

| Component | Version |
|-----------|---------|
| Dolibarr  | 19.0 or later (tested with 19, 21, 22, 23 and 24) |
| PHP       | 7.4 or higher (tested up to 8.5), with the `curl`, `json` and `openssl` extensions |
| Database  | MySQL / MariaDB (PostgreSQL should work but is not tested) |
| Webmecanik Automation | API enabled (Settings > Configuration > API settings) |

Dolibarr modules: **Third parties** (required); **Agenda** and **Projects** (optional).

## Installation

### From the release archive

1. Download `module_markautomecanik-x.y.z.zip` from the [releases page](https://github.com/iseffi/markautomecanik/releases).
2. In Dolibarr: *Home > Setup > Modules > Deploy/install external app/module*, upload the archive.

### From git

```sh
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/iseffi/markautomecanik.git markautomecanik
```

The directory **must** be named `markautomecanik`.

Then enable **Webmecanik marketing automation** in *Home > Setup > Modules* (CRM section).

## Configuration

### 1. Create API credentials in Webmecanik

1. *Settings > Configuration > API settings*: enable the API (and HTTP basic auth if you choose this mode).
2. **OAuth2 (recommended)**: *Settings > API Credentials > New*, protocol OAuth 2, any redirect URI.
   Copy the *Client ID* and *Client secret*.
   **Basic authentication**: create a dedicated Webmecanik user with a role restricted to
   *Contacts: view* (and *edit* if you enable the closed loop).

Use a dedicated API account with the least privileges required.

### 2. Configure the module

*Home > Setup > Modules > Webmecanik marketing automation* (gear icon):

| Setting | Description |
|---------|-------------|
| Instance URL | e.g. `https://mycompany.webmecanik.com` – **https only** |
| Authentication | OAuth2 client credentials or HTTP basic |
| Minimum points | contacts below this score are ignored (default 40) |
| Segment alias | optional, restricts the import to one segment |
| Custom field aliases | message, interests, industry (optional) |
| Processing delay | days before a lead is flagged late and reminders are sent (0 = off) |
| Alert email | receives the list of late unassigned leads |
| Retention | months before unconverted leads are anonymised (0 = unlimited) |
| Push status as tag | closed loop with Webmecanik |
| Webhook | optional real-time import |

Click **Test connection**, then **Synchronise now** (use a window of e.g. 30 days for the initial import).

### 3. Enable the scheduled jobs

*Home > Setup > Scheduled jobs* (module *Scheduled jobs* must be enabled and its cron entry configured,
see the [Dolibarr documentation](https://wiki.dolibarr.org/index.php/Module_Cron_En)):

| Job | Frequency |
|-----|-----------|
| Webmecanik - synchronise marketing leads | hourly |
| Webmecanik - remind pending marketing leads | daily |
| Webmecanik - GDPR purge of marketing leads | daily |

All jobs are created **disabled**: enable them once the connection works.

### 4. Optional: real-time webhook

1. In the module setup, paste the secret and enable the webhook; copy the displayed URL.
2. In Webmecanik: *Settings > Webhooks > New*, paste the URL and the secret,
   select the events *Contact Updated Event* and *Contact Points Changed Event*.

The webhook only carries contact ids: contact data are always read back from the API.
The scheduled job remains the reference and catches up anything the webhook missed.

### 5. Permissions

| Permission | Typical profile |
|------------|-----------------|
| Read leads assigned to / created by the user | sales representatives |
| Read all leads | marketing, sales managers |
| Create and qualify leads | marketing, sales |
| Delete and anonymise leads | DPO, administrators |
| Run the synchronisation manually | marketing |

## REST API

Requires the Dolibarr *API REST* module and a user API key.

```sh
# List leads to process
curl -H "DOLAPIKEY: <key>" "https://erp.example.com/api/index.php/markautomecanik/leads?status=1"

# Read one lead
curl -H "DOLAPIKEY: <key>" "https://erp.example.com/api/index.php/markautomecanik/leads/12"

# Create a lead
curl -X POST -H "DOLAPIKEY: <key>" -H "Content-Type: application/json" \
     -d '{"lastname":"Doe","firstname":"John","email":"john.doe@example.com","company":"Example Ltd"}' \
     "https://erp.example.com/api/index.php/markautomecanik/leads"
```

Visibility rules are the same as in the user interface.

## Security

Security is a design goal of this module; see [SECURITY.md](SECURITY.md) for the details and
for how to report a vulnerability. Highlights:

- credentials and webhook secret encrypted in the database, never displayed back nor logged;
- HTTPS enforced, certificates verified, redirects refused, response size and time bounded;
- CSRF token and permission checked on every state-changing action, POST required for forms;
- row-level visibility (own leads vs all leads) and multi-company isolation on every query;
- all output escaped, all SQL values cast or escaped, sort fields white-listed;
- HMAC-signed webhook with constant-time comparison; no personal data in logs.

## Development

```sh
# Syntax check
find . -name "*.php" -print0 | xargs -0 -n1 php -l

# Unit tests (need a Dolibarr checkout, no database)
DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/unit_test.php

# Build the installable archive
./build/build.sh
```

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Data model

| Table | Content |
|-------|---------|
| `llx_markautomecanik_lead` | marketing leads |
| `llx_markautomecanik_synclog` | synchronisation history (no personal data) |

Tables are kept when the module is disabled.

## License

Copyright (C) 2026 Michel L'Hostis

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See [COPYING](COPYING).

*Webmecanik* and *Mautic* are trademarks of their respective owners. This project is not affiliated
with or endorsed by Webmecanik or Dolibarr.
