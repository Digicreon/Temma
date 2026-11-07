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

DSN format: `ai://PROVIDER/MODEL#API_KEY` (the model may contain `/` and `:`; the API
key is optional for local providers like Ollama). In `etc/temma.php`:

```php
'application' => [
	'dataSources' => [
		'ai' => 'ai://openai/gpt-4o#sk-proj-XXX',
	],
],
```

The DSN may be prefixed by the **type of API**: `chat@` (default when omitted),
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
| `openrouter` | `ai://openrouter/openai/gpt-4o#sk-or-XXX` | chat | model name contains `/` |
| `ollama` | `ai://ollama/llama3:70b` | chat | local, no API key |
| `ovh` | `ai://ovh/gpt-oss-120b#XXX` | chat, transcript, embedding | OVHcloud AI Endpoints; API key optional (anonymous usage is rate-limited) |

Transcription and embedding examples: `ai://transcript@ovh/whisper-large-v3#XXX`,
`ai://transcript@mistral/voxtral-mini-latest#XXX`, `ai://embedding@openai/text-embedding-3-small#sk-proj-XXX`,
`ai://embedding@mistral/mistral-embed#XXX`, `ai://embedding@ovh/bge-m3#XXX`.

Any **OpenAI-compatible service** works with the bracket syntax
`ai://[TYPE@][ENDPOINT_URL]/MODEL#API_KEY` (Groq, Together AI, Fireworks, DeepInfra, LiteLLM...);
the URL must match the type:

```
ai://[https://api.groq.com/openai/v1/chat/completions]/llama-3.3-70b#gsk-XXX
ai://transcript@[https://api.groq.com/openai/v1/audio/transcriptions]/whisper-large-v3#gsk-XXX
```

A **custom provider class** can be given in brackets too (`ai://[\App\MyProvider]/model#key`,
optionally with a type prefix); it must implement the `\Temma\Datasources\Ai\Provider`
interface (constructor receiving the API key, the endpoint URL and the type as a
`\Temma\Datasources\Ai\Type` enum; `buildPayload()`; `parseResponse()`), like the classes in
`\Temma\Datasources\Ai\Providers\*`. Avoid committing API keys: use `env://` DSNs or platform
configuration overloads.

## Usage

```php
// array-like: prompt in, raw text out
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

The second parameter of `read()`/`get()` is a default value (scalar) or a fallback
callback, used on error.

Transcription and embedding use `read()` too:

```php
// transcription (DSN 'ai://transcript@ovh/whisper-large-v3#XXX'): the prompt is an optional
// context hint (ignored by Mistral), the file is given as third parameter
$text = $this->whisper->read('', null, '/path/to/message.mp3');

// embedding (DSN 'ai://embedding@openai/text-embedding-3-small#sk-proj-XXX'): list of floats
$vector = $this->embedding->read('Text to embed');
```

## Options (third parameter)

A string is the path to a file to send (shortcut for `['attachments' => [['path' => ...]]]`):
`$this->ai->read('Describe this image', null, '/path/to/photo.jpg')`. Otherwise, an
associative array:

- `system` (string): system prompt;
- `messages` (array): conversation history, each entry `['user' => '...']` or
  `['ai' => '...']` (attachments allowed inside entries);
- `temperature` (float, 0-2);
- `max_tokens` (int);
- `attachments` (array): each element is a file path, a binary string, or an array
  `['path' => ...]` / `['data' => ..., 'mime' => ...]`. MIME auto-detected when omitted
  (provide it when you already have binary content). Unsupported attachment types for
  the provider/model are ignored;
- `output` (string): output format alias (`'json'`, `'csv'`, `'audio'`, `'image'`,
  `'pdf'`, `'video'`, `'html'`, `'xml'`, `'wav'`) or a MIME type. With `read()` +
  `output: 'json'`, the response is a raw JSON string (not decoded, unlike `get()`).
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

- The `\Temma\Datasources\OpenAi` data source (`openai://`) is the older, deprecated
  connector; use `ai://` for new code.
- LLM calls are slow: for user-facing flows consider deferring them with the
  `temma-asynk` skill, or streaming results with the `temma-sse` skill.

## Further reading

- https://www.temma.net/en/documentation/ai
- https://www.temma.net/en/documentation/datasource-ai
