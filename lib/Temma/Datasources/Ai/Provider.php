<?php

/**
 * Provider
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/datasource-ai
 */

namespace Temma\Datasources\Ai;

/**
 * Interface of the provider objects used by the Ai data source.
 *
 * A provider builds the HTTP requests sent to an AI service, and parses its responses.
 * It may manage one or several types of API (see \Temma\Datasources\Ai\Type).
 * Its constructor must throw an exception if the requested type is not supported.
 */
interface Provider {
	/**
	 * Constructor.
	 * @param	string	$apiKey		(optional) API key.
	 * @param	string	$endpointUrl	(optional) Endpoint URL, given in the DSN for OpenAI-compatible services.
	 * @param	Type	$type		(optional) Type of API. Defaults to chat.
	 * @throws	\Temma\Exceptions\Database	If the type is not supported.
	 */
	public function __construct(string $apiKey='', string $endpointUrl='', Type $type=Type::Chat);
	/**
	 * Build the API request.
	 * @param	string	$model		Model identifier.
	 * @param	string	$prompt		User prompt.
	 * @param	array	$options	Normalized options. Attachments are processed,
	 *					each one as ['data' => base64 content, 'mime' => MIME type,
	 *					'name' => file name (null if the content was not given as a file path)].
	 * @return	array	Associative array with the keys:
	 *			- url (string): API endpoint URL.
	 *			- headers (array): HTTP headers.
	 *			- body (array): Request payload.
	 *			- encoding (string): (optional) 'multipart' to send the body as multipart/form-data
	 *			  (files must be given as \CURLFile or \CURLStringFile objects). JSON by default.
	 */
	public function buildPayload(string $model, string $prompt, array $options) : array;
	/**
	 * Parse the API response.
	 * @param	array	$data	Decoded response if its content type is 'application/json'. Otherwise,
	 *				associative array with 'type' (content type) and 'content' (raw response) keys.
	 * @return	mixed	The response content.
	 * @throws	\Temma\Exceptions\Database	If the API returned an error.
	 */
	public function parseResponse(array $data) : mixed;
}
