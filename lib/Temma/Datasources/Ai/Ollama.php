<?php

/**
 * Ollama
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Ollama provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * Ollama runs LLMs locally and exposes an OpenAI-compatible API.
 * Default endpoint: http://localhost:11434/v1/chat/completions
 * No API key is required by default.
 *
 * Model names may contain colons for tags (e.g. "llama3:70b", "mistral:latest").
 *
 * Supports: text, images input. JSON structured output.
 * Actual feature support depends on the model.
 */
class Ollama {
	/** Default API endpoint URL. */
	const string API_URL = 'http://localhost:11434/v1/chat/completions';

	/**
	 * Constructor.
	 * @param	string	$apiKey	(optional) API key (usually empty for local Ollama).
	 */
	public function __construct(string $apiKey='') {
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
		if (($options['output'] ?? null) === 'application/json')
			$payload['format'] = 'json';
		return ([
			'url'     => self::API_URL,
			'headers' => [],
			'body'    => $payload,
		]);
	}
	/**
	 * Parse the API response.
	 * @param	array	$data	Decoded JSON response.
	 * @return	?string	The response content.
	 * @throws	\Temma\Exceptions\Database	If the API returned an error.
	 */
	public function parseResponse(array $data) : ?string {
		if (isset($data['error'])) {
			$msg = $data['error']['message'] ?? 'Unknown error.';
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
