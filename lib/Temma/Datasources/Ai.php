<?php

/**
 * Ai
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * Unified AI data source.
 *
 * This object provides a unified interface to interact with various LLM providers
 * (OpenAI, Claude, Gemini, Mistral, Ollama, OpenRouter, or any custom/OpenAI-compatible provider).
 *
 * Connection is set using a DSN string:
 * <ul>
 *   <li><pre>ai://provider/model#API_KEY</pre></li>
 *   <li><pre>ai://[https://endpoint/path]/model#API_KEY</pre> (OpenAI-compatible service)</li>
 *   <li><pre>ai://[\Custom\Provider]/model#API_KEY</pre> (custom provider class)</li>
 * </ul>
 * Examples:
 * <ul>
 *   <li><tt>ai://openai/gpt-4o#sk-proj-XXX</tt></li>
 *   <li><tt>ai://claude/claude-sonnet-4-20250514#sk-ant-XXX</tt></li>
 *   <li><tt>ai://gemini/gemini-2.5-flash#AIza-XXX</tt></li>
 *   <li><tt>ai://mistral/mistral-small-latest#XXX</tt></li>
 *   <li><tt>ai://openrouter/openai/gpt-4o#sk-or-XXX</tt></li>
 *   <li><tt>ai://ollama/llama3:70b</tt></li>
 *   <li><tt>ai://[https://api.groq.com/openai/v1/chat/completions]/llama-3.3-70b#gsk-XXX</tt></li>
 *   <li><tt>ai://[\App\MyProvider]/model#XXX</tt></li>
 * </ul>
 *
 * Full example:
 * <code>
 * // simple prompt
 * $response = $ai->read('What is the capital of France?');
 * $response = $ai['What is the capital of France?'];
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
		'openai'     => '\Temma\Datasources\Ai\OpenAi',
		'claude'     => '\Temma\Datasources\Ai\Claude',
		'gemini'     => '\Temma\Datasources\Ai\Gemini',
		'mistral'    => '\Temma\Datasources\Ai\Mistral',
		'openrouter' => '\Temma\Datasources\Ai\OpenRouter',
		'ollama'     => '\Temma\Datasources\Ai\Ollama',
	];
	/** Provider instance. */
	private object $_provider;
	/** Model name. */
	private string $_model;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string.
	 * @return	\Temma\Datasources\Ai	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\Ai {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\Ai object creation with DSN: '$dsn'.");
		// parse DSN: ai://provider/model#key or ai://[url_or_class]/model#key
		if (!preg_match('/^ai:\/\/(\[([^\]]+)\]|([^\/]+))\/([^#]+?)(?:#(.*))?$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid AI DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid AI DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$bracket = $matches[2] ?? '';
		$providerName = $matches[3] ?? '';
		$model = $matches[4];
		$apiKey = $matches[5] ?? '';
		// resolve provider
		if ($bracket) {
			if (preg_match('/^https?:\/\//', $bracket)) {
				// URL in brackets: OpenAI-compatible service
				return (new self('\Temma\Datasources\Ai\OpenAiCompatible', $model, $apiKey, $bracket));
			}
			// class name in brackets: custom provider
			return (new self(ltrim($bracket, '\\'), $model, $apiKey));
		}
		// built-in provider
		$providerClass = self::PROVIDERS[mb_strtolower($providerName)] ?? null;
		if (!$providerClass) {
			TµLog::log('Temma/Base', 'WARN', "Unknown AI provider '$providerName'.");
			throw new \Temma\Exceptions\Database("Unknown AI provider '$providerName'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return (new self($providerClass, $model, $apiKey));
	}
	/**
	 * Constructor.
	 * @param	string	$providerClass	Fully qualified provider class name.
	 * @param	string	$model		LLM model identifier.
	 * @param	string	$apiKey		(optional) API key.
	 * @param	string	$endpointUrl	(optional) Custom endpoint URL (for OpenAI-compatible services).
	 */
	public function __construct(string $providerClass, string $model, string $apiKey='', string $endpointUrl='') {
		if ($endpointUrl)
			$this->_provider = new $providerClass($apiKey, $endpointUrl);
		else
			$this->_provider = new $providerClass($apiKey);
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
	 * Send a prompt to the LLM and return the response.
	 * @param	string	$prompt			User prompt.
	 * @param	mixed	$defaultOrCallback	(optional) Default value returned if the API call fails,
	 *						or callback whose returned value is used as fallback.
	 * @param	mixed	$options		(optional) Associative array with:
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
	 * @return	mixed	The response text, a decoded array (for JSON output), binary data,
	 *			or the default value if the call fails.
	 * @throws	\Temma\Exceptions\Database	If the API returns an error and no default value is provided.
	 */
	public function read(string $prompt, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return ($defaultOrCallback);
		try {
			// normalize options
			$opts = is_array($options) ? $options : [];
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
			$response = $this->_curlRequest($request['url'], $request['headers'], $request['body']);
			// decode JSON response
			$data = json_decode($response, true);
			if (!is_array($data))
				throw new \Temma\Exceptions\Database("AI API returned invalid response.", \Temma\Exceptions\Database::FUNDAMENTAL);
			// parse response via provider
			$result = $this->_provider->parseResponse($data);
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
		$opts = is_array($options) ? $options : [];
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
	 * @return	array	Processed attachments, each as ['data' => base64, 'mime' => type].
	 */
	private function _processAttachments(array $attachments) : array {
		$processed = [];
		foreach ($attachments as $attachment) {
			if (is_string($attachment)) {
				// string: file path or binary content
				if (file_exists($attachment)) {
					$binary = file_get_contents($attachment);
					$mime = $this->_detectMime($binary);
				} else {
					$binary = $attachment;
					$mime = $this->_detectMime($binary);
				}
			} else if (is_array($attachment)) {
				// array with explicit path or data
				if (isset($attachment['path'])) {
					$binary = file_get_contents($attachment['path']);
					$mime = $attachment['mime'] ?? $this->_detectMime($binary);
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
				'mime'  => $mime,
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
	 * @param	string	$url		API endpoint URL.
	 * @param	array	$headers	HTTP headers.
	 * @param	array	$body		Request payload.
	 * @return	string	The response body.
	 * @throws	\Temma\Exceptions\Database	If the request fails.
	 */
	private function _curlRequest(string $url, array $headers, array $body) : string {
		$json = json_encode($body);
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HTTPHEADER     => array_merge(
				['Content-Type: application/json', 'Content-Length: ' . mb_strlen($json, 'ascii')],
				$headers
			),
		]);
		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new \Temma\Exceptions\Database("AI API cURL error: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		curl_close($ch);
		return ($response);
	}
}
