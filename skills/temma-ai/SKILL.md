---
name: temma-ai
description: Query LLMs (OpenAI, Claude, Gemini, Mistral, OpenRouter, Ollama, or any OpenAI-compatible API) from a Temma PHP framework project through the unified AI data source. Use when calling a language model, generating text or structured JSON, sending images/audio/PDF to a model, or configuring an ai:// DSN.
license: MIT
---

# LLMs in Temma (AI data source)

`\Temma\Datasources\Ai` gives a single interface over multiple LLM providers: prompt and
response, system prompts, temperature, multi-turn history, attachments (images, audio,
video, PDF) and structured JSON output.

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

Built-in providers and DSN examples:

| Provider | Example | Notes |
|---|---|---|
| `openai` | `ai://openai/gpt-4o#sk-proj-XXX` | text, images, audio, JSON |
| `claude` | `ai://claude/claude-sonnet-4-20250514#sk-ant-XXX` | text, images, PDF, JSON |
| `gemini` | `ai://gemini/gemini-2.5-flash#AIza-XXX` | text, images, audio, video, PDF, JSON |
| `mistral` | `ai://mistral/mistral-small-latest#XXX` | text, images, audio, PDF, JSON |
| `openrouter` | `ai://openrouter/openai/gpt-4o#sk-or-XXX` | model name contains `/` |
| `ollama` | `ai://ollama/llama3:70b` | local, no API key |

Any **OpenAI-compatible service** works with the bracket syntax
`ai://[ENDPOINT_URL]/MODEL#API_KEY` (Groq, Together AI, Fireworks, DeepInfra, LiteLLM...):

```
ai://[https://api.groq.com/openai/v1/chat/completions]/llama-3.3-70b#gsk-XXX
```

A **custom provider class** can be given in brackets too (`ai://[\App\MyProvider]/model#key`);
it must implement `buildPayload()` and `parseResponse()` like the classes in
`\Temma\Datasources\Ai\*`. Avoid committing API keys: use `env://` DSNs or platform
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

## Options (third parameter)

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
