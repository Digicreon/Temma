---
name: temma-plugin
description: Write and configure pre-plugins and post-plugins in a Temma PHP framework project. Use when adding cross-cutting behavior around controllers (authentication checks, URL rewriting, common template variables, request/response transformations) or when configuring the plugins chain in etc/temma.php.
license: MIT
---

# Temma plugins

Plugins are controllers with an extra capability: they run **before** (pre-plugins)
and/or **after** (post-plugins) the controller, for all requests or a subset of them.
Typical uses: access control, extracting a language prefix from URLs, injecting template
variables common to all pages, post-processing generated data.

Plugin source files live in the project's `controllers/` directory, like controllers.
The same class can be both a plugin and a controller.

## Writing a plugin

A plugin extends `\Temma\Web\Plugin` (which extends `\Temma\Web\Controller`, so
everything from the `temma-controller` skill applies: template variables, `$this->_loader`,
`$this->_session`, flow control...):

```php
class CheckPlugin extends \Temma\Web\Plugin {
	public function plugin() {
		$this['checked'] = true;
	}
}
```

Method resolution:
- `plugin()` is called whether the class is used as a pre- or post-plugin;
- if `preplugin()` exists, it is preferred when running in the pre-plugin chain;
- if `postplugin()` exists, it is preferred in the post-plugin chain.

Plugins communicate with each other and with the controller through template variables.
They control the execution flow with the same return values as actions (`self::EXEC_STOP`,
`EXEC_HALT`, `EXEC_RESTART`...; see the `temma-controller` skill). For example an
authentication pre-plugin returns `$this->_redirect('/login')` to short-circuit the
controller.

## Configuration

The `plugins` key of `etc/temma.php` declares the chains. Plugins run in declaration
order:

```php
'plugins' => [
	// global plugins, for all requests
	'_pre'  => ['AuthenticationPlugin', '\MyApp\SomePlugin'],
	'_post' => ['CleanSessionPlugin'],

	// plugins for one controller only
	'Homepage' => [
		'_pre'  => ['SomePlugin'],
		'_post' => ['LastPlugin'],
		// plugins for one action of this controller
		'show' => [
			'_pre' => ['SpecialPlugin'],
		],
		// for all actions of this controller except one (leading hyphen)
		'-remove' => [
			'_pre' => ['BarPlugin'],
		],
	],

	// for all controllers except one (leading hyphen)
	'-Auth' => [
		'_pre' => ['AuthPlugin'],
	],
],
```

Built-in plugins, ready to enable: `\Temma\Plugins\Api` (see the `temma-api` skill),
`\Temma\Plugins\Router` (advanced routing, see `temma-controller`),
`\Temma\Plugins\Cache` (full-page cache), `\Temma\Plugins\Debug` (debug toolbar, never in
production), `\Temma\Plugins\Language` (localization), `\Temma\Plugins\KebabCaseUrl`
(kebab-case URLs). The advanced router also supports per-route `_pre`/`_post` lists.

## Modifying the request (controller/action rewriting)

A plugin can rewrite what the framework will execute, through
`$this->_loader->request`: `getController()`, `getAction()`, `getParams()`,
`getParam($i)`, and their setters `setController()`, `setAction()`, `setParams()`,
`setParam($i, $v)`. When changing controller or action, also update the `CONTROLLER`,
`ACTION` and `URL` template variables. Classic example, a language-prefix extractor
(`/en/article/show/123` handled as `/article/show/123`):

```php
class LangExamplePlugin extends \Temma\Web\Plugin {
	public function preplugin() {
		$lang = $this['CONTROLLER'];                     // first URL chunk
		$newController = $this['ACTION'];
		$params = $this->_loader->request->getParams();
		$newAction = array_shift($params);
		// shift everything one chunk left
		$this->_loader->request->setController($newController);
		$this->_loader->request->setAction($newAction);
		$this->_loader->request->setParams($params);
		// keep template variables in sync
		$this['lang'] = $lang;
		$this['CONTROLLER'] = $newController;
		$this['ACTION'] = $newAction;
		$this['URL'] = mb_substr($this['URL'], mb_strlen($lang) + 1);
	}
}
```

A plugin can also read and rewrite the plugins chain itself through
`$this->_loader->config->plugins` (get, modify, set back); return `EXEC_RESTART` or
`EXEC_REBOOT` to make the new chain effective.

## Further reading

- https://www.temma.net/en/documentation/plugins
- https://www.temma.net/en/documentation/flow
