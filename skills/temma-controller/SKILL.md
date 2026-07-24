---
name: temma-controller
description: Create and modify controllers in a Temma PHP framework project. Use when adding pages, actions, routes or URL handlers, when working with template variables, redirections, HTTP errors, request parameters, sessions or execution flow (EXEC_FORWARD, EXEC_HALT) in a Temma application.
license: MIT
---

# Temma controllers

Temma maps URLs to controllers and actions: `/article/show/123` executes `Article::show(123)`
and renders the template `templates/article/show.tpl`. This skill explains how to write
controllers the Temma way.

## Creating a controller

A controller must:
1. extend `\Temma\Web\Controller`;
2. be named in StudlyCase (`ArticleViewer`), in a file with the exact same name (`ArticleViewer.php`);
3. live in the `controllers/` directory of the project (or, when namespaced, in a matching
   tree under `controllers/` or `lib/`);
4. define its actions as public methods whose names start with a lowercase letter.

```php
<?php

/** Users controller. */
class User extends \Temma\Web\Controller {
	/** Root action, called for the "/user" URL. */
	public function __invoke() {
		$this['title'] = 'User list';
	}
	/**
	 * Called for URLs like "/user/show/123".
	 * @param	int	$id	User identifier.
	 */
	public function show(int $id) {
		$this['user'] = $this->_loader->UserDao->get($id);
	}
}
```

URL parameters are passed as method parameters, in order. Declare them with types and
default values as needed; validate them with a validation contract for anything non-trivial
(see the `temma-validation` skill).

## Special methods

| Method | Role |
|---|---|
| `__invoke()` | root action, used when the URL names the controller without an action (`/user`) |
| `__call(string $name, array $params)` | default action, used when the requested action doesn't exist |
| `__proxy(string $name, array $params)` | proxy action; when defined, ALWAYS called instead of any action |
| `__wakeup()` | initialization, called before the action (open resources, set common template variables) |
| `__sleep()` | finalization, called after the action |

Template resolution for these: `__invoke()` uses `templates/<controller>/__invoke.tpl`;
`__call()` and `__proxy()` use the action name from the URL (e.g. `/user/list` handled by
`__call()` renders `templates/user/list.tpl`).

The configuration file (`etc/temma.php`) can also define a `rootController` (used for the
site home page), a `defaultController` (used when the requested controller doesn't exist)
and a `proxyController` (always used).

## Template variables

Template variables carry data from the controller (and plugins) to the template, using
array-like access on `$this`:

```php
$this['articles'] = $articleList;             // create/update
$title = $this['title'] ?? 'Default title';   // read with default
unset($this['articles']);                     // remove
```

Variables whose name starts with `_` are private (available to plugins/controller, never
sent to templates). Temma pre-defines `$URL` (requested URL), `$CONTROLLER` and `$ACTION`,
and imports everything under the `autoimport` key of the configuration.

## Controller methods

- `$this->_template('ctrl/action.tpl')`: override the template (default: `<controller>/<action>.tpl`).
- `$this->_templatePrefix('subdir')`: prefix the template path.
- `$this->_view('\Temma\Views\Json')`: override the view (default: Smarty; see the `temma-view` skill).
- `$this->_redirect($url)` / `$this->_redirect301($url)`: HTTP redirection (302 / 301). With
  `referer: true` as second parameter, redirects to the HTTP referer, using `$url` as fallback.
- `$this->_httpError(404)`: interrupt with an HTTP error code.
- `$this->_httpCode(201)`: set the HTTP response code without interrupting.
- `$this->_header('Content-Type: text/plain')`: add an HTTP response header.
- `$this->_subProcess('OtherController', 'action')`: delegate the request to another controller.

`_redirect()`, `_redirect301()`, `_httpError()` and `_httpCode()` return an execution-flow
value; in an action, return their result: `return $this->_redirect('/login');`

## Objects available in a controller

- `$this->_loader`: the dependency injection component; any object may be fetched through it
  (`$this->_loader->UserDao`, `$this->_loader['\Temma\Utils\Email']`...).
- `$this->_session`: the session (`$this->_session['userId'] = 42;` array-like access).
- `$this->_config`: read-only access to the configuration (`$this->_config->xtra('section', 'key')`).
- `$this->_request`: the request (`getMethod()`, `getParam(0)`, `getPathInfo()`, `isAjax()`...).
- `$this->_response`: the response object.
- **Data sources** are directly available as properties, named as declared in the
  configuration: `$this->db`, `$this->cache`... (see the `temma-datasource` skill).

## Execution flow

The chain is: pre-plugins → controller (`__wakeup()`, action, `__sleep()`) → post-plugins →
view. Any of these methods can alter the flow by returning:

- `self::EXEC_FORWARD` (or nothing/null): continue normally;
- `self::EXEC_STOP`: stop the current phase, go to the next one;
- `self::EXEC_HALT`: stop everything, go straight to the view (used by redirections);
- `self::EXEC_RESTART`: restart the current phase;
- `self::EXEC_REBOOT`: restart the whole chain from the first pre-plugin;
- `self::EXEC_QUIT`: stop the framework without executing the view.

The same effects can be obtained by throwing `\Temma\Exceptions\FlowHalt()`,
`FlowStop()`, `FlowRestart()`, `FlowReboot()` or `FlowQuit()` from anywhere in the call
stack, which is handy deep inside business code.

## Attributes

PHP attributes provide declarative behavior, on a controller class (applies to all actions)
or on a single action:

```php
use \Temma\Attributes\Template as TµTemplate;
use \Temma\Attributes\View as TµView;
use \Temma\Attributes\Auth as TµAuth;
use \Temma\Attributes\Methods\Post as TµPost;

class Admin extends \Temma\Web\Controller {
	#[TµAuth('admin')]
	#[TµTemplate('admin/dashboard.tpl')]
	public function dashboard() { }

	#[TµPost]
	#[TµView('~Json')]
	public function saveSettings() { }
}
```

- `#[TµTemplate('path.tpl')]`, `#[TµTemplate(prefix: 'special')]`: template selection.
- `#[TµView('~Json')]`: view selection (`~` is a shortcut for `\Temma\Views\`).
- `#[TµAuth(...)]`: access control (see the `temma-auth` skill).
- `#[TµGet]`, `#[TµPost]`, `#[TµPut]`, `#[TµPatch]`, `#[TµDelete]`, `#[TµHead]`
  (namespace `\Temma\Attributes\Methods\`) or `#[TµMethod('POST')]`: restrict the HTTP method
  (throws an error 405 otherwise).
- `#[TµRedirect('/url')]`, `#[TµReferer]`: redirections and referer checks.
- Input validation attributes are covered by the `temma-validation` skill.

## Routing

Basic routing is convention-based (URL → controller/action). The `routes` configuration key
defines virtual controllers (aliases): `'routes' => ['sitemap.xml' => '\App\Ctrl\Sitemap']`.

For parameterized routes, enable the advanced router as a pre-plugin and describe routes in
the `x-router` extended configuration:

```php
'plugins'  => ['_pre' => ['\Temma\Plugins\Router']],
'x-router' => [
	'GET:/articles/[sort:enum:alpha,date]/[page:int]' => '\MyApp\Ctrl\Cms::list($sort, $page)',
	'*:/article/[id:int]/[title:string]'              => '\MyApp\Ctrl\Cms::show($id, $title)',
],
```

Routes can also declare per-route `_pre`/`_post` plugins alongside an `action` key.

## Further reading

- https://www.temma.net/en/documentation/controllers
- https://www.temma.net/en/documentation/flow
- https://www.temma.net/en/documentation/routing
- https://www.temma.net/en/documentation/internal_objects
- https://www.temma.net/en/documentation/loader
