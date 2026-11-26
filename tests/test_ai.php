#!/usr/bin/php
<?php

/**
 * Validation script for the Ai data source.
 * Starts a local HTTP server (PHP built-in server) that simulates AI services, then checks:
 * - the DSN parsing (type prefix, built-in providers, OpenAI-compatible URL, custom provider class);
 * - the requests built for each type of API (chat, transcript, embedding);
 * - the management of the responses (JSON whatever the content type, raw content, non-JSON responses, HTTP errors);
 * - the provider-specific behaviours (OpenRouter model names, Ollama JSON mode, OpenAI max_completion_tokens,
 *   Mistral error messages);
 * - the invalid prompts (invalid UTF-8) and attachments (directory);
 * - the path to a file given as options;
 * - the default values and callbacks of read() and get() (never JSON-decoded, strings never called as functions);
 * - the disabled data source (behaves like an empty one: default value or callback);
 * - the API keys masked in the logs and exception messages;
 * - the deprecated OpenAi data source (HTTP status and JSON validity checks).
 */

// safety net: no request can reach a real AI service (only the local fake server is reachable)
putenv('https_proxy=http://127.0.0.1:1');
putenv('http_proxy=http://127.0.0.1:1');
putenv('HTTPS_PROXY=http://127.0.0.1:1');
putenv('no_proxy=127.0.0.1,localhost');

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Datasources\Ai as TµAi;
use \Temma\Datasources\Ai\Type as TµAiType;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
// the logs are captured, to check that they don't contain secrets
$logs = [];
\Temma\Base\Log::setThreshold('DEBUG');
\Temma\Base\Log::addCallback(function($requestId, $message) use (&$logs) {
	$logs[] = $message;
});

/* ********** FAKE AI SERVER ********** */
$serverPath = sys_get_temp_dir() . '/temma-ai-test-' . getmypid();
mkdir($serverPath, 0755, true);
// the server returns the received request in the response, depending on the called path
file_put_contents("$serverPath/router.php", <<<'EOT'
	<?php
	$received = [
		'auth'  => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
		'type'  => $_SERVER['CONTENT_TYPE'] ?? null,
		'json'  => json_decode(file_get_contents('php://input'), true),
		'post'  => $_POST,
		'files' => array_map(fn($f) => ['name' => $f['name'], 'type' => $f['type'], 'md5' => md5_file($f['tmp_name'])], $_FILES),
	];
	if ($_SERVER['REQUEST_URI'] === '/slow')
		sleep(3);
	[$code, $type, $body] = match ($_SERVER['REQUEST_URI']) {
		'/chat'                 => [200, 'application/json; charset=utf-8', json_encode(['choices' => [['message' => ['content' => json_encode($received)]]]])],
		'/transcript'           => [200, 'application/json', json_encode(['text' => json_encode($received)])],
		'/embedding'            => [200, 'application/json', json_encode(['data' => [['embedding' => [0.5, -0.25]]]])],
		'/embedding-as-text'    => [200, 'text/plain', json_encode(['data' => [['embedding' => [0.5, -0.25]]]])],
		'/binary'               => [200, 'audio/mpeg', "\xFF\xFB\x90\x00binary"],
		'/slow'                 => [200, 'application/json', json_encode(['choices' => [['message' => ['content' => 'late']]]])],
		'/text-error'           => [422, 'text/plain; charset=utf-8', 'Failed to deserialize the JSON body'],
		'/json-error'           => [401, 'application/json', json_encode(['error' => ['message' => 'Invalid API key']])],
		'/unknown-error'        => [401, 'application/json', json_encode(['detail' => 'Unauthorized'])],
		'/text'                 => [200, 'application/json', json_encode(['choices' => [['message' => ['content' => 'plain text answer']]]])],
		'/html'                 => [200, 'text/html; charset=utf-8', '<html>Maintenance</html>'],
		'/json-as-text'         => [200, 'text/plain', json_encode(['choices' => [['message' => ['content' => '{"city":"Paris"}']]]])],
		'/json-no-type'         => [200, '', json_encode(['choices' => [['message' => ['content' => '{"city":"Rome"}']]]])],
		'/text-json-error'      => [401, 'text/plain', json_encode(['error' => ['message' => 'Invalid API key']])],
		'/mistral-unauthorized' => [401, 'application/json', json_encode(['message' => 'Unauthorized', 'request_id' => 'abc'])],
		'/mistral-error'        => [400, 'application/json', json_encode(['object' => 'error', 'message' => 'Invalid model: xyz', 'type' => 'invalid_model'])],
		'/mistral-validation'   => [422, 'application/json', json_encode(['object' => 'error', 'message' => ['detail' => [['msg' => 'Field required']]]])],
		'/bad-gateway'          => [502, 'application/json', '<html>Bad gateway</html>'],
		'/openai-ok'            => [200, 'application/json', json_encode(['choices' => [['message' => ['content' => 'Paris']]]])],
		'/openai-error'         => [500, 'text/html', '<html>Internal error</html>'],
		'/openai-text'          => [200, 'text/plain', 'not JSON'],
		default                 => [404, 'text/plain', 'Not found'],
	};
	http_response_code($code);
	if ($type)
		header("Content-Type: $type");
	else
		ini_set('default_mimetype', '');
	print($body);
	EOT);
$port = rand(20000, 60000);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$serverPath/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function() use ($server, $serverPath) {
	proc_terminate($server);
	exec('rm -rf ' . escapeshellarg($serverPath));
});
// wait for the server
foreach (range(1, 50) as $i) {
	usleep(100000);
	if (($socket = @fsockopen('127.0.0.1', $port))) {
		fclose($socket);
		break;
	}
}
define('BASE_URL', "http://127.0.0.1:$port");
// audio file (WAV, 0.1 second of silence)
$wav = 'RIFF' . pack('V', 36 + 1600) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 1600) . str_repeat("\0", 1600);
$wavPath = "$serverPath/sound.wav";
file_put_contents($wavPath, $wav);

/* ********** CUSTOM PROVIDER ********** */
/** Custom provider which returns the response as received. */
class TestProvider implements \Temma\Datasources\Ai\Provider {
	/** Type of API. */
	public TµAiType $type;

	/**
	 * Constructor.
	 * @param	string		$apiKey		(optional) API key.
	 * @param	string		$endpointUrl	(optional) Not used.
	 * @param	TµAiType	$type		(optional) Type of API.
	 */
	public function __construct(string $apiKey='', string $endpointUrl='', TµAiType $type=TµAiType::Chat) {
		$this->type = $type;
	}
	/**
	 * Build the API request.
	 * @param	string	$model		Not used.
	 * @param	string	$prompt		Not used.
	 * @param	array	$options	Not used.
	 * @return	array	The request.
	 */
	public function buildPayload(string $model, string $prompt, array $options) : array {
		return (['url' => BASE_URL . '/binary', 'headers' => [], 'body' => []]);
	}
	/**
	 * Parse the API response.
	 * @param	array	$data	Response.
	 * @return	mixed	The response, as given.
	 */
	public function parseResponse(array $data) : mixed {
		return ($data);
	}
}

/** OpenAi data source which uses the fake server. */
class TestOpenAi extends \Temma\Datasources\OpenAi {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/openai-ok';
}
/** OpenAi data source which receives an HTTP error. */
class TestOpenAiError extends \Temma\Datasources\OpenAi {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/openai-error';
}
/** OpenAi data source which receives a non-JSON response. */
class TestOpenAiText extends \Temma\Datasources\OpenAi {
	/** URL of the API. */
	const string API_URL = BASE_URL . '/openai-text';
}

/* ********** TEST MICRO-FRAMEWORK ********** */
$count = 0;
$failed = 0;
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// returns the provider object of an Ai data source
function provider(TµAi $ai) : \Temma\Datasources\Ai\Provider {
	return ((new \ReflectionProperty(TµAi::class, '_provider'))->getValue($ai));
}
// returns the request built for a DSN
function request(string $dsn, string $prompt='Hello', array $options=[]) : array {
	return (provider(TµAi::factory($dsn))->buildPayload('model', $prompt, $options));
}
// returns the exception thrown by a function, or null
function exception(callable $function) : ?\Throwable {
	try {
		$function();
	} catch (\Throwable $e) {
		return ($e);
	}
	return (null);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("DSN parsing\n"));
check("no prefix: chat type",
      request('ai://ovh/model')['url'] === \Temma\Datasources\Ai\Providers\Ovh::API_URLS['chat']);
check("'chat@' prefix",
      request('ai://chat@ovh/model')['url'] === \Temma\Datasources\Ai\Providers\Ovh::API_URLS['chat']);
check("'transcript@' prefix (case-insensitive)",
      request('ai://Transcript@ovh/model', '', ['attachments' => [['data' => base64_encode($wav), 'mime' => 'audio/x-wav']]])['url'] === \Temma\Datasources\Ai\Providers\Ovh::API_URLS['transcript']);
check("'embedding@' prefix",
      request('ai://embedding@ovh/model')['url'] === \Temma\Datasources\Ai\Providers\Ovh::API_URLS['embedding']);
foreach (['openai', 'mistral', 'ovh'] as $name) {
	check("'$name' supports the three types",
	      !exception(fn() => [TµAi::factory("ai://chat@$name/model"), TµAi::factory("ai://transcript@$name/model"), TµAi::factory("ai://embedding@$name/model")]));
}
foreach (['claude', 'gemini', 'openrouter', 'ollama'] as $name) {
	$e = exception(fn() => TµAi::factory("ai://transcript@$name/model"));
	check("'$name' doesn't support the transcript type",
	      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), "doesn't support the 'transcript' type"));
}
$e = exception(fn() => TµAi::factory('ai://speech@openai/model'));
check("unknown type: exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), "Unknown AI type 'speech'"));
$e = exception(fn() => TµAi::factory('ai://[\ArrayObject]/model'));
check("custom class without the Provider interface: exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), "doesn't implement"));
check("custom class receives the type",
      provider(TµAi::factory('ai://embedding@[\TestProvider]/model'))->type === TµAiType::Embedding);
check("custom class without prefix receives the chat type",
      provider(TµAi::factory('ai://[\TestProvider]/model'))->type === TµAiType::Chat);
$provider = provider(TµAi::factory('ai://transcript@[https://example.com/v1/audio/transcriptions]/model'));
check("OpenAI-compatible URL with type",
      $provider instanceof \Temma\Datasources\Ai\Providers\OpenAiCompatible &&
      $provider->buildPayload('model', '', ['attachments' => [['data' => base64_encode($wav), 'mime' => 'audio/x-wav']]])['url'] === 'https://example.com/v1/audio/transcriptions');
check("Datasource::factory() manages the type prefix",
      \Temma\Base\Datasource::factory('ai://transcript@ovh/model') instanceof TµAi);

print("\n" . TµAnsi::bold("Built requests\n"));
check("no Authorization header without API key",
      request('ai://ovh/model')['headers'] === []);
check("Authorization header with API key",
      request('ai://ovh/model#KEY')['headers'] === ['Authorization: Bearer KEY']);
check("embedding body",
      request('ai://embedding@openai/model', 'Text to embed')['body'] === ['model' => 'model', 'input' => 'Text to embed']);
$req = request('ai://transcript@openai/model', 'Context', ['attachments' => [['data' => base64_encode($wav), 'mime' => 'audio/x-wav']]]);
check("transcript: multipart encoding, file and prompt",
      ($req['encoding'] ?? null) === 'multipart' && $req['body']['file'] instanceof \CURLStringFile &&
      $req['body']['file']->postname === 'audio.wav' && $req['body']['prompt'] === 'Context');
$req = request('ai://transcript@mistral/model', 'Context', ['attachments' => [['data' => base64_encode($wav), 'mime' => 'audio/x-wav']]]);
check("Mistral transcript: no prompt field",
      !isset($req['body']['prompt']) && isset($req['body']['file']));
check("Mistral chat: JSON response format",
      (request('ai://mistral/model', 'Hello', ['output' => 'application/json'])['body']['response_format'] ?? null) === ['type' => 'json_object']);
$e = exception(fn() => request('ai://transcript@ovh/model'));
check("transcript without file: exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'No audio file'));
$ai = TµAi::factory('ai://openrouter/meta-llama/llama-3.3-70b-instruct:free#KEY');
$model = (new \ReflectionProperty(TµAi::class, '_model'))->getValue($ai);
check("OpenRouter: model name with variant suffix sent as is",
      $model === 'meta-llama/llama-3.3-70b-instruct:free' &&
      provider($ai)->buildPayload($model, 'Hello', [])['body']['model'] === 'meta-llama/llama-3.3-70b-instruct:free');
$body = request('ai://ollama/model', 'Hello', ['output' => 'application/json'])['body'];
check("Ollama chat: JSON mode sent as response_format (OpenAI-compatible endpoint)",
      ($body['response_format'] ?? null) === ['type' => 'json_object'] && !isset($body['format']));
$body = request('ai://openai/model', 'Hello', ['max_tokens' => 100])['body'];
check("OpenAI chat: max_tokens sent as max_completion_tokens",
      ($body['max_completion_tokens'] ?? null) === 100 && !isset($body['max_tokens']));
check("other OpenAI-compatible services: max_tokens kept",
      (request('ai://ovh/model', 'Hello', ['max_tokens' => 100])['body']['max_tokens'] ?? null) === 100 &&
      (request('ai://[https://example.com/v1/chat/completions]/model', 'Hello', ['max_tokens' => 100])['body']['max_tokens'] ?? null) === 100);

print("\n" . TµAnsi::bold("Requests and responses\n"));
$received = json_decode(TµAi::factory('ai://[' . BASE_URL . '/chat]/chat-model#KEY')->read('Hello'), true);
check("chat: JSON request",
      ($received['type'] ?? null) === 'application/json' && ($received['auth'] ?? null) === 'Bearer KEY' &&
      ($received['json']['model'] ?? null) === 'chat-model' && ($received['json']['messages'][0]['content'] ?? null) === 'Hello');
$received = json_decode(TµAi::factory('ai://[' . BASE_URL . '/chat]/chat-model')->read('Listen', null, $wavPath), true);
check("chat: path to a file given as options",
      ($received['json']['messages'][0]['content'][1]['type'] ?? null) === 'input_audio' &&
      ($received['json']['messages'][0]['content'][1]['input_audio']['format'] ?? null) === 'wav');
$ai = TµAi::factory('ai://transcript@[' . BASE_URL . '/transcript]/whisper#KEY');
$received = json_decode($ai->read('Context', null, $wavPath), true);
check("transcript: multipart request",
      str_starts_with($received['type'] ?? '', 'multipart/form-data') && ($received['auth'] ?? null) === 'Bearer KEY' &&
      ($received['post']['model'] ?? null) === 'whisper' && ($received['post']['prompt'] ?? null) === 'Context');
check("transcript: file sent as is, with its name",
      ($received['files']['file']['name'] ?? null) === 'sound.wav' && ($received['files']['file']['md5'] ?? null) === md5($wav));
$received = json_decode($ai->read('', null, ['attachments' => [['data' => $wav, 'mime' => 'audio/x-wav']]]), true);
check("transcript: binary content sent with a name built from its MIME type",
      ($received['files']['file']['name'] ?? null) === 'audio.wav' && ($received['files']['file']['md5'] ?? null) === md5($wav));
$received = json_decode($ai->read('', null, ['attachments' => [$wavPath], 'temperature' => 0.2]), true);
check("transcript: empty prompt not sent, temperature sent",
      !isset($received['post']['prompt']) && ($received['post']['temperature'] ?? null) === '0.2');
check("embedding: list of floats",
      TµAi::factory('ai://embedding@[' . BASE_URL . '/embedding]/bge')->read('Text') === [0.5, -0.25]);
check("raw response: content type and content given to the provider",
      TµAi::factory('ai://[\TestProvider]/model')->read('Hello') === ['type' => 'audio/mpeg', 'content' => "\xFF\xFB\x90\x00binary"]);
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/text-error]/model')->read('Hello'));
check("HTTP error with text response: exception with the response",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), '422') && str_contains($e->getMessage(), 'Failed to deserialize'));
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/json-error]/model')->read('Hello'));
check("HTTP error with JSON response: provider error message",
      $e instanceof \Temma\Exceptions\Database && $e->getMessage() === 'OpenAI-compatible API error: Invalid API key');
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/unknown-error]/model')->read('Hello'));
check("HTTP error not reported by the provider: exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'AI API HTTP error 401'));
check("HTTP error: default value",
      TµAi::factory('ai://[' . BASE_URL . '/text-error]/model')->read('Hello', 'fallback') === 'fallback');
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->read('Hello', null, "$serverPath/missing.wav"));
check("missing file: IO exception",
      $e instanceof \Temma\Exceptions\IO);
check("missing file: default value",
      TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->read('Hello', 'fallback', "$serverPath/missing.wav") === 'fallback');
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->read('Hello', null, ['attachments' => [$serverPath]]));
check("directory given as a string attachment: IO exception",
      $e instanceof \Temma\Exceptions\IO && str_contains($e->getMessage(), 'Unable to read file'));
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->read('Hello', null, $serverPath));
check("directory given as options: IO exception",
      $e instanceof \Temma\Exceptions\IO);
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->read("Invalid \xC3\x28 UTF-8"));
check("invalid UTF-8 in the prompt: exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'JSON-encode'));
check("invalid UTF-8 in the prompt: default value",
      TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->get("Invalid \xC3\x28 UTF-8", 'fallback') === 'fallback');
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/text-json-error]/model')->read('Hello'));
check("HTTP error with JSON response sent as text/plain: provider error message",
      $e instanceof \Temma\Exceptions\Database && $e->getMessage() === 'OpenAI-compatible API error: Invalid API key');
foreach (['/mistral-unauthorized' => 'Mistral API error: Unauthorized', '/mistral-error' => 'Mistral API error: Invalid model: xyz'] as $path => $message) {
	$e = exception(fn() => (new TµAi('\Temma\Datasources\Ai\Providers\Mistral', 'model', 'KEY', BASE_URL . $path))->read('Hello'));
	check("Mistral error without 'error' key ($path): API message",
	      $e instanceof \Temma\Exceptions\Database && $e->getMessage() === $message);
}
$e = exception(fn() => (new TµAi('\Temma\Datasources\Ai\Providers\Mistral', 'model', 'KEY', BASE_URL . '/mistral-validation'))->read('Hello'));
check("Mistral error with an object message: message JSON-encoded",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'Mistral API error: {"detail"') &&
      str_contains($e->getMessage(), 'Field required'));
check("Mistral successful response: parsed as usual",
      (new TµAi('\Temma\Datasources\Ai\Providers\Mistral', 'model', 'KEY', BASE_URL . '/text'))->read('Hello') === 'plain text answer');

print("\n" . TµAnsi::bold("Non-JSON responses and content types\n"));
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/html]/model')->read('Hello'));
check("HTML page with status 200: 'invalid response' exception",
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'invalid response') &&
      str_contains($e->getMessage(), 'text/html'));
check("HTML page with status 200: default value (read() and get())",
      TµAi::factory('ai://[' . BASE_URL . '/html]/model')->read('Hello', 'fallback') === 'fallback' &&
      TµAi::factory('ai://[' . BASE_URL . '/html]/model')->get('Hello', 'fallback') === 'fallback');
check("HTML page with status 200: callback",
      TµAi::factory('ai://[' . BASE_URL . '/html]/model')->get('Hello', fn() => ['CB']) === ['CB']);
check("JSON sent as text/plain: read() returns the text",
      TµAi::factory('ai://[' . BASE_URL . '/json-as-text]/model')->read('Hello') === '{"city":"Paris"}');
check("JSON sent as text/plain: get() returns the decoded data",
      TµAi::factory('ai://[' . BASE_URL . '/json-as-text]/model')->get('Hello') === ['city' => 'Paris']);
check("JSON sent without content type: get() returns the decoded data",
      TµAi::factory('ai://[' . BASE_URL . '/json-no-type]/model')->get('Hello') === ['city' => 'Rome']);
check("embedding sent as text/plain: list of floats",
      TµAi::factory('ai://embedding@[' . BASE_URL . '/embedding-as-text]/model')->read('Text') === [0.5, -0.25]);

print("\n" . TµAnsi::bold("Default values and callbacks\n"));
$failing = TµAi::factory('ai://[' . BASE_URL . '/text-error]/model');
check('get(): string default value returned as is',
      ($failing->get('Hello', 'fallback') === 'fallback') && ($failing->get('Hello', '0') === '0'));
check('get(): callback value returned as is (not JSON-decoded)',
      $failing->get('Hello', fn() => '[1]') === '[1]');
check('read() and get(): string default value never called as a function',
      ($failing->read('Hello', 'date') === 'date') && ($failing->get('Hello', 'date') === 'date'));
check('get(): JSON response decoded',
      is_array(TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->get('Hello')));
check('get(): non-JSON response, default value',
      TµAi::factory('ai://[' . BASE_URL . '/text]/model')->get('Hello', 'fallback') === 'fallback');
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/text]/model')->get('Hello'));
check('get(): non-JSON response without default value: exception',
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'invalid JSON'));
$result = TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->mGet(['Hello']);
check('mGet(): responses decoded like get()',
      is_array($result['Hello'] ?? null));
$e = exception(fn() => TµAi::factory('ai://[' . BASE_URL . '/bad-gateway]/model')->read('Hello'));
check('HTTP error with an invalid JSON response: exception with the HTTP code',
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'HTTP error 502'));
$calls = 0;
$callback = function() use (&$calls) {
	$calls++;
	return ('CB');
};
$disabled = TµAi::factory('ai://[' . BASE_URL . '/chat]/model')->disable();
check('disabled data source: read() and get() return the default value or call the callback',
      ($disabled->read('Hello', $callback) === 'CB') && ($disabled->get('Hello', $callback) === 'CB') && $calls === 2 &&
      ($disabled->read('Hello', 'fallback') === 'fallback') && ($disabled->get('Hello', 'date') === 'date'));
check('disabled data source: null without default value',
      ($disabled->read('Hello') === null) && ($disabled->get('Hello') === null) && ($disabled['Hello'] === null));
check('disabled data source: mGet() returns null for each prompt',
      $disabled->mGet(['Hello', 'World']) === ['Hello' => null, 'World' => null]);

print("\n" . TµAnsi::bold("Secrets\n"));
$logs = [];
TµAi::factory('ai://transcript@openai/whisper-1#SECRET-KEY-1');
TµAi::factory('ai://[https://user:SECRET-PASSWORD@example.com/v1/chat/completions]/model#SECRET-KEY-2');
check('API keys and URL credentials not written in the logs',
      (count($logs) === 2) && !array_filter($logs, fn($log) => str_contains($log, 'SECRET')));
$logs = [];
$e = exception(fn() => TµAi::factory('ai://openai#SECRET-KEY-3'));
check('API key not written in the exception message and the logs of an invalid DSN',
      $e instanceof \Temma\Exceptions\Database && !str_contains($e->getMessage(), 'SECRET') &&
      !array_filter($logs, fn($log) => str_contains($log, 'SECRET')));
$logs = [];
TµAi::factory('ai://openai/gpt-4o/SECRET-KEY-6');
TµAi::factory('ai://[https://example.com/v1/chat/completions?key=SECRET-KEY-7&x=1]/model#SECRET-KEY-8');
TµAi::factory('ai://embedding@[https://u:p?SECRET-PASSWORD@example.com/v1?key=SECRET-KEY-9]/model');
check('old format (API key as last path segment) and query string between brackets: not written in the logs',
      (count($logs) === 3) && !array_filter($logs, fn($log) => str_contains($log, 'SECRET')));
// masked DSNs (the useful parts are kept)
$maskAiDsn = fn(string $dsn) => (new \ReflectionMethod(TµAi::class, '_maskAiDsn'))->invoke(null, $dsn);
$masks = [
	'ai://openai/gpt-4o#sk-proj-XXX'                    => 'ai://openai/gpt-4o#***',
	'ai://openai/gpt-4o/sk-XXX'                         => 'ai://openai/gpt-4o/***',
	'ai://transcript@openai/whisper-1/sk-XXX'           => 'ai://transcript@openai/whisper-1/***',
	'ai://openrouter/openai/gpt-4o#sk-or-XXX'           => 'ai://openrouter/openai/gpt-4o#***',
	'ai://ollama/llama3:70b'                            => 'ai://ollama/llama3:70b',
	'ai://[https://h/v1/chat]/model'                    => 'ai://[https://h/v1/chat]/model',
	'ai://[https://h/v1/chat]/model/XXX'                => 'ai://[https://h/v1/chat]/model/***',
	'ai://[https://h/v1?key=XXX]/model#XXX'             => 'ai://[https://h/v1?***]/model#***',
	'ai://chat@[https://u:XXX@h/v1?key=XXX&a=b]/model'  => 'ai://chat@[https://***@h/v1?***]/model',
	'ai://[\App\MyProvider]/model#XXX'                  => 'ai://[\App\MyProvider]/model#***',
];
$wrong = array_filter($masks, fn($expected, $dsn) => ($maskAiDsn($dsn) !== $expected), ARRAY_FILTER_USE_BOTH);
check('masked DSNs: API key, credentials and query string masked, provider and model kept',
      !$wrong);
foreach ($wrong as $dsn => $expected)
	print('   ' . TµAnsi::faint("$dsn => " . $maskAiDsn($dsn) . " (expected $expected)") . "\n");

print("\n" . TµAnsi::bold("OpenAi data source (deprecated)\n"));
check('response text',
      (new TestOpenAi('KEY', 'model'))->read('Hello') === 'Paris');
check('mGet(): response texts (not JSON-decoded)',
      (new TestOpenAi('KEY', 'model'))->mGet(['Hello']) === ['Hello' => 'Paris']);
$e = exception(fn() => (new TestOpenAiError('KEY', 'model'))->read('Hello'));
check('HTTP error: exception',
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'HTTP error 500'));
check('HTTP error: default value, string never called as a function',
      (new TestOpenAiError('KEY', 'model'))->read('Hello', 'date') === 'date');
check('HTTP error: callback',
      (new TestOpenAiError('KEY', 'model'))->read('Hello', fn() => 'CB') === 'CB');
$e = exception(fn() => (new TestOpenAiText('KEY', 'model'))->read('Hello'));
check('non-JSON response: exception',
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'invalid response'));
$calls = 0;
$disabled = (new TestOpenAi('KEY', 'model'))->disable();
check('disabled data source: read() and get() return the default value or call the callback',
      ($disabled->read('Hello', $callback) === 'CB') && ($disabled->get('Hello', 'fallback') === 'fallback') && $calls === 1 &&
      ($disabled->read('Hello') === null));
check('disabled data source: mGet() returns null for each prompt',
      $disabled->mGet(['Hello', 'World']) === ['Hello' => null, 'World' => null]);
$e = exception(fn() => (new TestOpenAi('KEY', 'model'))->read("Invalid \xC3\x28 UTF-8"));
check('invalid UTF-8 in the prompt: exception',
      $e instanceof \Temma\Exceptions\Database && str_contains($e->getMessage(), 'JSON-encode'));
$logs = [];
\Temma\Datasources\OpenAi::factory('openai://chat/model/SECRET-KEY-4');
$e = exception(fn() => \Temma\Datasources\OpenAi::factory('openai://model/SECRET-KEY-5'));
check('API key not written in the logs and the exception messages',
      $logs && $e instanceof \Temma\Exceptions\Database && !str_contains($e->getMessage(), 'SECRET') &&
      !array_filter($logs, fn($log) => str_contains($log, 'SECRET')));

// must be the last test (the PHP built-in server processes one request at a time)
$start = microtime(true);
check('timeout option: default value',
      (TµAi::factory('ai://[' . BASE_URL . '/slow]/model')->read('Hello', 'fallback', ['timeout' => 1]) === 'fallback') &&
      ((microtime(true) - $start) < 2.5));

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
