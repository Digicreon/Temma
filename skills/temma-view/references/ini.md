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

- Data variable: `data` (or `@output`, which takes priority).

Reference: https://www.temma.net/en/documentation/view-ini
