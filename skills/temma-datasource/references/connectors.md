# Temma data source connectors and DSN formats

Documentation pages: `https://www.temma.net/en/documentation/datasource-<name>`.

## Databases and caches

| Connector | DSN | Notes |
|---|---|---|
| SQL (`Sql`) | `mysql://user:passwd@host[:port]/base`, `pgsql://...`, `sqlite:/path/to/file.sq3`, also `mysqli`, `mssql`, `oci`, `firebird`... | default DAO source; key-value mode needs a `TemmaData` table (MySQL/MariaDB, PostgreSQL, SQLite); doc: `datasource-sql` |
| Redis | `redis://host[:port][/db]`, `redis-sock:///path/to/socket[#db]` | TTL via `$options` (seconds); patterns are glob-style (`user:*`); lists: `lPush()`, `rPop()`, `rPopLPush()`, `lRem()`, `lLen()`, `lRange($list, $start, $stop)`; `rPush()` adds at the end; sorted sets: `zAdd($set, $score, $data)`, `zRangeByScore($set, $min, $max, $offset, $count)` (`'-inf'`/`'+inf'` accepted; offset used only with a count), `zRem($set, $data)`, `zCard($set)`; hashes: `hSet($hash, $field, $data)`, `hSetNx()` (atomic, false if the field exists), `hGet()` (null if missing), `hGetAll()`, `hDel()` (JSON-encoded values); doc: `datasource-redis` |
| Memcache | `memcache://host[:port][;host2[:port2]][#superprefix]`, Unix socket `memcache:///path.sock:0` | TTL via `$options`; the super-prefix (hashed) is added to all keys: HTTP_HOST without `#`, none with an empty `#`; doc: `datasource-memcache` |

## Files and object storage

| Connector | DSN | Notes |
|---|---|---|
| File | `file:///path/to/dir`, `file://0007/path/to/dir` (umask) | keys are relative file paths (`..` segments and null bytes rejected with an IO exception); recursive search like on S3 (`*` also matches `/`, only files are returned, hidden files skipped); doc: `datasource-file` |
| S3 | `s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET[:public\|:private]` (private by default) | needs `lib/aws.phar` (loaded only if the AWS SDK is not already available); `$options` for ACL/mimetype; doc: `datasource-s3` |

## Queues and sockets

| Connector | DSN | Notes |
|---|---|---|
| Beanstalk | `beanstalk://host[:port]/tube_name` | needs Pheanstalk 5 (`composer require pda/pheanstalk:^5`); `write()`/`set()` options: `['priority' => 1024, 'delay' => 0, 'ttr' => 60]` (time to run: seconds before an unfinished reservation is put back in the queue); `isValidId($id)` checks an identifier; `get('')`/`$bean['']` blocks until a message arrives, `get('', null, N)` waits at most N seconds (0 to 20) and returns null; `touch($id)` extends a reservation, `bury($id)` puts a reserved job aside, `kick($id)` / `kick()` puts one / all buried jobs back (returns the count), `release($id, $delay)` puts a reserved job back in the queue (delivered again after `$delay` seconds); `stats()` returns the number of `ready`/`reserved`/`delayed`/`buried` jobs of the tube, `stats($id)` the tube, state and counters of a job (`releases`, `reserves`...; null if unknown); `peek($id)` / `peekBuried()` read a job (of any tube) / the first buried job without reserving it; `getTube()` returns the tube name; `kick($id)` also kicks a delayed job; doc: `datasource-beanstalk` |
| SQS | `sqs://ACCESS_KEY:SECRET_KEY@QUEUE_URL[#WAIT]` (QUEUE_URL without `https://`, e.g. `sqs.eu-west-3.amazonaws.com/123456789012/queue_name`) | `#WAIT`: long polling wait, 0 to 20 seconds; needs `lib/aws.phar` (loaded only if the AWS SDK is not already available); received messages also have a `receiveCount` key; `changeVisibility($id, $seconds)` delivers a received message again after the given delay (reduced if needed: SQS limits it to 12 hours after the reception; nothing is done after 12 hours) and returns the applied delay; `receiveCount` needs the AWS SDK 3.320 or later; `stats()` returns the approximate number of `waiting`, `inFlight` and `delayed` messages; doc: `datasource-sqs` |
| ZeroMQ | `zmq://TYPE@host:port` (connect), `zmq-bind://TYPE@host:port` (bind); TYPE: `REQ`, `REP`, `PUSH`, `PULL`, `PUB`, `SUB`; several hosts separated by `;` | needs the `zmq` extension; doc: `datasource-zeromq` |
| Socket | `TYPE://host:port[#TIMEOUT]`, TYPE: `tcp`, `udp`, `ssl`, `tls`, `tlsv1.2`, `tlsv1.3`; IPv6 host between brackets (`tls://[fe80::1]:443#5`); `unix:///path` | `#TIMEOUT`: connection timeout in seconds; doc: `datasource-socket` |

## Messaging and notifications

| Connector | DSN | Notes |
|---|---|---|
| Slack | `slack://hooks.slack.com/services/TXX/BYY/ZZZ` | `$slack[''] = 'message';` doc: `datasource-slack` |
| Discord | `discord://discord.com/api/webhooks/ID/TOKEN` | doc: `datasource-discord` |
| Google Chat | `googlechat://chat.googleapis.com/v1/spaces/XXX/messages?key=YYY&token=ZZZ` | doc: `datasource-googlechat` |
| Telegram | `telegram://API_TOKEN` (chat identifier given as the key when sending) | doc: `datasource-telegram` |
| Pushover | `pushover://APP_TOKEN` | doc: `datasource-pushover` |
| Smsmode | `smsmode://API_KEY`, `smsmode://SENDER_NAME:API_KEY` (sender: `[a-zA-Z0-9]`, 11 characters at most) | sending returns the message ID; `read($msgId)` returns its delivery status; doc: `datasource-smsmode` |
| SMTP | `smtp://[user:pass@]host[:port]`, `smtp+tls://...` (STARTTLS, port 587), `smtps://...` (implicit TLS, port 465) | email transport (see the `temma-email` skill); doc: `datasource-smtp` |

## AI

| Connector | DSN | Notes |
|---|---|---|
| AI (unified LLM) | `ai://PROVIDER/MODEL#API_KEY`, `ai://[ENDPOINT_URL]/MODEL#API_KEY` (generic OpenAI-compatible endpoint, URL starting with `http://` or `https://`), `ai://[\Vendor\ProviderClass]/MODEL#API_KEY` (custom provider); the brackets are literal. Optional `TYPE@` prefix right after `ai://` (`chat` by default, `transcript` or `embedding`). `#API_KEY` is optional; MODEL is everything up to `#` and may contain `/` and `:`. E.g. `ai://openai/gpt-4o#sk-XXX`, `ai://transcript@ovh/whisper-large-v3` | see the `temma-ai` skill; doc: `datasource-ai` |

## Special

| Connector | DSN | Notes |
|---|---|---|
| Dummy | `dummy://` | discards writes, returns nothing; useful in tests |
| Environment | `env://VAR_NAME` | reads the real DSN from an environment variable |
| Custom class | `[\MyApp\MySource]anything://params` | any `\Temma\Base\Datasource` subclass |
