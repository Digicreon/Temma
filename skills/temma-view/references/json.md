# JSON view (`\Temma\Views\Json`)

Serializes PHP data to JSON. No template involved.

```php
class Api extends \Temma\Web\Controller {
	public function getUser(int $id) {
		$this['json'] = [
			'name' => 'Albert Einstein',
			'type' => 'genius',
		];
		$this->_view('~Json');
	}
}
```

- Data variable: `json` (or `@output`, which takes priority).
- `$this['filename'] = 'export.json';` sends the stream as a downloadable attachment.
- `$this['jsonDebug'] = true;` pretty-prints the stream (indented, multi-line) instead of
  the default compact single-line output.

Reference: https://www.temma.net/en/documentation/view-json
