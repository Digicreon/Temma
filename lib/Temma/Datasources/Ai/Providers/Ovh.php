<?php

/**
 * Ovh
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai\Providers;

/**
 * OVHcloud AI Endpoints provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * OVHcloud AI Endpoints exposes an OpenAI-compatible API.
 * The API key is optional (anonymous usage is rate-limited).
 *
 * Managed types:
 * <ul>
 *   <li>chat: text, images input (with vision models). JSON structured output.</li>
 *   <li>transcript: e.g. "whisper-large-v3", "whisper-large-v3-turbo".</li>
 *   <li>embedding: e.g. "bge-m3", "bge-multilingual-gemma2".</li>
 * </ul>
 */
class Ovh extends OpenAiCompatible {
	/** Provider name, used in error messages. */
	const string NAME = 'OVHcloud';
	/** Endpoint URL of each supported type. */
	const array API_URLS = [
		'chat'       => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions',
		'transcript' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/audio/transcriptions',
		'embedding'  => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/embeddings',
	];
}
