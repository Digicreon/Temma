---
name: temma-dao
description: Access databases in a Temma PHP framework project using DAOs (data access objects) and search criteria. Use when reading or writing SQL data, creating a model layer, writing CRUD code, custom DAO classes, search/sort criteria, joins or raw SQL queries in a Temma application.
license: MIT
---

# Temma DAOs (model layer)

Temma provides a generic DAO (`\Temma\Dao\Dao`) for single-table CRUD, extensible into
custom DAO classes for business methods and joins. DAOs use the SQL data source named `db`
declared in `etc/temma.php`, and transparently use the `cache` data source when defined
(see the `temma-datasource` skill for data source declaration).

## Automatic DAO in a controller

The fastest path: a controller sets `$_temmaAutoDao` and gets a ready-to-use DAO in
`$this->_dao`, bound to the table named after the controller:

```php
class Article extends \Temma\Web\Controller {
	// ask Temma to create the DAO automatically (table "article", primary key "id")
	protected $_temmaAutoDao = true;

	public function show(int $id) {
		$this['article'] = $this->_dao->get($id);
	}
}
```

`$_temmaAutoDao` also accepts the name of a custom DAO class (`'ArticleDao'`) or a
configuration array (`cache` false to disable caching, `base`, `table`, `id` for the
primary key field, `fields` to list/rename fetched fields, `criteria` for a custom
criteria class).

To use several DAOs in one controller, call `$this->_loadDao('\MyApp\UserDao')` (or pass
a configuration array) from `__wakeup()`.

## CRUD methods

```php
$cnt      = $this->_dao->count($criteria);                    // count (criteria optional)
$item     = $this->_dao->get(12);                             // one record by primary key (or first match of a criteria)
$list     = $this->_dao->search($criteria, $sort, $offset, $limit);
$newId    = $this->_dao->create(['title' => 'Hi', 'status' => 'draft']);
$nbrMod   = $this->_dao->update($idOrCriteria, ['status' => 'published']);
$nbrDel   = $this->_dao->remove($idOrCriteria);
```

- `get()` and `search()` return associative arrays (field name → value, renamed if a
  `fields` mapping was configured).
- `create()` returns the new primary key. Its optional second parameter handles duplicate
  keys ("upsert"): `true` updates all given fields, a field name updates that field, an
  associative array updates those fields.
- `update()` and `remove()` accept a primary key, a criteria, or null (all records; be careful).

## Search criteria

The simplest criteria is an associative array (`['login' => 'Bob']`, combined with AND).
For anything richer, build a `\Temma\Dao\Criteria` with `$this->_dao->criteria()`:

```php
$criteria = $this->_dao->criteria()               // criteria('or') to combine with OR
            ->equal('email', 'tom@tom.com')       // also accepts a list of values
            ->like('title', '%PHP%')
            ->greaterThan('age', 12)
            ->is('free');                         // boolean field is true
$users = $this->_dao->search($criteria);
```

Available methods: `equal()`/`eq()`, `different()`/`ne()`, `like()`, `notLike()`,
`is()`/`has()`, `isNot()`/`hasNot()`, `lessThan()`/`lt()`, `greaterThan()`/`gt()`,
`lessOrEqualTo()`/`le()`, `greaterOrEqualTo()`/`ge()`.

Nested boolean logic uses the `and()` / `or()` methods with a sub-criteria:

```php
// email in list AND (age <= 12 OR age > 24)
$criteria = $this->_dao->criteria()
            ->equal('email', ['john@john.com', 'bob@bob.com'])
            ->and($this->_dao->criteria('or')
                  ->lessOrEqualTo('age', 12)
                  ->greaterThan('age', 24));
```

## Sort criteria

Second parameter of `search()` (and third of `update()`):

```php
$sort = 'birthday';                            // ascending
$sort = '-birthday';                           // descending (leading dash)
$sort = ['birthday', '-points'];               // multiple fields
$sort = ['birthday' => 'asc', 'points' => 'desc'];
$sort = false;                                 // random order
```

## Custom DAO classes

For business methods and configuration, extend `\Temma\Dao\Dao` in a file under the
project's `lib/` directory (file name = class name):

```php
class ArticleDao extends \Temma\Dao\Dao {
	protected $_tableName = 'content';        // optional configuration
	protected $_idField = 'cid';
	protected $_disableCache = true;

	/** Articles written in the last 24 hours. */
	public function getLastArticles() : array {
		$criteria = $this->criteria()->greaterThan('date', date('c', time() - 86400));
		return ($this->search($criteria, '-date'));
	}
}
```

Optional protected attributes: `_tableName`, `_dbName`, `_idField`, `_fields`
(list/renaming of fetched fields), `_disableCache`, `_criteriaObject`.

Custom criteria classes (business-named filters) extend `\Temma\Dao\Criteria`; register
them with the `_criteriaObject` DAO attribute, then chain them like built-in criteria:

```php
class ArticleDaoCriteria extends \Temma\Dao\Criteria {
	public function mostRecent() {
		$this->greaterThan('date', date('c', time() - 86400));
		return ($this);
	}
}
// in the controller: $this->_dao->search($this->_dao->criteria()->mostRecent());
```

## Joins and raw SQL

For joins, skip the DAO abstraction and write SQL in a custom DAO method, using the
underlying connection `$this->_db` (disable the cache in that case):

```php
class ArticleDao extends \Temma\Dao\Dao {
	protected $_disableCache = true;

	public function getArticlesFromEmail(string $email) : array {
		$sql = "SELECT * FROM article
		        INNER JOIN user ON (article.user_id = user.id)
		        WHERE user.email = " . $this->_db->quote($email) . "
		        ORDER BY article.date DESC";
		return ($this->_db->queryAll($sql));
	}
}
```

Key SQL data source methods: `queryAll($sql)` (list of rows), `queryOne($sql)` (single
row or value), `exec($sql)` (write query), `quote($str)` / `quoteNull($str)` (escaping;
ALWAYS quote interpolated values), `prepare($sql)` (prepared statements, `?` placeholders),
`transaction(callable)` or `startTransaction()`/`commit()`/`rollback()`, `lastInsertId()`.

## Cache

When a `cache` data source exists, DAO reads are cached and writes invalidate. To bypass
it temporarily (random sorts, read-after-write consistency):

```php
$result = $this->_dao->disableCache()->search();
$this->_dao->enableCache();
```

## Further reading

- https://www.temma.net/en/documentation/model
- https://www.temma.net/en/documentation/model-dao_auto
- https://www.temma.net/en/documentation/model-dao_custom
- https://www.temma.net/en/documentation/datasource-sql
