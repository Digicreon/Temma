<?php

/**
 * Mistral
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai\Providers;

/**
 * Mistral AI provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * The Mistral API closely follows the OpenAI format with minor differences.
 * Managed types:
 * <ul>
 *   <li>chat: text, images, audio, PDF input. JSON structured output.</li>
 *   <li>transcript: e.g. "voxtral-mini-latest". The prompt is ignored (no context hint in the Mistral API).</li>
 *   <li>embedding: e.g. "mistral-embed".</li>
 * </ul>
 */
class Mistral extends OpenAiCompatible {
	/** Provider name, used in error messages. */
	const string NAME = 'Mistral';
	/** Endpoint URL of each supported type. */
	const array API_URLS = [
		'chat'       => 'https://api.mistral.ai/v1/chat/completions',
		'transcript' => 'https://api.mistral.ai/v1/audio/transcriptions',
		'embedding'  => 'https://api.mistral.ai/v1/embeddings',
	];

	/* ********** PROTECTED METHODS ********** */
	/**
	 * Build the request body of a chat completion.
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options.
	 * @return	array	The request body.
	 */
	protected function _buildChatBody(string $model, string $prompt, array $options) : array {
		$body = parent::_buildChatBody($model, $prompt, $options);
		if (($options['output'] ?? null) === 'application/json')
			$body['response_format'] = ['type' => 'json_object'];
		return ($body);
	}
	/**
	 * Build the request body of an audio transcription.
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt (ignored).
	 * @param	array	$options	Normalized options. The first attachment is the audio file.
	 * @return	array	The request body.
	 * @throws	\Temma\Exceptions\Database	If no audio file is given.
	 */
	protected function _buildTranscriptBody(string $model, string $prompt, array $options) : array {
		// the Mistral API has no prompt field
		return (parent::_buildTranscriptBody($model, '', $options));
	}
	/**
	 * Build the content field for a chat message.
	 * @param	string	$text		Text content.
	 * @param	?array	$attachments	(optional) Processed attachments.
	 * @return	string|array	Simple string or array of content parts.
	 */
	protected function _buildContent(string $text, ?array $attachments) : string|array {
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
