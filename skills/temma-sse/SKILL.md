---
name: temma-sse
description: Push real-time server-sent events (SSE) to browsers from a Temma PHP framework project. Use when implementing live updates, notifications, progress feedback or streaming to the client with EventSource and Temma event controllers.
license: MIT
---

# Server-sent events in Temma

SSE provide one-way, real-time communication from the server to the browser over a
long-lived HTTP connection (the browser reconnects automatically). Temma handles them
with **event controllers**, extending `\Temma\Web\EventController`.

Differences with regular controllers: they run as long as needed, execute **no view and
no template**, and every "template variable" assignment immediately **sends an event**
(key = channel name, value = JSON-serialized data).

## Server side

```php
class Message extends \Temma\Web\EventController {
	public function fetch() {
		$i = 1;
		while (true) {
			// send an event on the "demo" channel
			$this['demo'] = "Message n°$i";
			$i++;
			sleep(2);
		}
	}
}
```

Any PHP data can be sent (serialized to JSON):

```php
$this['update'] = [
	123 => ['id' => 123, 'name' => 'Neptune project'],
	456 => ['id' => 456, 'name' => 'Pluto project'],
];
```

Everything else works as in the `temma-controller` skill: root/proxy/default actions,
`__wakeup()`/`__sleep()`, plugins (post-plugins run once at the end of execution, not per
event).

## Client side

```html
<script>
const evtSource = new EventSource("/message/fetch");
evtSource.addEventListener("demo", function(event) {
	const str = JSON.parse(event.data);
	const newElement = document.createElement("li");
	newElement.textContent = str;
	document.getElementById("liste").appendChild(newElement);
});
</script>
```

## Lifecycle and helper methods

- Each send first checks that the client is still connected; if not, a
  `\Temma\Exceptions\FlowQuit` is thrown, which cleanly stops the processing (catch it
  only if you have cleanup to do).
- Each successful send extends PHP's `max_execution_time` (30 s if unset; untouched if 0),
  so the loop survives beyond the initial limit.
- `$this->_checkConnection()`: explicit connection check (throws `FlowQuit` when closed).
- `$this->_renewTimeLimit()`: connection check + execution time extension (useful in long
  computations between sends).
- `$this->_ping()`: sends a keep-alive event on the `ping` channel (`['time' => <ISO 8601>]`).

Channel bookkeeping: `isset($this['channel'])` tells whether a channel was used,
`$this['channel']` (read) returns the number of events sent on it, `unset($this['channel'])`
resets it.

## Operational notes

- One open connection per connected client: check the server's concurrent connection
  limits (and PHP-FPM worker count) before using SSE at scale.
- SSE is server→browser only. For deferred background processing, see the `temma-asynk`
  skill; a classic combo is a worker doing the heavy lifting and an SSE controller
  polling shared state (database, Redis) to stream progress to the user.

## Further reading

- https://www.temma.net/en/documentation/server_sent_events
