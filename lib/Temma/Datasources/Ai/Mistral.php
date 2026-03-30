<?php

/**
 * Mistral
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Mistral AI provider for the Ai data source.
 *
 * Supports: text, images, audio, PDF input. JSON structured output.
 * The Mistral API closely follows the OpenAI format with minor differences.
 */
class Mistral {
	/** API endpoint URL. */
	const string API_URL = 'https://api.mistral.ai/v1/chat/completions';
	/** API key. */
	private string $_apiKey;

	/**
	 * Constructor.
	 * @param	string	$apiKey	API key.
	 */
	public function __construct(string $apiKey) {
		$this->_apiKey = $apiKey;
	}
	/**
	 * Build the API request payload.
	 * @param	string	$model		Model identifier.
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
			$payload['response_format'] = ['type' => 'json_object'];
		return ([
			'url'     => self::API_URL,
			'headers' => ['Authorization: Bearer ' . $this->_apiKey],
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
			throw new \Temma\Exceptions\Database("Mistral API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
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
			$dataUri = 'data:' . $att['mime'] . ';base64,' . $att['data'];
			if (str_starts_with($att['mime'], 'image/')) {
				// Mistral uses a flat string for image_url (not a nested object like OpenAI)
				$content[] = [
					'type'      => 'image_url',
					'image_url' => $dataUri,
				];
			} else if ($att['mime'] === 'application/pdf') {
				$content[] = [
					'type'         => 'document_url',
					'document_url' => $dataUri,
				];
			} else if (str_starts_with($att['mime'], 'audio/')) {
				$content[] = [
					'type'        => 'input_audio',
					'input_audio' => $att['data'],
				];
			}
		}
		return ($content);
	}
}
