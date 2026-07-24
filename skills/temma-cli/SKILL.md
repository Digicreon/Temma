---
name: temma-cli
description: Write command-line scripts and cron jobs in a Temma PHP framework project, executed with bin/comma. Use when creating CLI commands, batch scripts, scheduled tasks, terminal output (colors, progress bars) or interactive prompts in a Temma application.
license: MIT
---

# Temma command-line scripts (Comma)

Comma (COMmand-line MAnager) runs CLI controllers with the whole framework initialized:
configuration, log, autoloader, dependency injection and data sources. Commands are
invoked as:

```
bin/comma <Controller> <action> [--param1=value1] [--param2=value2]...
bin/comma "\MyApp\Cli\CrmManager" pushData        # namespaced controller: quote the name
bin/comma help [Controller [action]]              # built-in documentation, from PHPDoc
```

## Writing a CLI controller

Same rules as web controllers (see the `temma-controller` skill), but the file lives in
the project's **`cli/`** directory:

```php
/** Users management. */
class User extends \Temma\Web\Controller {
	protected $_temmaAutoDao = true;

	/**
	 * Add a user.
	 * @param	string	$name	Name of the user.
	 * @param	string	$email	Email address of the user.
	 * @param	bool	$admin	(optional) Administrator rights. False by default.
	 */
	public function add(string $name, string $email, bool $admin=false) {
		$id = $this->_dao->create([
			'name'  => $name,
			'email' => $email,
			'roles' => $admin ? 'admin' : '',
		]);
		print("User created with identifier '$id'.\n");
	}
}
```

Invocation: `bin/comma User add --name="Luke Skywalker" --email=luke@rebellion.org --admin`

- Parameters map to **named** command-line options (`--email=...`); a parameter given
  without a value is `true` (boolean flags). Optional method parameters have defaults.
- `__invoke()` (root action, no parameters), `__call()`, `__proxy()`, `__wakeup()`,
  `__sleep()` behave as in web controllers. Attributes run too (when they make sense
  outside a web context).
- Available as usual: `$this->_loader`, `$this->_config`, data sources (`$this->db`),
  DAOs (`$_temmaAutoDao`, `$this->_dao`). The PHPDoc of the controller and its actions
  feeds `bin/comma help`.
- Differences with web: no routing, no plugins, no views (write to stdout), and
  `$this->_request` only carries the command-line parameters.

Options placed **before** the controller name tune Comma itself:
`bin/comma conf=etc/temma-test.php User add ...` (alternate configuration file),
`inc=/path` (additional include path), `nostderr` (do not log to stderr; by default CLI
logs go to stderr, plus `log/temma.log` if configured).

Built-in commands: `bin/comma "\Temma\Cli\Cache" ...` (cache clearing),
`bin/comma "\Temma\Cli\User" ...` (user management for the Auth system).

## Terminal output: `\Temma\Utils\Ansi`

Static helpers producing ANSI-decorated strings:

```php
use \Temma\Utils\Ansi as TµAnsi;

print(TµAnsi::bold("Important\n"));
print(TµAnsi::color('green', "OK") . ' ' . TµAnsi::faint("details") . "\n");
print(TµAnsi::backColor('red', 'white', "Error") . "\n");
print(TµAnsi::title1("Big section title"));
```

- Styles: `bold()`, `faint()`, `italic()`, `underline()`, `negative()`, `strikeout()`.
- Colors: `color($textColor, $text)`, `backColor($backColor, $textColor, $text)` with
  named colors (`red`, `green`, `blue`, `yellow`...) or xterm-256 codes.
- Structure: `title1()`...`title4()`, `block()`, plus a mini-markup language via
  `style()`/`setStyle()` (`<b>`, `<color t='red'>`... tags).
- Animations: `throbberStart()`/`throbberGo()`/`throbberEnd()` (spinner) and
  `progressStart()`/`progressGo()`/`progressEnd()` (progress bar, styled with
  `setProgressStyle()`).
- `TµAnsi::strlen()` and `wordwrap()` compute on visible characters (ignoring ANSI codes).

## Terminal interaction: `\Temma\Utils\Term`

Static helpers to read input and control the terminal:

```php
use \Temma\Utils\Term as TµTerm;

print('Path of the file to import: ');
$path = TµTerm::input();               // read a line from the user
print('Password: ');
$pass = TµTerm::password();            // read without echoing
```

Also: `clear()`, `clearLine()`, `hideCursor()`/`showCursor()`, cursor movement
(`moveCursorTo($x, $y)`, `moveCursorUp()`...), `getScreenSize()`, `getCursorPosition()`.

## Cron jobs

A Comma command is directly usable in a crontab; use absolute paths:

```
42 3 * * * /path/to/project/bin/comma nostderr Backup run
```

For long or deferred processing triggered from web requests, see the `temma-asynk` skill.

## Further reading

- https://www.temma.net/en/documentation/cli
- https://www.temma.net/en/documentation/helper-ansi
- https://www.temma.net/en/documentation/helper-term
