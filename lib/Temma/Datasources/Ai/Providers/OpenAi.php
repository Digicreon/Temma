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
 *       Does not support PDF input (not available in the OpenAI Chat Completions API).</li>
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
}
