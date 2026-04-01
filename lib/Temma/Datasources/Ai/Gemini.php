<?php

/**
 * Gemini
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Google Gemini provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * Supports: text, images, audio, video, PDF input. JSON structured output.
 * Gemini uses a unified inline_data format for all binary content types.
 */
class Gemini {
	/** API base URL. */
	const string API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
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
		// build contents list
		$contents = [];
		// conversation history
		if (isset($options['messages'])) {
			foreach ($options['messages'] as $msg) {
				if (isset($msg['user'])) {
					$parts = $this->_buildParts($msg['user'], $msg['attachments'] ?? null);
					$contents[] = ['role' => 'user', 'parts' => $parts];
				} else if (isset($msg['ai']))
					$contents[] = ['role' => 'model', 'parts' => [['text' => $msg['ai']]]];
			}
		}
		// current prompt with attachments
		$parts = $this->_buildParts($prompt, $options['attachments'] ?? null);
		$contents[] = ['role' => 'user', 'parts' => $parts];
		// build payload
		$payload = ['contents' => $contents];
		// system instruction
		if (isset($options['system']))
			$payload['system_instruction'] = ['parts' => [['text' => $options['system']]]];
		// generation config
		$genConfig = [];
		if (isset($options['temperature']))
			$genConfig['temperature'] = (float)$options['temperature'];
		if (isset($options['max_tokens']))
			$genConfig['max_output_tokens'] = (int)$options['max_tokens'];
		if (($options['output'] ?? null) === 'application/json')
			$genConfig['response_mime_type'] = 'application/json';
		if ($genConfig)
			$payload['generation_config'] = $genConfig;
		return ([
			'url'     => self::API_BASE_URL . $model . ':generateContent',
			'headers' => ['x-goog-api-key: ' . $this->_apiKey],
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
			throw new \Temma\Exceptions\Database("Gemini API error: $msg", \Temma\Exceptions\Database::FUNDAMENTAL);
		}
		return ($data['candidates'][0]['content']['parts'][0]['text'] ?? null);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Build the parts array for a content entry.
	 * @param	string	$text		Text content.
	 * @param	?array	$attachments	(optional) Processed attachments.
	 * @return	array	List of part objects.
	 */
	private function _buildParts(string $text, ?array $attachments) : array {
		$parts = [];
		// attachments as inline_data parts
		if ($attachments) {
			foreach ($attachments as $att) {
				$parts[] = [
					'inline_data' => [
						'mime_type' => $att['mime'],
						'data'      => $att['data'],
					],
				];
			}
		}
		$parts[] = ['text' => $text];
		return ($parts);
	}
}
