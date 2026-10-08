<?php

/**
 * Ovh
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * OVHcloud AI Endpoints provider for the Ai data source.
 *
 * This class is not intended to be instantiated directly.
 * It is used internally by \Temma\Datasources\Ai.
 *
 * OVHcloud AI Endpoints exposes an OpenAI-compatible API.
 * The API key is optional (anonymous usage is rate-limited).
 *
 * Supports: text, images input (with vision models). JSON structured output.
 */
class Ovh extends OpenAiCompatible {
	/** API endpoint URL. */
	const string API_URL = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions';

	/**
	 * Constructor.
	 * @param	string	$apiKey	(optional) API key.
	 */
	public function __construct(string $apiKey='') {
		parent::__construct($apiKey, self::API_URL);
	}
}
