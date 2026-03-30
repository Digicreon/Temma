<?php

/**
 * OpenAi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * OpenAI provider for the Ai data source.
 *
 * Supports: text, images, audio input. JSON structured output.
 * Does not support: PDF input (not available in the OpenAI Chat Completions API).
 */
class OpenAi {
	/** API endpoint URL. */
	const string API_URL = 'https://api.openai.com/v1/chat/completions';
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
			throw new \Temma\Exceptions\Database("OpenAI API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
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
			} else if (str_starts_with($att['mime'], 'audio/')) {
				$content[] = [
					'type'        => 'input_audio',
					'input_audio' => [
						'data'   => $att['data'],
						'format' => $this->_audioFormat($att['mime']),
					],
				];
			}
		}
		return ($content);
	}
	/**
	 * Convert MIME type to OpenAI audio format identifier.
	 * @param	string	$mime	MIME type.
	 * @return	string	Audio format.
	 */
	private function _audioFormat(string $mime) : string {
		return (match ($mime) {
			'audio/wav'  => 'wav',
			'audio/mpeg' => 'mp3',
			'audio/ogg'  => 'ogg',
			'audio/flac' => 'flac',
			default      => 'mp3',
		});
	}
}
