# Temma data source connectors and DSN formats

Documentation pages: `https://www.temma.net/en/documentation/datasource-<name>`.

## Databases and caches

| Connector | DSN | Notes |
|---|---|---|
| SQL (`Sql`) | `mysql://user:passwd@host[:port]/base`, `pgsql://...`, `sqlite:/path/to/file.sq3`, also `mysqli`, `mssql`, `oci`, `firebird`... | default DAO source; doc: `datasource-sql` |
| Redis | `redis://host[:port][/db]`, `redis-sock:///path/to/socket` | TTL via `$options` (seconds); doc: `datasource-redis` |
| Memcache | `memcache://host[:port]`, multiple servers separated by `;` | TTL via `$options`; doc: `datasource-memcache` |

## Files and object storage

| Connector | DSN | Notes |
|---|---|---|
| File | `file:///path/to/dir`, `file://0007/path/to/dir` (umask) | keys are relative file paths; doc: `datasource-file` |
| S3 | `s3://ACCESS_KEY:SECRET_KEY@REGION/BUCKET[:private]` | `$options` for ACL/mimetype; doc: `datasource-s3` |

## Queues and sockets

| Connector | DSN | Notes |
|---|---|---|
| Beanstalk | `beanstalk://host[:port]/tube_name` | doc: `datasource-beanstalk` |
| SQS | `sqs://ACCESS_KEY:SECRET_KEY@QUEUE_URL` | doc: `datasource-sqs` |
| ZeroMQ | `zmq://...` (connect), `zmq-bind://...` (bind) | doc: `datasource-zeromq` |
| Socket | `tcp://host:port`, `udp://`, `ssl://`, `tls://`, `unix:///path` | doc: `datasource-socket` |

## Messaging and notifications (write-only)

| Connector | DSN | Notes |
|---|---|---|
| Slack | `slack://hooks.slack.com/services/TXX/BYY/ZZZ` | `$slack[''] = 'message';` doc: `datasource-slack` |
| Discord | `discord://discord.com/api/webhooks/ID/TOKEN` | doc: `datasource-discord` |
| Google Chat | `googlechat://chat.googleapis.com/v1/spaces/XXX/messages?key=YYY&token=ZZZ` | doc: `datasource-googlechat` |
| Telegram | `telegram://API_TOKEN` (chat identifier given as the key when sending) | doc: `datasource-telegram` |
| Pushover | `pushover://APP_TOKEN` | doc: `datasource-pushover` |
| Smsmode | `smsmode://API_KEY`, `smsmode://SENDER_NAME:API_KEY` | doc: `datasource-smsmode` |
| SMTP | `smtp://[user:pass@]host[:port]`, `smtp+tls://...` (STARTTLS, port 587), `smtps://...` (implicit TLS, port 465) | email transport (see the `temma-email` skill); doc: `datasource-smtp` |

## AI

| Connector | DSN | Notes |
|---|---|---|
| AI (unified LLM) | `ai://provider/model#API_KEY` (e.g. `ai://openai/gpt-4o#sk-XXX`; see the `temma-ai` skill) | doc: `datasource-ai` |

## Special

| Connector | DSN | Notes |
|---|---|---|
| Dummy | `dummy://` | discards writes, returns nothing; useful in tests |
| Environment | `env://VAR_NAME` | reads the real DSN from an environment variable |
| Custom class | `[\MyApp\MySource]anything://params` | any `\Temma\Base\Datasource` subclass |
