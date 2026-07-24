---
name: temma-tests
description: Write automated tests for a Temma PHP framework project with PHPUnit and the \Temma\Web\Test object. Use when creating integration tests for controllers and actions, mocking DAOs or services, testing redirections, HTTP codes, sessions or authenticated pages.
license: MIT
---

# Testing a Temma application

Temma provides `\Temma\Web\Test`, which executes controller actions **without an HTTP
server**, for PHPUnit integration tests: you call a URL, you get back the output stream,
the template variables, the response object or the whole dependency injection component.

## Setup

PHPUnit (e.g. the PHAR in `bin/phpunit`), plus the bootstrap shipped with the project:

```
bin/phpunit --bootstrap tests/autoload.php tests                     # all tests
bin/phpunit --bootstrap tests/autoload.php tests/ArticlesTest.php    # one file
```

Test classes live in `tests/`, are named `*Test.php` and extend
`\PHPUnit\Framework\TestCase`.

## Writing an integration test

```php
class ArticlesTest extends \PHPUnit\Framework\TestCase {
	private \Temma\Web\Test $_test;

	public function setUp() : void {
		$this->_test = new \Temma\Web\Test();
	}
	public function testList() {
		$data = $this->_test->execData('/articles/list');
		$this->assertIsArray($data['articles'] ?? null);
		$this->assertNotEmpty($data['articles']);
	}
	public function testShow() {
		$data = $this->_test->execData('/articles/show/1');
		$this->assertEquals(1, ($data['article']['id'] ?? null));
	}
}
```

## The four exec methods

All take `(string $url, string $httpMethod='GET', ?array $data=null, ?array $cookies=null)`;
`$data` carries the GET or POST parameters (per `$httpMethod`):

- **`execData()`** (the everyday one): returns the **template variables** (associative
  array), or a **string** when a redirection was set (the redirect URL), or **null** if
  execution was interrupted;
- **`execOutput()`**: the output stream rendered by the view (HTML, JSON...), for
  content-level assertions;
- **`execResponse()`**: the `\Temma\Web\Response` object; getters `getRedirection()`,
  `getRedirectionCode()`, `getHttpError()`, `getHttpCode()`, `getView()`,
  `getTemplate()`, `getTemplatePrefix()`, `getHeaders()`, `getData()`, plus array-like
  access to template variables (`$response['article']`);
- **`execLoader()`**: the whole dependency injection component created for the request
  (`config`, `request`, `response`, `session`, and every object the application code
  instantiated through it).

```php
$html = $this->_test->execOutput('/articles/create', 'POST', [
	'title' => 'New article',
	'html'  => '<p>blah</p>',
]);
$this->assertStringContainsString('<h1>', $html);
```

## Dedicated configuration and mocks

Constructor: `new \Temma\Web\Test(?string $appPath, ?string $configPath, loader: ?Loader)`.

- **Alternate configuration** (test database, disabled plugins):
  `new \Temma\Web\Test('/opt/my_project', '/opt/my_project/etc/temma-test.php');`
- **Mocking**: pre-fill a loader with substitution objects under the names the
  application uses; the code under test receives them instead of the real ones:

```php
class MockArticleDao {
	public function getList() {
		return ([['id' => 1, 'title' => 'Title 1']]);
	}
}
$loader = new \Temma\Base\Loader(['ArticleDao' => new MockArticleDao()]);
$this->_test = new \Temma\Web\Test(loader: $loader);
```

Typical things to mock: DAOs (no database needed), external connectors (use `dummy://`
data sources in the test configuration), email sending (`'x-email' => ['disabled' => true]`).

## Sessions and authenticated pages

Sessions are cookie-based; carry the session cookie from request to request:

```php
$loader = $test->execLoader('/page1');
$cookies = ['TemmaSession' => $loader->session->getSessionId()];
$data = $test->execData('/page2', 'GET', null, $cookies);
```

(`TemmaSession` is the default `sessionName` from the configuration.) To test pages
behind the built-in authentication (`temma-auth` skill): POST the email to
`/auth/authentication`, read the token from `$loader->response['token']`, validate it via
`/auth/check/<token>` with the session cookie, then test the protected pages with that
cookie. The test configuration needs
`'x-security' => ['auth' => ['robotCheckDisabled' => true]]` and
`'x-email' => ['disabled' => true]`.

## Further reading

- https://www.temma.net/en/documentation/tests
