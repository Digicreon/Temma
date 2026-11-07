#!/usr/bin/php
<?php

/**
 * Validation script for the Ai data source.
 * Starts a local HTTP server (PHP built-in server) that simulates AI services, then checks:
 * - the DSN parsing (type prefix, built-in providers, OpenAI-compatible URL, custom provider class);
 * - the requests built for each type of API (chat, transcript, embedding);
 * - the management of the responses (JSON, raw content, HTTP errors);
 * - the path to a file given as options.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Datasources\Ai as TµAi;
use \Temma\Datasources\Ai\Type as TµAiType;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

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
		'/chat'          => [200, 'application/json; charset=utf-8', json_encode(['choices' => [['message' => ['content' => json_encode($received)]]]])],
		'/transcript'    => [200, 'application/json', json_encode(['text' => json_encode($received)])],
		'/embedding'     => [200, 'application/json', json_encode(['data' => [['embedding' => [0.5, -0.25]]]])],
		'/binary'        => [200, 'audio/mpeg', "\xFF\xFB\x90\x00binary"],
		'/slow'          => [200, 'application/json', json_encode(['choices' => [['message' => ['content' => 'late']]]])],
		'/text-error'    => [422, 'text/plain; charset=utf-8', 'Failed to deserialize the JSON body'],
		'/json-error'    => [401, 'application/json', json_encode(['error' => ['message' => 'Invalid API key']])],
		'/unknown-error' => [401, 'application/json', json_encode(['detail' => 'Unauthorized'])],
		default          => [404, 'text/plain', 'Not found'],
	};
	http_response_code($code);
	header("Content-Type: $type");
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
// must be the last test (the PHP built-in server processes one request at a time)
$start = microtime(true);
check("timeout option: default value",
      TµAi::factory('ai://[' . BASE_URL . '/slow]/model')->read('Hello', 'fallback', ['timeout' => 1]) === 'fallback' && (microtime(true) - $start) < 2.5);

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
