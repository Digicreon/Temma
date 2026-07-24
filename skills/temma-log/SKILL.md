---
name: temma-log
description: Write and configure logs in a Temma PHP framework project. Use when adding log messages, tuning log levels and log classes, sending logs to Syslog, Datadog or a centralized platform with log managers, or debugging with the log/temma.log file.
license: MIT
---

# Logging in Temma

`\Temma\Base\Log` writes application logs, by default to `log/temma.log` (and to stderr
for CLI scripts). It is initialized by the framework: just use it.

## Writing log messages

```php
use \Temma\Base\Log as TµLog;

TµLog::l("Always-written message");                       // unconditional
TµLog::l(['zone' => 'internal', 'idx' => 3]);             // any data (print_r-serialized)

TµLog::log('WARN', "Something odd happened.");            // level + message
TµLog::log('myapp', 'DEBUG', "Entering import loop.");    // log class + level + message
```

**Levels**, from lowest to highest criticality: `DEBUG`, `INFO` (default of an unleveled
message), `NOTE`, `WARN`, `ERROR`, `CRIT`.

**Log classes** partition messages by subsystem (free labels: `'myapp'`, `'data'`...;
the framework uses `Temma/Base`, `Temma/Web`, `Temma/Asynk`...). A message appears only
if its level reaches the threshold configured for its class (default class threshold:
`NOTE`).

## Configuring thresholds (`etc/temma.php`)

```php
// single threshold for everything
'loglevels' => 'ERROR',

// or per log class
'loglevels' => [
	'default'    => 'WARN',       // application messages without a class
	'myapp'      => 'DEBUG',      // verbose during development
	'Temma/Base' => 'ERROR',      // framework internals
],
```

Also: `logFile` (`application` section) redefines or disables (`false`) the log file;
`bufferingLoglevels` buffers low-level messages and flushes them only when an error
occurs. In development, raising a class to `DEBUG` is the standard way to trace
execution; in production, keep `WARN`/`ERROR`.

## Runtime control

Static methods, rarely needed (the framework initializes everything):
`TµLog::disable()`/`enable()`, `logToStdOut(bool)`, `logToStdErr(bool)`
(default for CLI), `setLogFile($path)`, `addCallback($fn)` (called for each message with
request id, message, level, class).

## Log managers (Syslog, Datadog, custom)

Log managers receive every log message, to forward them to a centralized platform
(instead of, or in addition to, the log file). Declared in the `application` section:

```php
'application' => [
	'logManager' => '\Temma\LogManagers\Syslog',       // or a list of managers
	// 'logFile' => false,                             // to disable local file writing
],
```

Built-in managers:
- `\Temma\LogManagers\Syslog`: sends to the local syslog daemon; option in
  `'x-syslog' => ['facility' => 'LOG_LOCAL0']`;
- `\Temma\LogManagers\Datadog`: sends to Datadog; configuration in `'x-datadog'`
  (API key, `service` name...; see the doc page).

A custom manager implements `\Temma\Web\LogManager` with a
`log($requestId, $message, $level, $class)` method; the 4-character request identifier
groups all messages of a single request. Declare its class name in `logManager`.

## Good practices

- Use log classes from day one (`TµLog::log('myapp', ...)`) so production verbosity can
  be tuned per subsystem.
- Reserve `l()` for temporary debugging: it bypasses thresholds.
- `ERROR`/`CRIT` should mean "someone must look at this": pair them with a log manager
  or monitoring in production.

## Further reading

- https://www.temma.net/en/documentation/log
- https://www.temma.net/en/documentation/log-managers
- https://www.temma.net/en/documentation/log-syslog
- https://www.temma.net/en/documentation/log-datadog
