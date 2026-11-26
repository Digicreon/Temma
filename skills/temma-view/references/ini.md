# INI view (`\Temma\Views\Ini`)

Serializes PHP data to an INI file. No template involved.

```php
class Api extends \Temma\Web\Controller {
	public function getUsers() {
		$this['data'] = [
			'user1' => ['name' => 'Alice', 'age' => 28],   // first-level keys become [sections]
			'user2' => ['name' => 'Bob', 'age' => 54],
		];
		$this['filename'] = 'userlist.ini';    // optional: downloadable attachment
		$this->_view('~Ini');
	}
}
```

- Data variable: `data` (or `@output`, which takes priority). If neither is defined, all
  template variables are used, except `URL`, `CONTROLLER`, `ACTION`, `filename` and the
  variables whose name starts with `_`. No data gives an empty stream.
- The data must be an associative array of at most 3 levels (first-level scalars are written
  before the sections), with keys valid in INI; otherwise `\Temma\Exceptions\IO` is raised
  (see `Serializer::encodeIni()`).

Reference: https://www.temma.net/en/documentation/view-ini
