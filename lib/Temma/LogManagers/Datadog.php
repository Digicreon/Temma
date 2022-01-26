<?php

/**
 * Datadog
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2022, Amaury Bouchard
 */

namespace Temma\LogManagers;

use \Temma\Base\Log as TµLog;

/**
 * Log manager that connects to the Datadog service (https://datadog.com).
 *
 * It needs a "x-datadog" extra configuration in the 'etc/temma.json' file,
 * with three keys:
 * - "url": (mandatory) The Datadog connection URL.
 *          EU:      https://http-intake.logs.datadoghq.eu/api/v2/logs
 *          US:      https://http-intake.logs.datadoghq.com/api/v2/logs
 *          US3:     https://http-intake.logs.us3.datadoghq.com/api/v2/logs
 *          US5:     https://http-intake.logs.us5.datadoghq.com/api/v2/logs
 *          US1-FED: https://http-intake.logs.ddog-gov.com/api/v2/logs
 * - "apiKey": (mandatory) Your Datadog API key.
 * - "service": (optional) The name of your running website or webservice.
 *
 * Example:
 * <code>
 * {
 *     // temma.json file
 *     ...
 *     // Datadog configuration
 *     "x-datadog": {
 *         "url": "https://http-intake.logs.datadoghq.eu/api/v2/logs",
 *         "apiKey": "...API_KEY...",
 *         "service": "temma.net"
 *     }
 * }
 *
 * It is recommended to create two facets in the Datadog interface, and add these
 * facet as columns in the log table:
 * - traceId: Four characters-long identifier, used to follow log traces from the same process.
 * - class:   Used to know which part of your application as written the log trace.
 * </code>
 */
class Datadog implements \Temma\Base\Loadable, \Temma\Web\LogManager {
	/** Datadog API connection URL. */
	private ?string $_url;
	/** Datadog API key. */
	private ?string $_apiKey;
	/** Datadog service. */
	private ?string $_service;

	/** Constructor. */
	public function __construct(\Temma\Base\Loader $loader) {
		// retrieving Datadog connection info from configuration
		$this->_url = $loader->config->xtra('datadog', 'url');
		$this->_apiKey = $loader->config->xtra('datadog', 'apiKey');
		$this->_service = $loader->config->xtra('datadog', 'service');
	}
	/** Sends the application logs to Datadog. */
	public function log(string $traceId, string $text,
	                    ?string $priority, ?string $class) : void {
		// check connection parameters
		if (!$this->_url || !$this->_apiKey)
			return;
		// creation of the request
		$ctx = stream_context_create([
			'http' => [
				'method'  => 'POST',
				'header'  => "Content-Type: application/json\r\n" .
				             "DD-API-KEY: {$this->_apiKey}",
				'content' => json_encode([
					'message'  => $text,
					'hostname' => gethostname(),
					'ddtags'   => "traceId:$traceId,class:$class",
					'status'   => ($priority ?? 'Info'),
					'service'  => $this->_service,
				]),
			],
		]);
		// send to Datadog
		file_get_contents($this->_url, false, $ctx);
	}
}

