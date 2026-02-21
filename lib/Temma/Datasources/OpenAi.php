<?php

/**
 * OpenAi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-openai
 */

namespace Temma\Datasources;

use \Temma\Base\Log as TµLog;

/**
 * OpenAI management object.
 *
 * This object is used to interact with the OpenAI Chat Completions API.
 *
 * <b>Usage</b>
 * <code>
 * // initialization
 * $ai = \Temma\Datasources\OpenAi::factory('openai://chat/gpt-4o/API_KEY');
 * $ai = \Temma\Base\Datasource::factory('openai://chat/gpt-4o/API_KEY');
 *
 * // simple prompt
 * $response = $ai->read('What is the capital of France?');
 * $response = $ai['What is the capital of France?'];
 *
 * // prompt with options
 * $response = $ai->read('Translate to French: Hello', null, [
 *     'system'     => 'You are a translator.',
 *     'temperature' => 0.3,
 *     'max_tokens'  => 500,
 * ]);
 *
 * // multi-turn conversation
 * $response = $ai->read('What about Italy?', null, [
 *     'messages' => [
 *         ['role' => 'user', 'content' => 'What is the capital of France?'],
 *         ['role' => 'assistant', 'content' => 'The capital of France is Paris.'],
 *     ],
 * ]);
 * </code>
 */
class OpenAi extends \Temma\Base\Datasource {
	/** URL of the OpenAI Chat Completions API. */
	const string API_URL = 'https://api.openai.com/v1/chat/completions';
	/** API key. */
	private string $_apiKey;
	/** LLM model. */
	private string $_model;

	/* ********** CONSTRUCTION ********** */
	/**
	 * Create a new instance of this class.
	 * @param	string	$dsn	Connection string (format: openai://chat/MODEL/API_KEY).
	 * @return	\Temma\Datasources\OpenAi	The created instance.
	 * @throws	\Temma\Exceptions\Database	If the DSN is invalid.
	 */
	static public function factory(string $dsn) : \Temma\Datasources\OpenAi {
		TµLog::log('Temma/Base', 'DEBUG', "\\Temma\\Datasources\\OpenAi object creation with DSN: '$dsn'.");
		if (!preg_match('/^openai:\/\/chat\/([^\/]+)\/(.+)$/', $dsn, $matches)) {
			TµLog::log('Temma/Base', 'WARN', "Invalid OpenAI DSN '$dsn'.");
			throw new \Temma\Exceptions\Database("Invalid OpenAI DSN '$dsn'.", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		$model = $matches[1];
		$apiKey = $matches[2];
		return (new self($apiKey, $model));
	}
	/**
	 * Constructor.
	 * @param	string	$apiKey	API key.
	 * @param	string	$model	LLM model identifier.
	 */
	public function __construct(string $apiKey, string $model) {
		$this->_apiKey = $apiKey;
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
	 * Get a response from the OpenAI Chat Completions API.
	 * @param	string	$prompt			User prompt.
	 * @param	mixed	$defaultOrCallback	(optional) Default value returned if the API call fails,
	 *						or callback whose returned value is used as fallback.
	 * @param	mixed	$options		(optional) Associative array with:
	 *						- system (string): System prompt.
	 *						- messages (array): Previous messages for multi-turn conversation.
	 *						- temperature (float): Sampling temperature (0 to 2).
	 *						- max_tokens (int): Maximum number of tokens in the response.
	 * @return	mixed	The response text, or the default value if the call fails.
	 * @throws	\Temma\Exceptions\Database	If the API returns an error and no default value is provided.
	 */
	public function read(string $prompt, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		if (!$this->_enabled)
			return ($defaultOrCallback);
		// build messages list
		$messages = [];
		if (is_array($options)) {
			if (isset($options['system']))
				$messages[] = ['role' => 'system', 'content' => $options['system']];
			if (isset($options['messages']))
				$messages = array_merge($messages, $options['messages']);
		}
		$messages[] = ['role' => 'user', 'content' => $prompt];
		// build request payload
		$payload = [
			'model'    => $this->_model,
			'messages' => $messages,
		];
		if (is_array($options)) {
			if (isset($options['temperature']))
				$payload['temperature'] = (float)$options['temperature'];
			if (isset($options['max_tokens']))
				$payload['max_tokens'] = (int)$options['max_tokens'];
		}
		// send request
		$json = json_encode($payload);
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => self::API_URL,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->_apiKey,
				'Content-Length: ' . mb_strlen($json, 'ascii'),
			],
		]);
		$response = curl_exec($ch);
		if ($response === false) {
			$error = curl_error($ch);
			curl_close($ch);
			if ($defaultOrCallback !== null)
				return (is_callable($defaultOrCallback) ? $defaultOrCallback() : $defaultOrCallback);
			throw new \Temma\Exceptions\Database("OpenAI cURL error: $error", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		curl_close($ch);
		// decode response
		$data = json_decode($response, true);
		if (isset($data['error'])) {
			$errorMsg = $data['error']['message'] ?? 'Unknown error.';
			if ($defaultOrCallback !== null)
				return (is_callable($defaultOrCallback) ? $defaultOrCallback() : $defaultOrCallback);
			throw new \Temma\Exceptions\Database("OpenAI API error: $errorMsg", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return ($data['choices'][0]['message']['content'] ?? null);
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
	 * Get a response from the OpenAI Chat Completions API.
	 * @param	string	$prompt			User prompt.
	 * @param	mixed	$defaultOrCallback	(optional) Default value returned if the API call fails.
	 * @param	mixed	$options		(optional) Options (see read()).
	 * @return	mixed	The response text.
	 */
	public function get(string $prompt, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		return ($this->read($prompt, $defaultOrCallback, $options));
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
}

