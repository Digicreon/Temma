---
name: temma-api
description: Build REST APIs and webservices with the Temma PHP framework. Use when creating JSON API endpoints, versioned APIs (/v1, /v2), API key authentication, or when configuring the Temma API plugin in a web or API-oriented Temma project.
license: MIT
---

# Temma APIs

A Temma API endpoint is a regular controller returning JSON. Two levels of tooling:

1. **Simple case**: use the JSON view on regular controllers, nothing else.
2. **Full API**: enable the `\Temma\Plugins\Api` pre-plugin, which adds versioned URLs
   (`/v1/...`), public/private key authentication, and sets the JSON view automatically.

## Simple JSON endpoints

```php
class Api extends \Temma\Web\Controller {
	#[TµView('~Json')]
	public function getUser(int $id) {
		$this['json'] = [
			'name' => 'Albert Einstein',
			'type' => 'genius',
		];
	}
}
```

The `json` template variable carries the data to serialize (`@output` takes priority when
defined; `$this['jsonDebug'] = true;` pretty-prints). For an API-only project, set the
JSON view once and for all in `etc/temma.php` (`'application' => ['defaultView' => '~Json']`),
which is what the `temma-project-api` skeleton does. Use `$this->_httpCode(201)` /
`$this->_httpError(404)` for status codes, and validation contracts on input (see the
`temma-validation` skill).

## The API plugin

Enable it as one of the very first pre-plugins in `etc/temma.php`:

```php
'plugins' => [
	'_pre' => ['\Temma\Plugins\Api'],
],
```

It provides:
- **Versioned routing**: URLs are `/v<version>/<controller>/<action>/<params>`, mapped to
  controllers in the matching namespace. `/v1/user/list` calls `\v1\User::list()`;
  `/v2/user/remove/123` calls `\v2\User::remove(123)`. Several versions coexist, each in
  its own namespace tree.
- **JSON view** set by default for all actions.
- **Authentication** with public/private key pairs, sent on each request as HTTP Basic
  credentials (public key = login, private key = password). Always serve the API over
  HTTPS. On success, the plugin defines the `currentUser` template variable (user data,
  including `roles` and `services`), which powers the `#[TµAuth]` attribute.

## Authentication database

The plugin expects two tables in the `db` data source: `User` (fields `id`,
`date_creation`, `date_last_login`, `date_last_access`, `email`, `name`, `roles` SET,
`services` SET) and `ApiKey` (fields `public_key`, `private_key` storing a
`password_hash()` digest, `name`, `user_id`). Typical creation SQL, with `roles` and
`services` to customize:

```sql
CREATE TABLE User (
	id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
	date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	date_last_login  DATETIME,
	date_last_access DATETIME,
	email            TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	name             TINYTEXT,
	roles            SET('writer', 'reviewer', 'validator'), -- customize
	services         SET('articles', 'news', 'images'),      -- customize
	PRIMARY KEY (id),
	UNIQUE INDEX email (email(255))
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE ApiKey (
	public_key  CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	private_key TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	name        TINYTEXT NOT NULL DEFAULT ('Default'),
	user_id     INT UNSIGNED NOT NULL,
	PRIMARY KEY (public_key),
	FOREIGN KEY (user_id) REFERENCES User (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

Table/field names can be remapped in the `x-security` → `auth` configuration (`userData`
and `apiKeyData` arrays: `base`, `table`, and field mappings; extra user fields may be
listed to be fetched into `currentUser`). Alternatively, point to custom DAO classes with
`'userDao' => '\MyApp\UserDao'` and `'apiKeyDao' => '\MyApp\ApiKeyDao'`.

## Key generation

Generate key pairs server-side, show them once to the user, store the public key in clear
and only a hash of the private key:

```php
$keys = \Temma\Plugins\Api::generateKeys();
$apiKeyDao->create([
	'public_key'  => $keys['public'],
	'private_key' => password_hash($keys['private'], PASSWORD_BCRYPT),
	'user_id'     => $userId,
	'name'        => $keyName,     // optional label, lets users manage several keys
]);
```

## Protecting endpoints

Use the `#[TµAuth]` attribute (`\Temma\Attributes\Auth`), on the controller or per action,
based on the `currentUser` variable set by the plugin:

```php
namespace v1;

use \Temma\Attributes\Auth as TµAuth;

#[TµAuth]                                     // whole controller requires authentication
class Article extends \Temma\Web\Controller {
	#[TµAuth('manager')]                  // role required
	public function remove(int $articleId) { }

	#[TµAuth(service: ['images', 'text'])] // access to one of these services required
	public function list() { }
}
```

`#[TµAuth(authenticated: false)]` marks an action for non-authenticated users only.
See the `temma-auth` skill for the full attribute capabilities.

## Further reading

- https://www.temma.net/en/documentation/helper-plugin_api
- https://www.temma.net/en/documentation/view-json
- https://www.temma.net/en/documentation/helper-attr_auth
