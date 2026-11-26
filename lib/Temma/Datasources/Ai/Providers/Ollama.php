<?php

/**
 * Ollama
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai\Providers;

use \Temma\Datasources\Ai\Type as TµAiType;

/**
 * Ollama provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * Ollama runs LLMs locally and exposes an OpenAI-compatible API.
 * Endpoint: http://localhost:11434/v1/chat/completions
 * No API key is required by default.
 * For an Ollama server which is not on localhost, use the generic OpenAI-compatible syntax
 * (e.g. <tt>ai://[http://ollama.example.com:11434/v1/chat/completions]/llama3:70b</tt>).
 *
 * Model names may contain colons for tags (e.g. "llama3:70b", "mistral:latest").
 *
 * Supports: text, images input. JSON structured output.
 * Actual feature support depends on the model.
 *
 * Managed type: chat only.
 */
class Ollama implements \Temma\Datasources\Ai\Provider {
	/** Default API endpoint URL. */
	const string API_URL = 'http://localhost:11434/v1/chat/completions';
	/** API endpoint URL. */
	private string $_apiUrl;

	/**
	 * Constructor.
	 * @param	string		$apiKey		(optional) API key (usually empty for local Ollama, not used).
	 * @param	string		$endpointUrl	(optional) Endpoint URL. Defaults to the API_URL constant. Not given by
	 *					the DSN "ai://ollama/MODEL" (for a remote server, see the class documentation).
	 * @param	TµAiType	$type		(optional) Type of API. Only chat is supported.
	 * @throws	\Temma\Exceptions\Database	If the type is not supported.
	 */
	// @phpstan-ignore-next-line (unused parameter required by the Provider interface)
	public function __construct(string $apiKey='', string $endpointUrl='', TµAiType $type=TµAiType::Chat) {
		if ($type !== TµAiType::Chat)
			throw new \Temma\Exceptions\Database("Ollama provider doesn't support the '{$type->value}' type.", \Temma\Exceptions\Database::FUNDAMENTAL);
		$this->_apiUrl = $endpointUrl ?: self::API_URL;
	}
	/**
	 * Build the API request payload.
	 * @param	string	$model		Model identifier (e.g. "llama3:70b").
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options.
	 * @return	array	Associative array with 'url', 'headers', and 'body' keys.
	 */
	public function buildPayload(string $model, string $prompt, array $options) : array {
		// build messages list
		$messages = [];
		if (isset($options['system']))
			$messages[] = ['role' => 'system', 'content' => $options['system']];
		// conversation history
		if (isset($options['messages'])) {
			foreach ($options['messages'] as $msg) {
				if (isset($msg['user'])) {
					$content = $this->_buildContent($msg['user'], $msg['attachments'] ?? null);
					$messages[] = ['role' => 'user', 'content' => $content];
				} else if (isset($msg['ai']))
					$messages[] = ['role' => 'assistant', 'content' => $msg['ai']];
			}
		}
		// current prompt with attachments
		$content = $this->_buildContent($prompt, $options['attachments'] ?? null);
		$messages[] = ['role' => 'user', 'content' => $content];
		// build payload
		$payload = [
			'model'    => $model,
			'messages' => $messages,
		];
		if (isset($options['temperature']))
			$payload['temperature'] = (float)$options['temperature'];
		if (isset($options['max_tokens']))
			$payload['max_tokens'] = (int)$options['max_tokens'];
		// JSON mode (the "format" parameter of the native Ollama API is ignored by the OpenAI-compatible endpoint)
		if (($options['output'] ?? null) === 'application/json')
			$payload['response_format'] = ['type' => 'json_object'];
		return ([
			'url'     => $this->_apiUrl,
			'headers' => [],
			'body'    => $payload,
		]);
	}
	/**
	 * Parse the API response.
	 * @param	array	$data	Decoded JSON response (also for HTTP errors), or associative array with 'type' (content
	 *				type) and 'content' (raw response) keys if the response is not JSON.
	 * @return	?string	The response content, or null if it is not found (always the case for a non-JSON response).
	 * @throws	\Temma\Exceptions\Database	If the API returned an error.
	 */
	public function parseResponse(array $data) : ?string {
		if (isset($data['error'])) {
			// the error may be an object with a message, or a simple string
			$msg = is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error.') : (string)$data['error'];
			throw new \Temma\Exceptions\Database("Ollama API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return ($data['choices'][0]['message']['content'] ?? null);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Build the content field for a message.
	 * @param	string	$text		Text content.
	 * @param	?array	$attachments	(optional) Processed attachments.
	 * @return	string|array	Simple string or array of content parts.
	 */
	private function _buildContent(string $text, ?array $attachments) : string|array {
		if (!$attachments)
			return ($text);
		$content = [['type' => 'text', 'text' => $text]];
		foreach ($attachments as $att) {
			if (str_starts_with($att['mime'], 'image/')) {
				$content[] = [
					'type'      => 'image_url',
					'image_url' => ['url' => 'data:' . $att['mime'] . ';base64,' . $att['data']],
				];
			}
		}
		return ($content);
	}
}
