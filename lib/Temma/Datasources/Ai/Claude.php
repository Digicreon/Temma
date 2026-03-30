<?php

/**
 * Claude
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Claude (Anthropic) provider for the Ai data source.
 *
 * Supports: text, images, PDF input. JSON structured output.
 * Does not support: audio input (not available in the Anthropic Messages API).
 */
class Claude {
	/** API endpoint URL. */
	const string API_URL = 'https://api.anthropic.com/v1/messages';
	/** API version. */
	const string API_VERSION = '2023-06-01';
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
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => (int)($options['max_tokens'] ?? 4096),
		];
		if (isset($options['system']))
			$payload['system'] = $options['system'];
		if (isset($options['temperature']))
			$payload['temperature'] = (float)$options['temperature'];
		return ([
			'url'     => self::API_URL,
			'headers' => [
				'x-api-key: ' . $this->_apiKey,
				'anthropic-version: ' . self::API_VERSION,
			],
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
			throw new \Temma\Exceptions\Database("Claude API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return ($data['content'][0]['text'] ?? null);
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
		// attachments before text (Claude convention)
		$content = [];
		foreach ($attachments as $att) {
			if (str_starts_with($att['mime'], 'image/')) {
				$content[] = [
					'type'   => 'image',
					'source' => [
						'type'       => 'base64',
						'media_type' => $att['mime'],
						'data'       => $att['data'],
					],
				];
			} else if ($att['mime'] === 'application/pdf') {
				$content[] = [
					'type'   => 'document',
					'source' => [
						'type'       => 'base64',
						'media_type' => $att['mime'],
						'data'       => $att['data'],
					],
				];
			}
		}
		$content[] = ['type' => 'text', 'text' => $text];
		return ($content);
	}
}
