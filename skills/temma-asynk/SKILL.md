---
name: temma-asynk
description: Run asynchronous and deferred processing in a Temma PHP framework project with Asynk. Use when offloading slow tasks (emails, LLM calls, imports) out of the request cycle, configuring task transport/storage (MySQL, Redis, Beanstalkd, SQS, xinetd), setting up workers, crontab or Supervisor, configuring retries of failed tasks, or managing tasks (AsynkManager command).
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

- `storage`: where tasks are stored until processed (MySQL or Redis; not set with a
  Beanstalk or SQS transport, the queue holds the tasks);
- `transport`: how execution is triggered (unset = polling by crontab/workers; a
  `socket` source = xinetd; a Beanstalk or SQS source = message queue).

Supported combinations, from simplest to most scalable:

| transport | storage | Execution |
|---|---|---|
| (none) | MySQL or Redis | crontab (every minute) or polling workers |
| socket | MySQL or Redis | xinetd (immediate), crontab as backup (processes the tasks missed by xinetd and the delayed tasks) |
| Beanstalk / SQS | (none) | workers (task data inside the queue message: 64 KB max with Beanstalk, 256 KB with SQS) |

Any other combination (e.g. a queue transport with a storage) throws a
`\Temma\Exceptions\Framework` (CONFIG) when Asynk is used.

Recommendations: database storage + crontab is the simplest setup (add xinetd for near
real-time execution); Beanstalkd is the recommended queue for high volume (SQS works,
but is polled and billed per request).

Example (SQS transport):

```php
'application' => [
	'dataSources' => [
		'sqs' => 'sqs://ACCESS_KEY:SECRET@QUEUE_URL',
	],
],
'x-asynk' => [
	'transport' => 'sqs',
],
```

## MySQL storage table

```sql
CREATE TABLE Task (
	id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
	dateCreation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	dateUpdate      DATETIME ON UPDATE CURRENT_TIMESTAMP,
	status          ENUM('waiting', 'reserved', 'processing', 'error') NOT NULL DEFAULT 'waiting',
	token           CHAR(16) CHARACTER SET ascii COLLATE ascii_general_ci,
	target          TINYTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	action          TINYTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	data            MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	-- fields used only when retries are enabled ("retries" configuration)
	attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
	dateNextAttempt DATETIME,
	error           TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	PRIMARY KEY (id),
	INDEX status (status, dateNextAttempt),
	INDEX token (token)
);
```

Without the retry fields, the `status` index only contains `status`. Database/table/field names are remappable in `x-asynk` (`base`, `table`, `id`, `dateCreation`,
`dateUpdate`, `status`, `token`, `target`, `action`, `data`, `attempts`, `dateNextAttempt`,
`error`), or a custom DAO can be used (`'dao' => '\MyApp\AsynkDao'`). The `attempts`,
`dateNextAttempt` and `error` fields are needed only if `retries` is set (even to an empty
list, which stores the error message and the number of attempts without retrying); add
them to an existing table with:

```sql
ALTER TABLE Task
	ADD attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
	ADD dateNextAttempt DATETIME,
	ADD error TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
	DROP INDEX status,
	ADD INDEX status (status, dateNextAttempt);
```

## Retries

By default, a failed task is put aside at once (except with SQS, see below). The
`retries` key of `x-asynk` lists the delays before each new attempt (integers in
seconds, or strings with an optional unit `s`, `m`, `h`, `d`, case-insensitive);
`true` gives the default list `['1m', '2m', '15m', '1h', '4h', '12h', '1d', '2d']`
(8 new attempts over about 3.7 days, suited to a temporarily unavailable external
service); `false` or no key disables retries. An invalid delay throws a
`\Temma\Exceptions\Framework` (CONFIG) when Asynk is used.

```php
'x-asynk' => [
	'storage' => 'db',
	'retries' => ['30s', '5m', '1h'],   // or true
],
```

Only exceptions are retried: a PHP error (`\Error`, e.g. a `TypeError`) or an unknown
target object or method puts the task aside immediately (except with SQS). Behavior by
configuration:

| Configuration | New attempt | Task put aside |
|---|---|---|
| MySQL storage | `waiting` status with `dateNextAttempt` (UTC; needs the extra fields) | `error` status |
| Redis storage | identifier in the `asynk_delayed` sorted set (score = date of next attempt), moved back to `asynk` when due (checked at most every 10 s by each worker or crontab run) | identifier in the `asynk_failed` list |
| Beanstalkd | job released with a delay | job buried |
| SQS | visibility timeout changed (SQS limits it to 12 hours after the reception: longer delays are reduced) | nothing: the message comes back after its visibility timeout; when it was received more than retries + 1 times, it is hidden 12 hours without being processed; configure a dead-letter queue in AWS (`maxReceiveCount` = number of retries + 1) |

With SQS, retries need a recent AWS SDK (3.320 or later; older versions silently ignore
the `MessageSystemAttributeNames` parameter used to get the number of receptions, so
the first delay would always be used).

With xinetd (MySQL or Redis storage), delayed tasks are processed by the crontab, which
must be configured; xinetd ignores a task which is not waiting or whose next attempt date
is not reached. The error message (`"ExceptionClass: message"`, invalid UTF-8
characters replaced, truncated to 10,000 bytes) and the number of attempts are stored
with MySQL (extra fields) and Redis; Beanstalkd and SQS count the attempts themselves.

**Crashes count as attempts** (when `retries` is set): the number of attempts is
incremented when the processing starts, so a processing interrupted by a crash of the
worker (fatal error, memory limit, killed process) is counted. MySQL and Redis: see
`recover` below. Beanstalkd: a job whose reservation expired (Asynk jobs have a time to
run of 1 hour) or whose worker disconnected is delivered again; if it was reserved more
times than the number of retries + 1, it is buried without being processed. SQS: the
receive count includes the crashes. Invalid messages (not a task) are put aside too.

## Task management: AsynkManager command

```sh
bin/comma AsynkManager/stats                  # number of waiting, delayed, processing, failed tasks
bin/comma AsynkManager/list                   # tasks (20 per state by default)
bin/comma AsynkManager/list/failed            # or --state=failed --limit=50
bin/comma AsynkManager/show/123               # details: parameters, attempts, last error...
bin/comma AsynkManager/retry/123              # failed or delayed task back in the queue (attempts reset; notified to xinetd)
bin/comma AsynkManager/retry/all              # all failed tasks back in the queue
bin/comma AsynkManager/remove/123             # remove a task permanently
bin/comma AsynkManager/purge                  # remove all failed tasks
bin/comma AsynkManager/recover --minutes=120  # tasks processing for more than N minutes (crashed workers; 60 by default)
```

States: `waiting`, `delayed`, `processing`, `failed`. All actions work with MySQL and Redis
storages, with or without xinetd (Redis task identifiers are strings like
`1764150000.123456-3fXk9b`). `attempts` is the number of started attempts. Dates are
displayed in the `YYYY-MM-DD HH:MM:SS` format, in the local time zone. Terminal control
sequences are removed from the displayed values (they may come from user data).
`list`: MySQL tasks sorted by identifier, Redis tasks the oldest first in each state.
`recover`: without `retries`, the tasks are put back in the queue; with `retries`, the
interrupted processing counts as an attempt (new attempt with the configured delay, or
task put aside; error message `Processing interrupted (crashed worker?).`); MySQL tasks
reserved but not started are put back without counting an attempt; the duration must be
greater than the longest task, otherwise a task could be processed twice; it can be run
by cron (see `etc/asynk/crontab`).
Limits: Beanstalkd lists only the first failed job (`list` without state lists it),
`retry` creates the job again (new identifier, counters reset), a job being processed
can't be removed, `recover` is not available (Beanstalkd puts back expired
reservations); SQS only supports `stats` (approximate).

The same operations are available in PHP through `$this->_loader['\Temma\Asynk\AsynkDao']`
(`getStats()`, `listTasks()`, `getTask()`, `retryTask()`, `retryFailedTasks()`,
`removeTask()`, `purgeFailedTasks()`, `recoverTasks()`). With SQS, `stats` numbers are
approximate, a task waiting for a new attempt is counted as `processing` (its visibility
timeout is not reached), and `delayed` stays at 0.

## Execution setups

The project skeletons ship ready-to-adapt files in `etc/asynk/`:

- **crontab** (every minute): copy `etc/asynk/crontab` to `/etc/cron.d/<name>` after
  adapting the path; it runs `bin/comma '\Temma\Cli\Asynk\Worker' crontab`;
- **xinetd** (immediate execution): declare a socket data source
  (`'sock' => 'tcp://localhost:11137'`) as `transport`, adapt and copy
  `etc/asynk/xinetd` to `/etc/xinetd.d/<name>`; combine with the crontab as a safety
  net (tasks missed by xinetd and delayed tasks are picked up by cron); restrict the
  access to the port (`only_from`, `bind`, or a firewall);
- **workers** (background daemons): long-running `bin/comma '\Temma\Cli\Asynk\Worker'`
  processes, one task at a time each; run several in parallel for volume. Keep them
  alive with Supervisor: adapt `etc/asynk/supervisor.conf` (its `command` path and
  `numprocs`), copy it to `/etc/supervisor/conf.d/`, then `supervisorctl reread` and
  `update`. Polling workers (SQS/MySQL/Redis) wait 60 s between checks (`loopDelay`
  key to change; with SQS, each check first waits for the DSN's long polling duration);
  Beanstalkd workers are notified instantly and don't use `loopDelay`.

## Notes

- Task parameters must be JSON-encodable (scalars, arrays), otherwise the asynchronous
  call throws a `\Temma\Exceptions\Application` (BAD_PARAM); the target object is
  instantiated through the dependency injection component at execution time.
- Asynk logs under the `Temma/Asynk` log class (see the `temma-log` skill); e.g.
  `'loglevels' => ['Temma/Asynk' => 'NOTE']`.
- Failed tasks (exception or PHP error) are logged as WARN, then retried or put aside
  (see "Retries"); manage them with the `AsynkManager` command.
- Redis storage (with or without xinetd): tasks in the `asynk_tasks` hash (identifier =>
  task); identifiers in the lists `asynk` (waiting), `asynk_processing`, `asynk_failed`
  and the sorted set `asynk_delayed`; reservations in the `asynk_claims` hash (a task
  is processed only once, even if its identifier is several times in a list).
- Upgrade from a previous version: the Redis storage format changed (tasks waiting in
  Redis must be processed before the upgrade); `AsynkDao::setTaskStatusFromId()` and
  `removeTaskFromId()` were removed; `invalidateFetchedTask()` returns the delay before
  the next attempt (`?int`) and takes the error message and a retry flag; with xinetd,
  `getTaskFromId()` doesn't reserve the task anymore (use `isTaskReady()` and
  `reserveTask()`).

## Further reading

- https://www.temma.net/en/documentation/asynk
