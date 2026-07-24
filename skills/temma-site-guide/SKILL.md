---
name: temma-site-guide
description: Step-by-step guide to build a website or an API from a freshly created Temma PHP framework project skeleton. Use when starting a new Temma project, when the user asks how to structure or bootstrap a Temma site, where files go, or what to do first after composer create-project.
license: MIT
---

# Building a site with Temma, step by step

This guide takes a project from the freshly created skeleton to a working site or API.
It orchestrates the other `temma-*` skills: each step points to the skill that details it;
invoke that skill when performing the step.

## 0. The starting point

Temma projects are created with Composer (this has usually already been done):

```
composer create-project digicreon/temma-project-web my_site    # website (Smarty templates)
composer create-project digicreon/temma-project-api my_api     # API (JSON by default, API plugin)
```

Project tree:

| Directory | Content |
|---|---|
| `controllers/` | controllers (and plugins) |
| `templates/` | Smarty templates, one subdirectory per controller |
| `lib/` | project PHP classes (DAOs, business objects, Smarty plugins in `lib/smarty-plugins/`) |
| `etc/` | configuration (`temma.php`, web server configs) |
| `www/` | web root (`index.php`, static assets) |
| `cli/` | command-line controllers (run with `bin/comma`) |
| `bin/` | `comma` command-line runner |
| `log/`, `tmp/`, `var/` | logs, temporary files, local data |
| `tests/` | tests (PHPUnit bootstrap in `tests/autoload.php`) |

The web server must point to `www/` (configuration examples in `etc/apache.conf` and
`etc/nginx.conf`). URLs map to controllers by convention: `/article/show/123` executes
`Article::show(123)` and renders `templates/article/show.tpl`.

## 1. Configure the application

Everything lives in `etc/temma.php`, a PHP file returning an array. Minimal example:

```php
<?php

return [
	'application' => [
		'dataSources' => [
			'db' => 'mysql://user:passwd@localhost/mybase',
		],
		'rootController' => 'Homepage',
	],
	'loglevels'  => 'ERROR',
	'errorPages' => 'error404.html',
];
```

Key entries: `dataSources` (named connections, see the `temma-datasource` skill),
`rootController` (home page controller), `defaultController`, `defaultView` (`'~Json'`
for an API), `routes` (URL aliases), `plugins` (`_pre`/`_post` lists), `autoimport`
(values exposed to all templates as `$conf.<key>`), `loglevels` (see the `temma-log`
skill), and `x-*` sections for extended configuration. Full reference:
https://www.temma.net/en/documentation/configuration

## 2. First controller and templates

Create the home page controller in `controllers/Homepage.php` with an `__invoke()`
root action, and its template in `templates/homepage/__invoke.tpl`. Data goes to
templates through `$this['variable'] = $value;`.

→ skill `temma-controller` (actions, URL parameters, redirections, attributes, routing)
→ skill `temma-view` (Smarty syntax, layouts, escaping; JSON/CSV/RSS/iCal outputs)

Build the layout early: a shared `header.tpl`/`footer.tpl` (or Smarty `{extends}`),
included by every page template.

## 3. Data model

Declare the `db` data source, then access tables through DAOs: `$_temmaAutoDao = true;`
in a controller gives `$this->_dao` bound to the matching table; custom DAO classes in
`lib/` hold business queries.

→ skill `temma-dao` (CRUD, criteria, custom DAOs, raw SQL)
→ skill `temma-datasource` (all connectors: SQL, Redis, Memcache, S3...)

## 4. Forms and input validation

Never trust input. Validate URL parameters, GET/POST fields and JSON payloads with
validation contracts, declaratively (attributes) or programmatically.

→ skill `temma-validation`

## 5. Users and authentication

For a user account system (registration, login, roles), use the built-in authentication
controller and the `#[TµAuth]` attribute to protect controllers/actions. Sessions are
available in controllers via `$this->_session` (array-like access; flash variables with
a `__` name prefix).

→ skill `temma-auth`

## 6. API endpoints (if any)

JSON endpoints are regular controllers with the JSON view; a versioned, key-authenticated
API uses the API plugin (default in the `temma-project-api` skeleton).

→ skill `temma-api`

## 7. Cross-cutting features, as needed

- transverse behaviors on requests (access control, redirections, common data): pre/post
  plugins → skill `temma-plugin`;
- command-line tools and cron jobs: `cli/` controllers run by `bin/comma` → skill `temma-cli`;
- background/deferred processing → skill `temma-asynk`;
- sending emails (SMTP relay, templated emails) → skill `temma-email`;
- LLM calls (OpenAI, Claude, Gemini, Mistral...) → skill `temma-ai`;
- server-sent events (live updates) → skill `temma-sse`;
- logging and log managers → skill `temma-log`;
- tests → skill `temma-tests`.

## 8. Before going to production

- run a security pass → skill `temma-security`;
- set `loglevels` to `WARN` or `ERROR`, configure `errorPages`;
- disable debug tooling (the `\Temma\Plugins\Debug` pre-plugin must not run in production);
- check that `log/`, `tmp/`, `var/` are writable by the web server and not web-accessible;
- keep secrets out of version control (use `etc/temma.<platform>.php` overloads or
  environment variables in the configuration).

## Conventions to respect in every step

- controllers in StudlyCase, actions in camelCase starting lowercase;
- one template per action: `templates/<controller>/<action>.tpl`;
- project classes (DAOs, services) in `lib/`, loaded by the autoloader;
- data sources and configuration always accessed through the framework (never hardcode
  credentials in code).

## Further reading

- https://www.temma.net/en/documentation/installation
- https://www.temma.net/en/documentation/configuration
- https://www.temma.net/en/documentation/sessions
