<?php

/**
 * OpenAi
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai\Providers;

/**
 * OpenAI provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * Managed types:
 * <ul>
 *   <li>chat: text, images, audio input. JSON structured output.
 *       Does not support PDF input (not available in the OpenAI Chat Completions API).
 *       The "max_tokens" option is sent as "max_completion_tokens".</li>
 *   <li>transcript: e.g. "whisper-1", "gpt-4o-transcribe".</li>
 *   <li>embedding: e.g. "text-embedding-3-small".</li>
 * </ul>
 */
class OpenAi extends OpenAiCompatible {
	/** Provider name, used in error messages. */
	const string NAME = 'OpenAI';
	/** Endpoint URL of each supported type. */
	const array API_URLS = [
		'chat'       => 'https://api.openai.com/v1/chat/completions',
		'transcript' => 'https://api.openai.com/v1/audio/transcriptions',
		'embedding'  => 'https://api.openai.com/v1/embeddings',
	];

	/* ********** PROTECTED METHODS ********** */
	/**
	 * Build the request body of a chat completion.
	 * The "max_tokens" option is sent as "max_completion_tokens", because "max_tokens" is deprecated by OpenAI
	 * and rejected by the reasoning models (o-series, gpt-5).
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options.
	 * @return	array	The request body.
	 */
	protected function _buildChatBody(string $model, string $prompt, array $options) : array {
		$body = parent::_buildChatBody($model, $prompt, $options);
		if (isset($body['max_tokens'])) {
			$body['max_completion_tokens'] = $body['max_tokens'];
			unset($body['max_tokens']);
		}
		return ($body);
	}
}
