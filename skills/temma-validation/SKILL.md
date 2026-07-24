---
name: temma-validation
description: Validate input and output data in a Temma PHP framework project with validation contracts. Use when checking URL parameters, GET/POST form fields, JSON payloads, uploaded files or output data, with Check attributes, Request validate methods or the DataFilter utility.
license: MIT
---

# Data validation in Temma

Everything revolves around **validation contracts**, a compact description of expected
data, usable at three levels: declaratively with the `Check\*` attributes (most common),
programmatically with the `Request::validate*()` methods, or anywhere with the low-level
`\Temma\Utils\DataFilter::process($data, $contract)`.

## Contracts

Two interchangeable forms:

```php
// string form: type then parameters, separated by semicolons
'int; min: 0; max: 100'
'string; minLen: 2; maxLen: 50'
'email; mask: @mydomain\.com$'

// array form
['type' => 'int', 'min' => 0, 'max' => 100]
[
	'type' => 'assoc',
	'keys' => [
		'id'    => 'int',
		'name'  => 'string; minLen: 2',
		'email' => 'email',
	],
]
```

**Types**: `null`, `bool`, `true`, `false`, `int`, `float`, `string`; `email`, `url`,
`slug`, `uuid`, `color`, `phone`; `date`, `time`, `datetime`; `ip`, `ipv4`, `ipv6`,
`mac`, `port`; `isbn`, `ean`, `hash` (`md5`, `sha1`, `sha256`, `sha512`); `geo`; `enum`,
`list`, `assoc`; `json`, `binary`, `base64`. Combine with a pipe (`'null|int|string'`),
make nullable with `?` (`'?bool'`).

**Common parameters**: `default` (fallback on invalid data), `min`/`max`,
`minLen`/`maxLen` (K/M/G units allowed), `mask` (regex), `values` (for `enum`),
`contract` (element contract of a `list` or `json`), `keys` (for `assoc`), `mime`
(for `binary`/`base64`), `format`/`inFormat`/`outFormat` (dates).

**Strict vs non-strict**: by default validation is non-strict (converts `"42"` to 42,
clamps out-of-range numbers, truncates long strings). Strict mode requires exact types
and errors on violations. Control it globally (`$strict` parameter) or per contract with
a type prefix: `'=int'` (strict), `'~string'` (non-strict).

**Optional keys and wildcard** (in `assoc` contracts and GET/POST contracts): suffix a
key with `?` to make it optional (`'firstname?' => 'string'`); add `'...'` to accept
extra keys (optionally typed: `'...' => 'int'`). Without the wildcard, extra keys are
removed (non-strict) or cause an error (strict).

**Named contracts**: declare reusable contracts in `etc/temma.php` under
`validationTypes` (`'user' => [...]`, or a custom validator class name), then use their
name anywhere a contract is expected: `#[TµCheckPost('user')]`,
`validateInput('user')`, `DataFilter::process($data, 'user')`.

## Declarative input validation: the Check attributes

Five attributes, on a controller or an action (`\Temma\Attributes\Check\` namespace):
`Params` (URL parameters, ordered list), `Get`, `Post` (associative contracts),
`Files` (uploaded files), `Payload` (request body: JSON, base64, binary).

```php
use \Temma\Attributes\Check\Post as TµCheckPost;
use \Temma\Attributes\Check\Params as TµCheckParams;
use \Temma\Attributes\Check\Payload as TµCheckPayload;

class User extends \Temma\Web\Controller {
	#[TµCheckParams(['int; min: 1', 'slug'])]
	public function show(int $id, string $slug) { }

	// on failure: redirect to the referer, POST data copied to the '__form' flash variable
	#[TµCheckPost([
		'email' => 'email',
		'name'  => 'string; minLen: 2',
	])]
	public function create() { }

	// JSON body; validated data made available in $this['jsonData']
	#[TµCheckPayload(
		['type' => 'json', 'contract' => ['id' => 'int', 'role' => 'enum; values: user, admin']],
		dataVar: 'jsonData',
	)]
	public function import() { }
}
```

Common attribute parameters (all but `Output`): `strict` (bool), `redirect` (URL on
error), `redirectVar` (template variable holding the URL), `redirectReferer` (default
true: fall back to the HTTP referer), `flashVar` (flash variable receiving the submitted
data on redirection; default `'form'` → available as `__form`; `null` disables),
`dataVar` (template variable receiving the validated/filtered data; not for `Files`).

Redirection priority on invalid data: `redirect` > `redirectVar` > referer (if
`redirectReferer`) > `x-security` → `redirect` configuration. If none: HTTP 403.

## Programmatic validation

The `Request` methods throw a `\Temma\Exceptions\Application` exception on failure, and
can return the filtered data through an `&$output` by-reference parameter:

```php
$this->_request->validateParams(['int; min: 1', 'slug']);            // URL parameters
$this->_request->validateInput([                                     // GET and/or POST
	'name'       => 'string',
	'firstname?' => 'string',
	'age'        => '~int',
], 'POST', true);                                                    // source, strict
$this->_request->validatePayload('base64; mime: image/gif, image/png');
$this->_request->validateFiles([
	'definition' => 'json',
	'avatar?'    => 'binary; mime: image',
]);

$output = null;
$this->_request->validateInput(['email' => 'email'], 'POST', output: $output);
```

Low level, anywhere in the code:

```php
$clean = \Temma\Utils\DataFilter::process($data, ['type' => 'list', 'contract' => 'int']);
```

## Output validation

`#[TµCheckOutput([...])]` (`\Temma\Attributes\Check\Output`) declares a contract on the
**template variables** produced by the action (no redirection parameters); the same
contract can be set programmatically with `$this->_response->setValidationContract($contract)`.
The view then validates/filters outgoing data before sending it.

## Further reading

- https://www.temma.net/en/documentation/validation
- https://www.temma.net/en/documentation/helper-attr_check
- https://www.temma.net/en/documentation/helper-datafilter
