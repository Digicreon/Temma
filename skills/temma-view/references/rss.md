# RSS view (`\Temma\Views\Rss`)

Generates an RSS 2.0 feed (blog/news syndication). No template involved. The feed is
described with several template variables:

```php
class Feed extends \Temma\Web\Controller {
	public function __invoke() {
		$this['domain'] = 'https://mysite.com';        // mandatory
		$this['title'] = 'My super site';              // mandatory
		$this['description'] = 'Site description';     // mandatory
		$this['language'] = 'en';                      // optional
		$this['contact'] = 'contact@mysite.com';       // optional (managingEditor/webMaster)
		$this['copyright'] = '© 2026 MySite';          // optional
		$this['category'] = 'Blog';                    // optional (default: Blog)
		$this['articles'] = [
			[
				'title'    => 'Happy new year!',              // mandatory
				'url'      => 'https://mysite.com/page/23',   // mandatory
				'pubDate'  => '2026-01-01 00:00:00',          // optional
				'abstract' => 'Summary...',                   // optional
				'author'   => 'author@mysite.com',            // optional
				'guid'     => 'article-23',                   // optional (default: url)
			],
		];
		$this->_view('~Rss');
	}
}
```

- `@output` may carry an associative array with the same keys; it takes priority.
- `$this['filename'] = 'feed.rss';` sends the stream as a downloadable attachment.

Reference: https://www.temma.net/en/documentation/view-rss
