---
name: temma-security
description: Audit and harden the security of a Temma PHP framework project. Use when reviewing code for XSS, SQL injection, CSRF-adjacent risks, access control gaps, unsafe uploads or configuration leaks, when the user asks "is this secure?", or before deploying a Temma site to production.
license: MIT
---

# Security in a Temma project

This skill is a review checklist: Temma provides a safety net for each risk below, and
the audit consists of checking that none of them has been bypassed. When fixing a point,
use the dedicated skill mentioned for details.

## 1. XSS (output escaping) → `temma-view`

- Smarty auto-escaping is ON by default: verify it was not disabled
  (`x-smarty`/`autoEscape`, and the deprecated `x-smarty-view` section).
- Hunt for `|raw` in templates: each occurrence must be justified by trusted content.
  User-generated HTML must go through `\Temma\Utils\HTMLCleaner` (HTMLPurifier-based)
  before being stored or displayed raw.
- Inline JavaScript receiving variables: use `{$var|escape:'javascript'}`.
- The PHP view (`~Php`) escapes NOTHING: every displayed variable needs
  `htmlspecialchars()`.

## 2. Injections (SQL and others) → `temma-dao`

- DAO methods and criteria are parameterized: safe. Raw SQL in custom DAOs must quote
  every interpolated value with `$this->_db->quote()` / `quoteNull()` or use prepared
  statements; grep for string-built SQL.
- Shell commands (CLI controllers): `escapeshellarg()` everywhere.

## 3. Input validation → `temma-validation`

- Every action processing input (URL parameters, GET/POST, JSON payload, uploads) should
  carry a `Check\*` attribute or call a `validate*()` method. Actions with typed
  parameters but no contract accept any content of that type: check ranges and formats.
- Uploads: `validateFiles()` with `mime:` constraints; never trust the client-provided
  file name or MIME (re-derive names, e.g. `|filenamize`).

## 4. Access control → `temma-auth`, `temma-api`

- Every non-public controller/action must carry `#[TµAuth]` (with role/service where
  relevant). Audit trick: list controllers and check which ones lack it on purpose.
- Access control by hiding URLs is not access control (default routing exposes every
  public method: a public method IS a routable action; helpers must be `private`/`protected`).
- State-changing actions should be restricted to the right HTTP method
  (`#[TµPost]`, `#[TµDelete]`...); `#[TµReferer]` adds a same-origin check on top for
  sensitive form targets.
- APIs: HTTPS mandatory (HTTP Basic keys travel on every request); private keys stored
  hashed (`password_hash()`), never in clear.

## 5. Sessions → `temma-auth`

- Session cookies: check the `sessionSecure` configuration on HTTPS sites.
- Never store secrets client-side; the Temma session lives server-side, keyed by cookie.
- After privilege changes (login/logout), make sure stale session data is removed.

## 6. Secrets and configuration

- No credentials in committed code: `etc/temma.php` DSNs can use `env://VAR` data
  sources; platform-specific overloads keep production secrets out of the repository.
- `log/`, `tmp/`, `var/`, `etc/` must not be web-accessible (only `www/` is the
  document root; check the vhost).
- Error pages configured (`errorPages`), `loglevels` at `WARN`/`ERROR` in production
  (verbose logs can leak data).

## 7. Production hygiene

- The `\Temma\Plugins\Debug` pre-plugin (debug toolbar) must NOT be enabled in
  production configuration.
- Emails: `x-email` → `disabled`/`allowedDomains` on test platforms, so staging never
  emails real users (see `temma-email`).
- Dependencies: keep `digicreon/temma-lib` and Smarty updated (`composer update`,
  `composer audit`).
- Authentication endpoints: the built-in Auth controller has anti-robot protection;
  `robotCheckDisabled` must only ever be set in test configurations (see `temma-tests`).

## How to run an audit

1. `grep -rn "|raw" templates/` and review each hit.
2. `grep -rnE "query(One|All)|->exec\(" lib/ controllers/ cli/` and check quoting.
3. List actions (public methods of controllers) and map them to `#[TµAuth]` /
   `Check\*` / `#[TµPost]` coverage.
4. Review `etc/temma.php`: secrets, debug plugin, loglevels, escaping, session settings.
5. Check the web server configuration exposes only `www/`.

Report findings with file:line references and fix them through the dedicated skills.

## Further reading

- https://www.temma.net/en/documentation/helper-htmlcleaner
- https://www.temma.net/en/documentation/helper-attr_auth
- https://www.temma.net/en/documentation/validation
- https://www.temma.net/en/documentation/helper-plugin_debug
