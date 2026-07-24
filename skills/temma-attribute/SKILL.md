---
name: temma-attribute
description: Create custom PHP attributes for controllers and actions in a Temma PHP framework project. Use when writing a reusable declarative behavior (access control, logging, rate limiting, feature flags) applied with #[MyAttribute] on Temma controllers or actions.
license: MIT
---

# Custom Temma attributes

In Temma, attributes are **active**: the framework executes them before the controller or
action they decorate, and they can alter its behavior (block access, redirect, change the
view...). New attributes plug in without touching the framework core. Built-in ones
(`Auth`, `Check\*`, `View`, `Template`, `Methods\*`, `Referer`, `Redirect`) are covered
by the other `temma-*` skills; this skill is about writing your own.

## Writing an attribute

Rules:
1. extend `\Temma\Web\Attribute`, declare `#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]`;
2. the constructor only **stores** its parameters (promoted properties), no logic;
3. the logic goes in `apply(\Reflector $context)`, called by the framework with a
   `ReflectionClass` (attribute on a controller) or `ReflectionMethod` (on an action).

```php
<?php

use \Temma\Base\Log as TµLog;

/** Logs the execution flow of controllers and actions. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class MyLog extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$message	(optional) Message appended to the log line.
	 */
	public function __construct(private ?string $message=null) {
	}
	/**
	 * Attribute execution.
	 * @param	\Reflector	$context	Execution context (class or method).
	 */
	public function apply(\Reflector $context) : void {
		$name = $context->getName();
		if ($context instanceof \ReflectionMethod)
			$name = $context->getDeclaringClass()->getName() . '::' . $name;
		$logString = "$name ({$context->getFileName()}:{$context->getStartLine()})";
		if ($this->message)
			$logString .= ' : ' . $this->message;
		TµLog::l($logString);
	}
}
```

Usage (attributes stack; the same attribute may appear several times with different
parameters):

```php
#[MyLog]
class Article extends \Temma\Web\Controller {
	#[MyLog('Article list')]
	public function list() { }
}
```

## What an attribute can do

Through inheritance, `apply()` has the same toolbox as a controller (see the
`temma-controller` skill):

- template variables: `$this['var'] = 'value';` / `$var = $this['var'];`
- data sources as properties: `$this->db`...
- internal objects: `$this->_loader`, `$this->_session`, `$this->_config`,
  `$this->_request`, `$this->_response`;
- response control: `$this->_httpCode()`, `$this->_httpError()`, `$this->_redirect()`,
  `$this->_redirect301()`, `$this->_view()`, `$this->_template()`, `$this->_templatePrefix()`.

## Altering the execution flow

Unlike actions (which return `EXEC_*` values), an attribute alters the flow by
**throwing** a flow exception:

- `\Temma\Exceptions\FlowHalt`: stop everything, go straight to the view/redirection
  (the usual way to deny access: set a redirection or an HTTP error, then throw);
- `\Temma\Exceptions\FlowRestart`: restart the current phase;
- `\Temma\Exceptions\FlowReboot`: restart the whole chain;
- `\Temma\Exceptions\FlowQuit`: stop the framework without executing the view.

Typical access-control pattern:

```php
public function apply(\Reflector $context) : void {
	if (!$this['currentUser']) {
		$this->_redirect('/auth/login');
		throw new \Temma\Exceptions\FlowHalt();
	}
}
```

## Notes

- Attributes run on web requests and on CLI commands (`temma-cli` skill) when their
  logic makes sense there; guard web-only logic accordingly.
- Attribute classes live in the project's `lib/` directory (or `controllers/`), loaded
  by the autoloader like any project class.

## Further reading

- https://www.temma.net/en/documentation/attributes
- https://www.temma.net/en/documentation/flow
