# PHP view (`\Temma\Views\Php`)

Template-based view using plain PHP files instead of Smarty; no external library. Same
template resolution as Smarty (`templates/<controller>/<action>.php`), same template
variables, exposed as regular PHP variables.

```php
// etc/temma.php: use it as the default view
'application' => [
	'defaultView' => '\Temma\Views\Php',
],
```

```php
<!-- templates/user/show.php -->
<?php include('header.php'); ?>
<h1><?=htmlspecialchars($name)?></h1>
<ul>
	<?php foreach ($users as $user) { ?>
		<li><?=htmlspecialchars($user['name'])?></li>
	<?php } ?>
</ul>
</body>
```

- All template variables not starting with a single `_` become PHP variables in the template
  (`__`-prefixed ones are kept), or the key-value pairs of `@output` when defined
  (single-`_` keys removed; `\Temma\Exceptions\Framework` code `BAD_OUTPUT` if `@output`
  is not an array or has integer keys).
- **No auto-escaping**: unlike the Smarty view, nothing is escaped automatically. Always
  escape displayed values with `htmlspecialchars()`.

Reference: https://www.temma.net/en/documentation/view-php
