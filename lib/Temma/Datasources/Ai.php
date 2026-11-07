<?php

/**
 * Ai
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;
use \Temma\Datasources\Ai\Type as TµAiType;

/**
 * Unified AI data source.
 *
 * This object provides a unified interface to interact with various AI providers
 * (OpenAI, Claude, Gemini, Mistral, Ollama, OpenRouter, OVHcloud, or any custom/OpenAI-compatible provider).
 *
 * Connection is set using a DSN string:
 * <ul>
 *   <li><pre>ai://provider/model#API_KEY</pre></li>
 *   <li><pre>ai://[https://endpoint/path]/model#API_KEY</pre> (OpenAI-compatible service)</li>
 *   <li><pre>ai://[\Custom\Provider]/model#API_KEY</pre> (custom provider class,
 *       which must implement the \Temma\Datasources\Ai\Provider interface)</li>
 * </ul>
 * The DSN may be prefixed by the type of API ("chat", "transcript" or "embedding"), followed by an "@" character
 * (e.g. <tt>ai://transcript@openai/whisper-1#API_KEY</tt>). Without prefix, the type is "chat".
 * Supported types by provider:
 * <ul>
 *   <li>chat: all providers.</li>
 *   <li>transcript: openai, mistral, ovh, and OpenAI-compatible services.</li>
 *   <li>embedding: openai, mistral, ovh, and OpenAI-compatible services.</li>
 * </ul>
 * Examples:
 * <ul>
 *   <li><tt>ai://openai/gpt-4o#sk-proj-XXX</tt></li>
 *   <li><tt>ai://claude/claude-sonnet-4-20250514#sk-ant-XXX</tt></li>
 *   <li><tt>ai://gemini/gemini-2.5-flash#AIza-XXX</tt></li>
 *   <li><tt>ai://mistral/mistral-small-latest#XXX</tt></li>
 *   <li><tt>ai://openrouter/openai/gpt-4o#sk-or-XXX</tt></li>
 *   <li><tt>ai://ollama/llama3:70b</tt></li>
 *   <li><tt>ai://ovh/gpt-oss-120b#XXX</tt></li>
 *   <li><tt>ai://[https://api.groq.com/openai/v1/chat/completions]/llama-3.3-70b#gsk-XXX</tt></li>
 *   <li><tt>ai://[\App\MyProvider]/model#XXX</tt></li>
 *   <li><tt>ai://transcript@openai/whisper-1#sk-proj-XXX</tt></li>
 *   <li><tt>ai://transcript@ovh/whisper-large-v3#XXX</tt></li>
 *   <li><tt>ai://transcript@[https://api.groq.com/openai/v1/audio/transcriptions]/whisper-large-v3#gsk-XXX</tt></li>
 *   <li><tt>ai://embedding@mistral/mistral-embed#XXX</tt></li>
 * </ul>
 *
 * Full example:
 * <code>
 * // simple prompt
 * $response = $ai->read('What is the capital of France?');
 * $response = $ai['What is the capital of France?'];
 *
 * // prompt with a file (the third parameter is the path to the file)
 * $response = $ai->read('Describe this image', null, '/path/to/photo.jpg');
 *
 * // prompt with options
 * $response = $ai->read('Translate to French: Hello', null, [
 *     'system'      => 'You are a translator.',
 *     'temperature'  => 0.3,
 *     'max_tokens'   => 500,
 * ]);
 *
 * // multi-turn conversation
 * $response = $ai->read('What about Italy?', null, [
 *     'messages' => [
 *         ['user' => 'What is the capital of France?'],
 *         ['ai' => 'The capital of France is Paris.'],
 *     ],
 * ]);
 *
 * // JSON structured output (returns a PHP array)
 * $data = $ai->read('List the 3 largest cities in France', null, [
 *     'output' => 'json',
 * ]);
 *
 * // vision (image input)
 * $response = $ai->read('Describe this image', null, [
 *     'attachments' => ['/path/to/photo.jpg'],
 * ]);
 *
 * // binary attachment with explicit MIME type
 * $response = $ai->read('Summarize this document', null, [
 *     'attachments' => [
 *         ['data' => $pdfContent, 'mime' => 'application/pdf'],
 *     ],
 * ]);
 *
 * // audio transcription (DSN "ai://transcript@ovh/whisper-large-v3#XXX")
 * // the prompt is an optional context hint
 * $text = $ai->read('', null, '/path/to/audio.mp3');
 *
 * // embedding (DSN "ai://embedding@openai/text-embedding-3-small#sk-proj-XXX")
 * // returns a list of floats
 * $vector = $ai->read('Text to embed');
 * </code>
 */
class Ai extends \Temma\Base\Datasource {
	/** Output format aliases. */
	const array OUTPUT_ALIASES = [
		'json'  => 'application/json',
		'csv'   => 'text/csv',
		'html'  => 'text/html',
		'xml'   => 'application/xml',
		'pdf'   => 'application/pdf',
		'audio' => 'audio/mpeg',
		'wav'   => 'audio/wav',
		'image' => 'image/png',
		'video' => 'video/mp4',
	];
	/** Built-in provider mapping. */
	const array PROVIDERS = [
		'openai'     => '\Temma\Datasources\Ai\Providers\OpenAi',
		'claude'     => '\Temma\Datasources\Ai\Providers\Claude',
		'gemini'     => '\Temma\Datasources\Ai\Providers\Gemini',
		'mistral'    => '\Temma\Datasources\Ai\Providers\Mistral',
		'openrouter' => '\Temma\Datasources\Ai\Providers\OpenRouter',
		'ollama'     => '\Temma\Datasources\Ai\Providers\Ollama',
		'ovh'        => '\Temma\Datasources\Ai\Providers\Ovh',
	];
	/** Provider instance. */
	private \Temma\Datasources\Ai\Provider $_provider;
	/** Model name. */
	private string $_model;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Ai	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid, or if the provider doesn't support the type.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Ai {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Ai object creation with DSN: '$dsn'.");
		// parse DSN: ai://[type@]provider/model#key or ai://[type@][url_or_class]/model#key
		if (!preg_match('/^ai:\/\/(?:([a-zA-Z]+)@)?(\[([^\]]+)\]|([^\/]+))\/([^#]+?)(?:#(.*))?$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid AI DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid AI DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$typeName = $matches[1] ?: 'chat';
		$bracket = $matches[3] ?? '';
		$providerName = $matches[4] ?? '';
		$model = $matches[5];
		$apiKey = $matches[6] ?? '';
		// resolve type
		$type = TµAiType::tryFrom(mb_strtolower($typeName));
		if (!$type) {
			TµLog::log('Temma/Base', 'WARN', "Unknown AI type '$typeName'.");
			throw new \Temma\Exceptions\Database("Unknown AI type '$typeName'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		// resolve provider
		if ($bracket) {
			if (preg_match('/^https?:\/\//', $bracket)) {
				// URL in brackets: OpenAI-compatible service
				return (new self('\Temma\Datasources\Ai\Providers\OpenAiCompatible', $model, $apiKey, $bracket, $type));
			}
			// class name in brackets: custom provider
			return (new self(ltrim($bracket, '\\'), $model, $apiKey, '', $type));
		}
		// built-in provider
		$providerClass = self::PROVIDERS[mb_strtolower($providerName)] ?? null;
		if (!$providerClass) {
			TµLog::log('Temma/Base', 'WARN', "Unknown AI provider '$providerName'.");
			throw new \Temma\Exceptions\Database("Unknown AI provider '$providerName'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return (new self($providerClass, $model, $apiKey, '', $type));
	}
	/**
	 * Constructor.
	 * @param	string		$providerClass	Fully qualified provider class name.
	 * @param	string		$model		Model identifier.
	 * @param	string		$apiKey		(optional) API key.
	 * @param	string		$endpointUrl	(optional) Custom endpoint URL (for OpenAI-compatible services).
	 * @param	TµAiType	$type		(optional) Type of API. Defaults to chat.
	 * @throws	\Temma\Exceptions\Database	If the provider class doesn't implement the Provider interface,
	 *						or if the provider doesn't support the type.
	 */
	public function __construct(string $providerClass, string $model, string $apiKey='', string $endpointUrl='', TµAiType $type=TµAiType::Chat) {
		if (!is_a($providerClass, '\Temma\Datasources\Ai\Provider', true)) {
			TµLog::log('Temma/Base', 'WARN', "AI provider '$providerClass' doesn't implement the \\Temma\\Datasources\\Ai\\Provider interface.");
			throw new \Temma\Exceptions\Database("AI provider '$providerClass' doesn't implement the \\Temma\\Datasources\\Ai\\Provider interface.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$this->_provider = new $providerClass($apiKey, $endpointUrl, $type);
		$this->_model = $model;
	}

	/* ********** STANDARD REQUESTS ********** */
	/**
	 * Disabled clear.
	 * @param	string	$pattern	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function clear(string $pattern) : void {
		throw new \Temma\Exceptions\Database("No clear() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** RAW REQUESTS ********** */
	/**
	 * Send a prompt to the AI service and return the response.
	 * @param	string	$prompt			User prompt (for transcriptions: optional context hint;
	 *						for embeddings: text to embed).
	 * @param	mixed	$defaultOrCallback	(optional) Default value returned if the API call fails,
	 *						or callback whose returned value is used as fallback.
	 * @param	mixed	$options		(optional) Path to a file to send (string), or associative array with:
	 *						- system (string): System prompt.
	 *						- messages (array): Previous messages for multi-turn conversation.
	 *						  Each message: ['user' => '...'] or ['ai' => '...'],
	 *						  optionally with an 'attachments' key.
	 *						- temperature (float): Sampling temperature (0 to 2).
	 *						- max_tokens (int): Maximum number of tokens in the response.
	 *						- attachments (array): Files to send (images, audio, video, PDF).
	 *						  Each: string (path or binary), or ['data' => binary, 'mime' => type],
	 *						  or ['path' => filepath, 'mime' => type].
	 *						- output (string): Output format (alias or MIME type).
	 *						- timeout (int): Maximum duration of the request, in seconds (600 by default).
	 * @return	mixed	The response text, a decoded array (for JSON output), a list of floats (for embeddings),
	 *			binary data, or the default value if the call fails.
	 * @throws	\Temma\Exceptions\Database	If the API returns an error and no default value is provided.
	 * @throws	\Temma\Exceptions\IO		If a file can't be read and no default value is provided.
	 */
	public function read(string $prompt, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return ($defaultOrCallback);
		try {
			// normalize options (a string is the path to a file)
			$opts = is_string($options) ? ['attachments' => [['path' => $options]]] : (is_array($options) ? $options : []);
			// process attachments
			if (isset($opts['attachments']))
				$opts['attachments'] = $this->_processAttachments($opts['attachments']);
			// process message attachments
			if (isset($opts['messages'])) {
				foreach ($opts['messages'] as &$msg) {
					if (isset($msg['attachments']))
						$msg['attachments'] = $this->_processAttachments($msg['attachments']);
				}
				unset($msg);
			}
			// resolve output alias
			if (isset($opts['output']))
				$opts['output'] = self::OUTPUT_ALIASES[$opts['output']] ?? $opts['output'];
			// add JSON instruction to system prompt
			if (($opts['output'] ?? null) === 'application/json') {
				$jsonInstruction = 'Always respond with valid JSON only, without any additional text, explanation, or markdown formatting.';
				if (isset($opts['system']))
					$opts['system'] .= "\n" . $jsonInstruction;
				else
					$opts['system'] = $jsonInstruction;
			}
			// build request via provider
			$request = $this->_provider->buildPayload($this->_model, $prompt, $opts);
			// execute cURL
			$response = $this->_curlRequest($request, (int)($opts['timeout'] ?? 600));
			// decode JSON response; other responses are given as received, with their content type
			$isJson = str_starts_with(mb_strtolower($response['type']), 'application/json');
			$data = $isJson ? json_decode($response['body'], true) : ['type' => $response['type'], 'content' => $response['body']];
			if (!is_array($data))
				throw new \Temma\Exceptions\Database("AI API returned invalid response.", \Temma\Exceptions\Database::FUNDAMENTAL);
			// parse response via provider (which manages the error messages of the API)
			$result = ($isJson || $response['code'] < 400) ? $this->_provider->parseResponse($data) : null;
			// HTTP error not reported by the provider
			if ($response['code'] >= 400)
				throw new \Temma\Exceptions\Database("AI API HTTP error {$response['code']}: " . mb_substr($response['body'], 0, 500), \Temma\Exceptions\Database::FUNDAMENTAL);
			return ($result);
		} catch (\Exception $e) {
			TµLog::log('Temma/Base', 'NOTE', "AI API error: " . $e->getMessage());
			if ($defaultOrCallback !== null)
				return (is_callable($defaultOrCallback) ? $defaultOrCallback() : $defaultOrCallback);
			throw $e;
		}
	}
	/**
	 * Disabled write.
	 * @param	string	$key		Not used.
	 * @param	string	$value		Not used.
	 * @param	mixed	$options	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function write(string $key, string $value=null, mixed $options=null) : mixed {
		throw new \Temma\Exceptions\Database("No write() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** KEY-VALUE REQUESTS ********** */
	/**
	 * Send a prompt to the LLM and return the response as structured data (JSON).
	 * Activates JSON output mode on the LLM so that the response is always valid JSON,
	 * and returns a decoded PHP array.
	 * @param	string	$prompt			User prompt.
	 * @param	mixed	$defaultOrCallback	(optional) Default value returned if the API call fails.
	 * @param	mixed	$options		(optional) Options (see read()).
	 * @return	mixed	The decoded response (array or scalar).
	 */
	public function get(string $prompt, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		$opts = is_string($options) ? ['attachments' => [['path' => $options]]] : (is_array($options) ? $options : []);
		if (!isset($opts['output']))
			$opts['output'] = 'json';
		$result = $this->read($prompt, $defaultOrCallback, $opts);
		if (is_string($result))
			$result = json_decode($result, true);
		return ($result);
	}
	/**
	 * Disabled set.
	 * @param	string	$key		Not used.
	 * @param	mixed	$value		Not used.
	 * @param	mixed	$options	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function set(string $key, mixed $value=null, mixed $options=null) : mixed {
		throw new \Temma\Exceptions\Database("No set() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}
	/**
	 * Disabled mSet.
	 * @param	array	$data		Not used.
	 * @param	mixed	$options	Not used.
	 * @throws	\Temma\Exceptions\Database	Always throws an exception.
	 */
	public function mSet(array $data, mixed $options=null) : int {
		throw new \Temma\Exceptions\Database("No mSet() method on this object.", \Temma\Exceptions\Database::FUNDAMENTAL);
	}

	/* ********** ARRAY SYNTAX ********** */
	/**
	 * Array-access read. Returns a raw text response (not JSON).
	 * Overrides the default behavior (which calls get()) to use read() instead,
	 * because $ai['prompt'] is naturally a text question.
	 * @param	mixed	$key	The prompt.
	 * @return	mixed	The text response.
	 */
	public function offsetGet(mixed $key) : mixed {
		return ($this->read($key));
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Process a list of attachments into a normalized format.
	 * @param	array	$attachments	List of attachments.
	 * @return	array	Processed attachments, each as ['data' => base64, 'mime' => type, 'name' => file name or null].
	 * @throws	\Temma\Exceptions\IO	If a file can't be read.
	 */
	private function _processAttachments(array $attachments) : array {
		$processed = [];
		foreach ($attachments as $attachment) {
			$name = null;
			if (is_string($attachment)) {
				// string: file path or binary content
				if (file_exists($attachment)) {
					$binary = file_get_contents($attachment);
					$mime = $this->_detectMime($binary);
					$name = basename($attachment);
				} else {
					$binary = $attachment;
					$mime = $this->_detectMime($binary);
				}
			} else if (is_array($attachment)) {
				// array with explicit path or data
				if (isset($attachment['path'])) {
					if (!is_file($attachment['path']) || !is_readable($attachment['path']))
						throw new \Temma\Exceptions\IO("Unable to read file '{$attachment['path']}'.", \Temma\Exceptions\IO::UNREADABLE);
					$binary = file_get_contents($attachment['path']);
					$mime = $attachment['mime'] ?? $this->_detectMime($binary);
					$name = basename($attachment['path']);
				} else if (isset($attachment['data'])) {
					$binary = $attachment['data'];
					$mime = $attachment['mime'] ?? $this->_detectMime($binary);
				} else {
					continue;
				}
			} else {
				continue;
			}
			$processed[] = [
				'data' => base64_encode($binary),
				'mime' => $mime,
				'name' => $name,
			];
		}
		return ($processed);
	}
	/**
	 * Detect the MIME type of binary data.
	 * @param	string	$data	Binary data.
	 * @return	string	The detected MIME type.
	 */
	private function _detectMime(string $data) : string {
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		return ($finfo->buffer($data) ?: 'application/octet-stream');
	}
	/**
	 * Execute an HTTP POST request.
	 * @param	array	$request	Request built by the provider ('url', 'headers', 'body' and 'encoding' keys).
	 * @param	int	$timeout	Maximum duration of the request, in seconds.
	 * @return	array	Associative array with 'code' (HTTP status code), 'type' (content type) and 'body' keys.
	 * @throws	\Temma\Exceptions\Database	If the request fails.
	 */
	private function _curlRequest(array $request, int $timeout) : array {
		$headers = $request['headers'];
		if (($request['encoding'] ?? null) === 'multipart') {
			// the array is sent as multipart/form-data by cURL
			$body = $request['body'];
		} else {
			$body = json_encode($request['body']);
			$headers = array_merge(['Content-Type: application/json', 'Content-Length: ' . mb_strlen($body, 'ascii')], $headers);
		}
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $request['url'],
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_HTTPHEADER     => $headers,
		]);
		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new \Temma\Exceptions\Database("AI API cURL error: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$result = [
			'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
			'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
			'body' => $response,
		];
		curl_close($ch);
		return ($result);
	}
}
