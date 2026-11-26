---
name: temma-datasource
description: Declare and use data sources (database, cache, files, queues, messaging) in a Temma PHP framework project. Use when configuring connections with DSNs (MySQL, PostgreSQL, SQLite, Redis, Memcache, S3, SQS, Beanstalk, files, sockets, Slack, Telegram, SMTP...), or when reading/writing key-value data through Temma's unified data source API.
license: MIT
---

# Temma data sources

Data sources are named connections declared in the configuration and shared by the whole
application. Most of them expose the same **unified key/value API**, which makes them
interchangeable (swap Memcache for Redis, or Beanstalkd for SQS, without changing the
calling code), while each also exposes its specific capabilities (e.g. SQL queries).

## Declaring data sources

In `etc/temma.php`, under `application` → `dataSources`, each entry maps a name to a DSN:

```php
'application' => [
	'dataSources' => [
		'db'    => 'mysql://user:passwd@localhost/my_base',
		'ndb'   => 'redis://localhost',
		'cache' => 'memcache://localhost',
		'files' => 'file:///var/data/temma',
	],
],
```

Names are free, but some are conventional: `db` (default SQL source of DAOs), `cache`
(used by the DAO cache and the Cache plugin). The full list of connectors and their DSN
formats is in [references/connectors.md](references/connectors.md). A custom class can be
used as a data source with the bracket form: `'[\MyApp\MySource]mydsn://PARAMS'`. A DSN
can also be read from an environment variable: `'env://VAR_NAME'` (avoids committing
credentials).

## Accessing data sources

```php
// in controllers and plugins: direct property access, by configured name
$db = $this->db;
$cache = $this->cache;

// everywhere else (through the dependency injection component)
$db = $this->_loader->dataSources->db;
$db = $this->_loader->dataSources['db'];
```

## Unified API

Array-like access handles serialized data (JSON, transparently):

```php
$this->cache['key1'] = $data;          // write (serialized)
$data = $this->cache['key1'];          // read (deserialized)
isset($this->cache['key1']);           // existence
unset($this->cache['key1']);           // deletion
count($this->cache);                   // number of elements
```

`$source[] = $data` (no key) is reserved for queues and messaging sources; on key-value
sources (File, Sql, Redis, Memcache, S3) it throws a `\Temma\Exceptions\Database`.

Method form, richer:

- **Serialized data**: `get($key, $defaultOrCallback, $options)`, `mGet($keys)`,
  `set($key, $value, $options)`, `mSet($data, $options)`,
  `search($pattern, $getValues, $sort, $offset, $limit)`.
- **Raw data** (strings/binary, no serialization): `read()`, `mRead()`, `write()`,
  `mWrite()`, `find()` (same signatures), plus file transfers: `copyFrom($key, $localPath)`,
  `copyTo($key, $localPath)`, `mCopyFrom()`, `mCopyTo()`.
- **General**: `isSet($key)`, `remove($key)`, `mRemove($keys)`, `clear($pattern)`,
  `flush()`, `count($pattern)`.
- **Connection** (rarely needed, connections are lazy): `connect()`, `reconnect()`,
  `disconnect()`.

The `$options` parameter is source-specific (TTL in seconds for Redis/Memcache, an
options array for S3...). The killer feature of `get()`/`read()` is the **callback
fallback**, perfect for cache-aside:

```php
// if not in cache: execute the callback, store its result, return it
$user = $this->cache->get("user:$userId", function() use ($userId) {
	return ($this->_dao->get($userId));
});
```

Callback and default rules:
- A non-callable second parameter (any value, even a string matching a PHP function name)
  is the default value, returned as is and never stored.
- A callback's result is stored then returned: `get()` stores it JSON-encoded, `read()`
  stores it raw (it must be a string, a number or `null`, otherwise a Database exception).
  A callback returning `null` stores nothing.
- Falsy stored values (`"0"`, `0`, `false`, `""`) are real values, not "missing".

Null values and multiple writes:
- `set($key, null)` / `$source[$key] = null` removes the key.
- `mSet()` / `mWrite()`: on key-value sources (File, S3, Sql, Redis, Memcache) a `null` value
  removes the key; on queues and messaging sources, `null` values are skipped (nothing is
  removed). In both cases they are not counted in the returned number.
- `mWrite()` converts numbers to strings and skips other non-string values (arrays, objects,
  booleans) on key-value sources; a value whose write fails is skipped, the others are
  written. `mSet()` also skips values that can't be JSON-encoded. Queues (Sqs, Beanstalk,
  ZeroMQ) throw a Database exception in `mWrite()` if a value is an array or an object.
- `mCopyFrom()` returns the number of keys actually copied (missing keys are not counted).

Patterns of `find()`/`search()`/`clear()`/`count()` are prefixes or glob masks
(`user:*`, `images/*`) depending on the source; regular expressions are never used.

## Disabling a source

`disable()` / `enable()` (both return the source) and `isEnabled()`. A disabled key-value
source behaves like an empty source, and writes are no-ops:
- `isSet()` false, `count()` 0, `find()`/`search()` `[]`;
- `read()`/`get()` return the default value or the callback result, which is not stored;
  `copyFrom()` writes that value to the local file and returns true (false if there is none);
- `mRead()`/`mGet()` return every requested key associated to `null`; `mCopyFrom()` 0;
- `write()`/`set()`/`copyTo()` return false; `mWrite()`/`mSet()`/`mCopyTo()` 0;
- `remove()`/`mRemove()`/`clear()`/`flush()` do nothing.

Disabled queues and messaging sources: sends return false (or null for sources returning
a message identifier, 0 for counts), reads return null. The `dummy://` source always
behaves like an empty source.

Not every source implements everything (a queue can't `search()`, a webhook connector is
write-only); unsupported methods throw a `\Temma\Exceptions\Database` exception.

## Source-specific APIs

Beyond the unified API, each connector keeps its native strengths. The most used: the
SQL source (`queryAll()`, `queryOne()`, `exec()`, `quote()`, `prepare()`,
`transaction()`...; see the `temma-dao` skill), the messaging sources (`$slack[''] = 'text'`
sends a notification), the AI source (see the `temma-ai` skill), the SMTP source (see the
`temma-email` skill). Each connector's page details its own methods (links in
[references/connectors.md](references/connectors.md)).

Two key-value specifics worth knowing:
- **File**: keys are paths relative to the root directory. Backslashes are directory
  separators; empty and `.` segments are ignored (`a//./b` is `a/b`). A key or pattern
  containing a `..` segment or a null byte makes every method throw a
  `\Temma\Exceptions\IO` (BAD_FORMAT); `mWrite()`/`mCopyFrom()` skip such keys, `mRemove()`
  throws at the first one (`....` is a normal file name). `find()`/`search()` are recursive,
  return relative paths and skip hidden files.
- **Sql** as a key-value store: requires a `TemmaData` table (MySQL/MariaDB, where keys
  must be ASCII, PostgreSQL or SQLite; see the `datasource-sql` page for the
  `CREATE TABLE`). Keys are case-sensitive; prefixes are matched literally.

## Further reading

- https://www.temma.net/en/documentation/datasources
- https://www.temma.net/en/documentation/configuration
