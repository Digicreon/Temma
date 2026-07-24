---
name: temma-auth
description: Set up user authentication, access control and sessions in a Temma PHP framework project. Use when adding login/logout, passwordless magic-link authentication, user roles and services, the Auth attribute to protect controllers or actions, or when working with sessions and flash variables.
license: MIT
---

# Authentication and sessions in Temma

Temma ships a complete **passwordless** authentication system: the user enters their
email address, receives a single-use magic link (valid one hour), and clicking it logs
them in. Two components:

- `\Temma\Controllers\Auth`: controller + pre-plugin managing the login form, email
  sending and the user session;
- `#[TµAuth]` (`\Temma\Attributes\Auth`): restricts access to controllers/actions.

## Database

Two tables in the `db` data source (`roles` and `services` SETs to customize):

```sql
CREATE TABLE User (
	id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
	date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	date_last_login  DATETIME,
	date_last_access DATETIME,
	email            TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	name             TINYTEXT,
	roles            SET('admin', 'writer', 'reviewer'), -- customize
	services         SET('articles', 'news', 'images'),  -- customize
	PRIMARY KEY (id),
	UNIQUE INDEX email (email(255))
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE AuthToken (
	token      CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	expiration DATETIME NOT NULL,
	user_id    INT UNSIGNED NOT NULL,
	PRIMARY KEY (token),
	INDEX expiration (expiration),
	FOREIGN KEY (user_id) REFERENCES User (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

**Roles** describe what users are (admin, writer...); **services** describe which modules
they can access. Table/field names are remappable under `x-security` → `auth` (`userData`
and `tokenData` arrays).

## Configuration

The Auth controller is declared twice in `etc/temma.php`: as a **pre-plugin** (checks the
session on every request) and as a **route** (exposes `/auth/login` and `/auth/logout`):

```php
'plugins' => [
	'_pre' => ['\Temma\Controllers\Auth'],
],
'routes' => [
	'auth' => '\Temma\Controllers\Auth',
],
```

The login form template is `templates/auth/login.tpl` (a minimal one is provided; create
your own). It POSTs an `email` field to `/auth/login`, and can use the `authError`
(unknown email) and `authSent` (email sent) template variables.

Options under `x-security` → `auth`: `emailSender`, `emailSubject`, `emailText` (with
`%s` replaced by the login URL), `registration` (`true` to auto-register unknown emails),
`redirection` (URL after login; default home page). Logout: link to `/auth/logout`.
Emails are sent through the `temma-email` machinery (configure a transport if the local
MTA can't send).

## Protecting controllers and actions

`#[TµAuth]` relies on the `currentUser` template variable set by the pre-plugin:

```php
use \Temma\Attributes\Auth as TµAuth;

#[TµAuth]                                  // the whole controller requires authentication
class Account extends \Temma\Web\Controller { ... }

class Blog extends \Temma\Web\Controller {
	public function list() { }         // public

	#[TµAuth('writer')]                // role required
	public function create() { }

	#[TµAuth(['admin', 'writer'])]     // any of these roles
	public function edit() { }

	#[TµAuth(service: 'images')]       // service access required
	public function uploadImage() { }

	#[TµAuth(authenticated: false)]    // only for NON-authenticated users
	public function register() { }
}
```

Unauthorized access yields an HTTP 401 by default. To redirect to the login form instead:
globally with `'x-security' => ['authRedirect' => '/auth/login']`, or per attribute with
`#[TµAuth(redirect: '/auth/login', storeUrl: true)]` (`storeUrl` saves the requested URL
to return to it after login).

## Using the authenticated user

The pre-plugin sets two template variables, readable everywhere:

```php
$userId = $this['currentUserId'];              // null when not authenticated
$user = $this['currentUser'];                  // ['id', 'email', 'name', 'roles', 'services', ...]
$isAdmin = $user['roles']['admin'] ?? false;
```

```smarty
{if $currentUserId}
    <p>Hello, {$currentUser.name}!</p>
    {if $currentUser.roles.admin}<a href="/admin">Administration</a>{/if}
    <a href="/auth/logout">Logout</a>
{else}
    <a href="/auth/login">Login</a>
{/if}
```

The same `User` table, `currentUser` variable and `#[TµAuth]` attribute are shared with
the API plugin's key-based authentication (see the `temma-api` skill), and the
`bin/comma "\Temma\Cli\User"` command manages users from the command line.

## Sessions

Sessions work out of the box (cookie-based, storage configurable). In controllers and
plugins, `$this->_session` (elsewhere: `$this->_loader->session`) with array-like access:

```php
$this->_session['basket'] = $items;            // write (null deletes)
$items = $this->_session['basket'] ?? [];      // read
unset($this->_session['basket']);              // delete
```

Also: `get($key, $default)`, `getAll()`, `getPrefix($prefix)`. **Flash variables** (name
prefixed with `__`) live one request: set `$this->_session['__status'] = 'saved';` before
a redirection, and on the next request it is extracted (removed from the session) and made
available as the `__status` template variable (`$this['__status']` in controllers,
`{$__status}` in templates; double-underscore variables are the only underscore-prefixed
ones passed to templates).

## Further reading

- https://www.temma.net/en/documentation/auth
- https://www.temma.net/en/documentation/helper-ctrl_auth
- https://www.temma.net/en/documentation/helper-attr_auth
- https://www.temma.net/en/documentation/sessions
