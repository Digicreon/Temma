---
name: temma-ai
description: Query LLMs (OpenAI, Claude, Gemini, Mistral, OpenRouter, Ollama, OVHcloud, or any OpenAI-compatible API) from a Temma PHP framework project through the unified AI data source. Use when calling a language model, generating text or structured JSON, sending images/audio/PDF to a model, transcribing audio (speech-to-text), computing text embeddings, or configuring an ai:// DSN.
license: MIT
---

# LLMs in Temma (AI data source)

`\Temma\Datasources\Ai` gives a single interface over multiple AI providers: prompt and
response, system prompts, temperature, multi-turn history, attachments (images, audio,
video, PDF) and structured JSON output; plus audio transcription and text embeddings.

## Configuration

DSN syntax (the square brackets are literal characters written in the DSN, they never
mean "optional"):

```
ai://PROVIDER/MODEL#API_KEY                   built-in provider
ai://[ENDPOINT_URL]/MODEL#API_KEY             OpenAI-compatible service (URL starting with http:// or https://)
ai://[\Vendor\ProviderClass]/MODEL#API_KEY    custom provider class
```

- An optional `TYPE@` prefix can follow `ai://` in any form (see below).
- `MODEL` is everything up to `#`; it may contain `/` and `:`.
- `#API_KEY` is optional (Ollama, OVHcloud). The key must follow `#`: in
  `ai://openai/gpt-4o/KEY`, `gpt-4o/KEY` would be taken as the model name.

In `etc/temma.php`:

```php
'application' => [
	'dataSources' => [
		'ai' => 'ai://openai/gpt-4o#sk-proj-XXX',
	],
],
```

The DSN may be prefixed by the **type of API** (case-insensitive): `chat@` (default when omitted),
`transcript@` (speech-to-text) or `embedding@` (text embeddings), e.g.
`ai://transcript@openai/whisper-1#sk-proj-XXX`. The model is bound to the DSN, so use one
data source per type (e.g. `ai` for chat and `whisper` for transcription). A provider that
doesn't support the type throws an exception when the data source is created.

Built-in providers and DSN examples:

| Provider | Example | Types | Notes |
|---|---|---|---|
| `openai` | `ai://openai/gpt-4o#sk-proj-XXX` | chat, transcript, embedding | text, images, audio, JSON |
| `claude` | `ai://claude/claude-sonnet-4-20250514#sk-ant-XXX` | chat | text, images, PDF, JSON |
| `gemini` | `ai://gemini/gemini-2.5-flash#AIza-XXX` | chat | text, images, audio, video, PDF, JSON |
| `mistral` | `ai://mistral/mistral-small-latest#XXX` | chat, transcript, embedding | text, images, audio, PDF, JSON |
| `openrouter` | `ai://openrouter/openai/gpt-4o#sk-or-XXX` | chat | model written exactly as OpenRouter names it (`vendor/model`, optional `:variant`, e.g. `ai://openrouter/meta-llama/llama-3.3-70b-instruct:free#sk-or-XXX`) |
| `ollama` | `ai://ollama/llama3:70b` | chat | always `http://localhost:11434`, no API key (remote server: bracket syntax) |
| `ovh` | `ai://ovh/gpt-oss-120b#XXX` | chat, transcript, embedding | OVHcloud AI Endpoints; API key optional (anonymous usage is rate-limited) |

Transcription and embedding examples: `ai://transcript@ovh/whisper-large-v3#XXX`,
`ai://transcript@mistral/voxtral-mini-latest#XXX`, `ai://embedding@openai/text-embedding-3-small#sk-proj-XXX`,
`ai://embedding@mistral/mistral-embed#XXX`, `ai://embedding@ovh/bge-m3#XXX`.

Any **OpenAI-compatible service** works with the bracket syntax
`ai://[ENDPOINT_URL]/MODEL#API_KEY` (Groq, Together AI, Fireworks, DeepInfra, LiteLLM...),
also with a type prefix (`ai://transcript@[URL]/...`); the URL must match the type. It is also
how to reach a remote Ollama server. With this generic provider, JSON mode relies on the system
prompt only and `max_tokens` is sent as is:

```
ai://[https://api.groq.com/openai/v1/chat/completions]/llama-3.3-70b#gsk-XXX
ai://transcript@[https://api.groq.com/openai/v1/audio/transcriptions]/whisper-large-v3#gsk-XXX
ai://[http://192.168.1.20:11434/v1/chat/completions]/llama3:70b
```

A **custom provider class** can be given in brackets too (`ai://[\App\MyProvider]/model#key`,
optionally with a type prefix); it must implement the `\Temma\Datasources\Ai\Provider`
interface, like the classes in `\Temma\Datasources\Ai\Providers\*`:

- constructor receiving the API key, the endpoint URL (empty for a class in brackets) and the
  type as a `\Temma\Datasources\Ai\Type` enum; it throws if the type is not supported;
- `buildPayload($model, $prompt, $options)` returns `['url', 'headers', 'body']` (plus
  `'encoding' => 'multipart'` for multipart bodies). Options are normalized: attachments are
  `['data' => base64, 'mime' => ..., 'name' => file name or null]`, `output` is a MIME type;
- `parseResponse(array $data)` receives the decoded JSON whenever the body is a JSON object or
  array (whatever its content type), otherwise `['type' => content type, 'content' => raw body]`.
  It is also called for JSON error responses (HTTP >= 400): it should throw a
  `\Temma\Exceptions\Database` with the API message (if it doesn't, a generic HTTP error is
  thrown and its return value is ignored). For a non-JSON 2xx body, returning null makes the
  data source throw "invalid response"; returning a value (e.g. binary data) is how a custom
  provider supports binary responses.

Avoid committing API keys: use `env://` DSNs or platform configuration overloads.

## Usage

```php
// array-like: same as read() without default value (throws on error)
$response = $this->ai['What is the capital of France?'];

// read(): raw text; default value or fallback callback; options
$response = $this->ai->read('Explain photosynthesis', null, [
	'system'      => 'You are a biology teacher.',
	'temperature' => 0.3,
]);

// get(): JSON mode, response decoded into a PHP array
$data = $this->ai->get('List the 3 largest cities in France with their population');
$recipe = $this->ai->get('Create a recipe using: eggs, tomatoes', null, [
	'system' => 'You are a chef. Return JSON with keys: title, difficulty (1-5), steps (list).',
]);
```

The second parameter of `read()`/`get()` is a default value or a fallback callback, used on
error. It is returned as is (never JSON-decoded); a string is always a default value, never
called as a function.

`get()` details:

- JSON mode = instruction added to the system prompt for every provider, plus the native JSON
  mode of `mistral`, `gemini` and `ollama`;
- a surrounding Markdown code fence (```` ``` ```` or ```` ```json ````) is stripped before decoding;
- invalid JSON throws `\Temma\Exceptions\Database` ("AI API returned invalid JSON: ...") unless a
  default value/callback is given;
- on an embedding source, the list of floats is returned as is; on a transcript source, `get()`
  makes no sense (plain text: decoding fails, exception or default).

`mGet(array $prompts)` calls `get()` on each prompt without default value and returns
`[prompt => decoded response]`; the first error throws.

Transcription and embedding use `read()` too:

```php
// transcription (DSN 'ai://transcript@ovh/whisper-large-v3#XXX'): the prompt is an optional
// context hint (ignored by Mistral), the file is given as third parameter
$text = $this->whisper->read('', null, '/path/to/message.mp3');

// embedding (DSN 'ai://embedding@openai/text-embedding-3-small#sk-proj-XXX'): list of floats
$vector = $this->embedding->read('Text to embed');
```

## Errors and disabled source

Without default value, failures throw:

- `\Temma\Exceptions\Database` for HTTP >= 400: the provider's API error message if the body is
  JSON, otherwise "AI API HTTP error CODE: <first 500 chars of body>";
- `\Temma\Exceptions\Database` "AI API returned invalid response (content type '...')" for a 2xx
  body that is not JSON (e.g. HTML maintenance page). Bodies are decoded as JSON whatever their
  Content-Type; a JSON response with an unexpected structure returns null;
- `\Temma\Exceptions\Database` "Unable to JSON-encode the AI API request: Malformed UTF-8
  characters..." when the prompt, system prompt or messages contain invalid UTF-8 (the prompt is
  not altered);
- `\Temma\Exceptions\Database` "AI API cURL error: ..." on network error or timeout;
- `\Temma\Exceptions\IO` "Unable to read file '...'." when an attachment is a directory or an
  unreadable file, in any form (string, `['path' => ...]`, string `$options`).

With a default value or callback, it is returned instead (callback executed) for all these errors.

A disabled data source (`disable()`) behaves like an empty source and sends nothing: `read()`/
`get()` return the default value (or the callback's result), or null; `$ai['prompt']` returns
null; `mGet()` returns every prompt with a null value.

## Options (third parameter)

A string is the path to a file to send (shortcut for `['attachments' => [['path' => ...]]]`):
`$this->ai->read('Describe this image', null, '/path/to/photo.jpg')`. Otherwise, an
associative array:

- `system` (string): system prompt;
- `messages` (array): conversation history, each entry `['user' => '...']` or
  `['ai' => '...']` (attachments allowed inside entries);
- `temperature` (float, 0-2);
- `max_tokens` (int): sent as `max_completion_tokens` by the `openai` provider (o-series,
  gpt-5); other OpenAI-compatible services (`ovh`, `mistral`, bracket syntax) receive
  `max_tokens`; `claude` uses 4096 by default;
- `attachments` (array): each element is a file path, a binary string, or an array
  `['path' => ...]` / `['data' => ..., 'mime' => ...]`. MIME auto-detected when omitted
  (provide it when you already have binary content). Unsupported attachment types for
  the provider/model are ignored;
- `output` (string): output format alias (`'json'`, `'csv'`, `'audio'`, `'image'`,
  `'pdf'`, `'video'`, `'html'`, `'xml'`, `'wav'`) or a MIME type. Only `'json'`
  (`'application/json'`) changes the request of the built-in providers (JSON mode); other
  values are only passed to custom providers (`$options['output']`). With `read()` +
  `output: 'json'`, the response is a raw JSON string (not decoded nor fence-stripped, unlike `get()`).
- `timeout` (int): maximum duration of the request, in seconds (600 by default). Use a
  short value with a default value in web pages, so a slow service doesn't block the page.

Multi-turn example:

```php
$response = $this->ai->read('And the capital of Italy?', null, [
	'system'   => 'You are a geography assistant.',
	'messages' => [
		['user' => 'What is the capital of France?'],
		['ai' => 'The capital of France is Paris.'],
	],
]);
```

## Notes

- The `\Temma\Datasources\OpenAi` data source (`openai://chat/MODEL/KEY`) is the older,
  deprecated connector; use `ai://` for new code. Its `get()` returns the raw text like
  `read()` (no JSON decoding), `mGet()` returns texts; errors (API error, HTTP error, non-JSON
  response, invalid UTF-8, network/60 s timeout) throw `\Temma\Exceptions\Database` unless a
  default value or callback is given; disabled behaviour is the same as `ai://`.
- LLM calls are slow: for user-facing flows consider deferring them with the
  `temma-asynk` skill, or streaming results with the `temma-sse` skill.

## Further reading

- https://www.temma.net/en/documentation/ai
- https://www.temma.net/en/documentation/datasource-ai
