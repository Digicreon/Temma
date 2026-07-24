---
name: temma-asynk
description: Run asynchronous and deferred processing in a Temma PHP framework project with Asynk. Use when offloading slow tasks (emails, LLM calls, imports) out of the request cycle, configuring task transport/storage (MySQL, Redis, Beanstalkd, SQS, xinetd), or setting up workers, crontab or Supervisor.
license: MIT
---

# Asynchronous processing with Asynk

Asynk defers any method call to background execution, with a one-word change: prefix the
dependency-injection access with `asynk`.

```php
// synchronous call
$this->_loader->MyObject->myMethod($param1, $param2);

// same call, executed asynchronously
$this->_loader->asynk->MyObject->myMethod($param1, $param2);

// namespaced object: array notation
$this->_loader->asynk['\My\Name\Space\MyObject']->myMethod($param1, $param2);
```

The call is recorded as a **task** (target object, method, serialized parameters) and
executed later by a separate process. Typical candidates: sending emails, LLM calls,
imports/exports, image processing.

## Architecture: transport and storage

Configuration lives in `x-asynk` (`etc/temma.php`) with two keys naming **data sources**
(see the `temma-datasource` skill):

- `storage`: where tasks are stored until processed (MySQL, Redis, or the queue itself);
- `transport`: how execution is triggered (unset = polling by crontab/workers; a
  `socket` source = xinetd; a Beanstalk or SQS source = message queue).

Supported combinations, from simplest to most scalable:

| transport | storage | Execution |
|---|---|---|
| (none) | MySQL or Redis | crontab (every minute) or polling workers |
| socket | MySQL or Redis | xinetd (immediate), crontab as backup |
| Beanstalk / SQS | same queue | workers (task data inside the queue message) |
| Beanstalk / SQS | MySQL or Redis | workers (queue signals, database holds data > 64KB/256KB) |

Recommendations: database storage + crontab is the simplest setup (add xinetd for near
real-time execution); Beanstalkd is the recommended queue for high volume (SQS works,
but is polled and billed per request).

Example (SQS transport, MySQL storage):

```php
'application' => [
	'dataSources' => [
		'db'  => 'mysql://user:pwd@host/database',
		'sqs' => 'sqs://ACCESS_KEY:SECRET@QUEUE_URL',
	],
],
'x-asynk' => [
	'transport' => 'sqs',
	'storage'   => 'db',
],
```

## MySQL storage table

```sql
CREATE TABLE Task (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	dateCreation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	dateUpdate   DATETIME ON UPDATE CURRENT_TIMESTAMP,
	status       ENUM('waiting', 'reserved', 'processing', 'error') NOT NULL DEFAULT 'waiting',
	token        CHAR(16) CHARACTER SET ascii COLLATE ascii_general_ci,
	target       TINYTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	action       TINYTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	data         MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	PRIMARY KEY (id),
	INDEX status (status),
	INDEX token (token)
);
```

Database/table/field names are remappable in `x-asynk` (`base`, `table`, `id`, `status`,
`token`, `target`, `action`, `data`), or a custom DAO can be used (`'dao' => '\MyApp\AsynkDao'`).

## Execution setups

The project skeletons ship ready-to-adapt files in `etc/asynk/`:

- **crontab** (every minute): copy `etc/asynk/crontab` to `/etc/cron.d/<name>` after
  adapting the path; it runs `bin/comma '\Temma\Cli\Asynk\Worker' crontab`;
- **xinetd** (immediate execution): declare a socket data source
  (`'sock' => 'tcp://localhost:11137'`) as `transport`, adapt and copy
  `etc/asynk/xinetd` to `/etc/xinetd.d/<name>`; combine with the crontab as a safety
  net (tasks missed by xinetd are picked up by cron);
- **workers** (background daemons): long-running `bin/comma '\Temma\Cli\Asynk\Worker'`
  processes, one task at a time each; run several in parallel for volume. Keep them
  alive with Supervisor: adapt `etc/asynk/supervisor.conf` (its `command` path and
  `numprocs`), copy it to `/etc/supervisor/conf.d/`, then `supervisorctl reread` and
  `update`. Polling workers (SQS/MySQL/Redis) wait 60 s between checks (`loopDelay`
  key to change); Beanstalkd workers are notified instantly.

## Notes

- Task parameters must be serializable (scalars, arrays); the target object is
  instantiated through the dependency injection component at execution time.
- Asynk logs under the `Temma/Asynk` log class (see the `temma-log` skill); e.g.
  `'loglevels' => ['Temma/Asynk' => 'NOTE']`.
- Failed tasks get the `error` status in database storage: monitor that column.

## Further reading

- https://www.temma.net/en/documentation/asynk
