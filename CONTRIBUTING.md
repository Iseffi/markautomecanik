# Contributing

Thank you for your interest in improving MarkAutoMecanik!

## Reporting bugs and requesting features

- Search existing issues first.
- Use the issue templates and give your Dolibarr, PHP and module versions.
- **Never paste credentials, API responses or personal data** in issues or logs.
- Security problems: see [SECURITY.md](SECURITY.md), do not open a public issue.

## Development setup

1. Clone the repository into `htdocs/custom/markautomecanik` of a Dolibarr 19+ development instance.
2. Enable the module, configure a Webmecanik (or Mautic) test instance.
3. Run the checks before submitting:

```sh
find . -name "*.php" -print0 | xargs -0 -n1 php -l
DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/unit_test.php
```

## Coding rules

- Follow the [Dolibarr coding rules](https://wiki.dolibarr.org/index.php/Language_and_development_rules):
  tabs for indentation, `snake_case` SQL, PHPDoc on every function.
- Code must run on PHP 7.4 to 8.3 (no PHP 8-only syntax).
- Security rules, mandatory for every change:
  - check the permission **and** call `markautomecanikCheckToken()` for any state-changing action;
    forms that modify data use POST and `markautomecanikRequirePost()`;
  - restrict every query on leads with `entity IN (getEntity(...))` and
    `MarkAutoMecanikLead::getSqlUserRestriction()`;
  - cast integers, escape strings with `$db->escape()`, white-list sort fields;
  - escape every output with `dol_escape_htmltag()`;
  - never log personal data, secrets, request bodies or API responses.
- Add translation keys to **both** `langs/en_US` and `langs/fr_FR`.
- Update `ChangeLog.md`.

## Pull requests

- One topic per pull request, with a clear description and test steps.
- CI (lint on several PHP versions + unit tests) must pass.
- By contributing you agree that your contribution is licensed under the GPL v3 or later.
