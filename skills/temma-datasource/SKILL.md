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

Not every source implements everything (a queue can't `search()`, a webhook connector is
write-only); unsupported methods throw a `\Temma\Exceptions\Database` exception.

## Source-specific APIs

Beyond the unified API, each connector keeps its native strengths. The most used: the
SQL source (`queryAll()`, `queryOne()`, `exec()`, `quote()`, `prepare()`,
`transaction()`...; see the `temma-dao` skill), the messaging sources (`$slack[''] = 'text'`
sends a notification), the AI source (see the `temma-ai` skill), the SMTP source (see the
`temma-email` skill). Each connector's page details its own methods (links in
[references/connectors.md](references/connectors.md)).

## Further reading

- https://www.temma.net/en/documentation/datasources
- https://www.temma.net/en/documentation/configuration
