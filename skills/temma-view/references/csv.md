# CSV view (`\Temma\Views\Csv`)

Serializes a list of rows to CSV. No template involved.

```php
class Api extends \Temma\Web\Controller {
	public function getUsers() {
		$this['csv'] = [
			['name', 'age', 'size'],       // first row often used as header
			['Alice', 38, 162],
			['John Doe', 31, 176],         // values are quoted as needed
		];
		$this['filename'] = 'userlist.csv';    // optional: downloadable attachment
		$this['separator'] = ';';              // optional: default is ','
		$this->_view('~Csv');
	}
}
```

- Data variable: `csv`, a list of rows, each row being a list of values
  (or `@output`, which takes priority).

Reference: https://www.temma.net/en/documentation/view-csv
