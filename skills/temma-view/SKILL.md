---
name: temma-view
description: Render output in a Temma PHP framework project, with the view system and Smarty templates. Use when creating or editing HTML pages, Smarty templates (.tpl files), layouts, template variables, escaping, localization, or when returning JSON, CSV, RSS, iCal, INI or plain-PHP output from a Temma controller.
license: MIT
---

# Temma views and templates

The view is the layer that formats what the server returns. The default view is
**Smarty** (HTML templates); Temma also ships views for JSON, CSV, RSS, iCal, INI and
plain-PHP templates. This skill covers the view system and Smarty; the other views are
detailed in this skill's `references/` files.

## Selecting the view

The default view is Smarty (configurable with the `defaultView` directive of
`etc/temma.php`, e.g. `'defaultView' => '~Json'` for an API). Per action or per
controller, use the `_view()` method or the `#[TµView]` attribute. The `~` prefix is a
shortcut for `\Temma\Views\`.

```php
use \Temma\Attributes\View as TµView;

class User extends \Temma\Web\Controller {
	public function export() {
		$this['csv'] = $data;
		$this->_view('~Csv');            // method form
	}

	#[TµView('~Json')]                       // attribute form
	public function getActivity(int $id) {
		$this['json'] = $activity;
	}
}
```

Built-in views and the reference file describing each one:

| View | Data variable | Reference |
|---|---|---|
| `~Smarty` (default) | all template variables | this file |
| `~Json` | `json` | [references/json.md](references/json.md) |
| `~Csv` | `csv` | [references/csv.md](references/csv.md) |
| `~Rss` | `title`, `description`, `domain`, `articles`... | [references/rss.md](references/rss.md) |
| `~ICal` | `ical` | [references/ical.md](references/ical.md) |
| `~Ini` | `data` | [references/ini.md](references/ini.md) |
| `~Php` | all template variables | [references/php.md](references/php.md) |

Data is passed through template variables (`$this['name'] = $value;` in the controller).
Template-based views (Smarty, PHP) receive all variables not starting with `_`; other
views read their specific variable (see table). Alternatively, assigning `$this['@output']`
takes priority: an associative array of template variables for template-based views, or
the raw data to serialize for the other views. Most non-template views also honor a
`filename` variable to send the stream as a downloadable attachment.

## Smarty templates

Template files live in the project's `templates/` directory. The default template for an
action is `templates/<controller>/<action>.tpl`; override it with
`$this->_template('path.tpl')` or `#[TµTemplate('path.tpl')]` (see the `temma-controller`
skill).

Core syntax (see https://smarty-php.github.io/smarty/stable/ for the full language):

```smarty
{* comment *}
{$name}                          {* display a variable *}
{$colors.red}                    {* associative array key or object property *}
{$fruits[2]}                     {* list element *}

{if $user.roles.admin}
    <a href="/user/show/{$user.id}">My account</a>
{else}
    ...
{/if}

<ul>
    {foreach $users as $user}
        <li>{$user.name}</li>
    {foreachelse}
        <li>No user.</li>
    {/foreach}
</ul>

{include file="header.tpl"}      {* template inclusion, for layouts *}
```

Common layout pattern: shared `header.tpl` / `footer.tpl` included from each page
template, or Smarty template inheritance (`{extends}` / `{block}`).

## Escaping (XSS protection)

Temma enables Smarty's **auto-escape** by default: every displayed variable is
HTML-escaped (`<` becomes `&lt;`...). Consequences:

- never add `|escape` for plain HTML output, it is already done;
- to output trusted raw HTML, use `{$html|raw}` explicitly (and only for trusted content);
- specialized escaping contexts still use the modifier: `{$s|escape:'javascript'}`,
  `{$s|escape:'url'}`, `{$s|escape:'quotes'}`...;
- literal braces (inline JavaScript/CSS) must be wrapped in `{literal}...{/literal}`.

Auto-escaping can be disabled with `'x-smarty' => ['autoEscape' => false]` in
`etc/temma.php`, but keep it enabled unless the project explicitly manages escaping
manually. (The former `x-smarty-view` section is deprecated; use `x-smarty`.)

## Smarty plugins

Custom plugins are PHP functions in files named `modifier.<name>.php`,
`function.<name>.php` or `block.<name>.php`, placed in the project's
`lib/smarty-plugins/` directory (additional directories via `'x-smarty' => ['pluginsDir' => ...]`):

```php
// lib/smarty-plugins/modifier.warp.php
function smarty_modifier_warp($text) {
	return (str_replace('T', '.', $text));
}
// usage in templates: {$variable|warp}
```

Temma ships plugins, usable out of the box: `|urlize` (URL-friendly string),
`|filenamize` (filename-friendly string), `|nbsp` (non-breaking spaces before French
punctuation), `|hash` (hash a value), `|dump` / `{dump}` (debug dump), and `|l10n` /
`{l10n}` for translations.

## Localization

The `\Temma\Plugins\Language` pre-plugin manages per-language translation files and
exposes the `{l10n}` block and `|l10n` modifier in templates:

```smarty
{l10n}Text to translate{/l10n}
{$str|l10n}
```

See https://www.temma.net/en/documentation/helper-plugin_language for the full setup
(translation files, domains, contexts, plurals).

## Further reading

- https://www.temma.net/en/documentation/views
- https://www.temma.net/en/documentation/view-smarty
- https://www.temma.net/en/documentation/helper-attr_view
- https://www.temma.net/en/documentation/helper-attr_template
