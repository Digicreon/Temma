<?php

/**
 * Syslog
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2022-2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/log-syslog
 */

namespace Temma\LogManagers;

use \Temma\Base\Log as TµLog;

/**
 * Log manager that sends messages to the local Syslog server.
 */
class Syslog implements \Temma\Base\Loadable, \Temma\Web\LogManager {
	/** Syslog priorities mapping table. */
	const PRIO_MAPPING = [
		'DEBUG' => LOG_DEBUG,
		'INFO'  => LOG_INFO,
		'NOTE'  => LOG_NOTICE,
		'WARN'  => LOG_WARNING,
		'ERROR' => LOG_ERR,
		'CRIT'  => LOG_CRIT,
	];
	/** Syslog facilities mapping table. */
	const FACILITY_MAPPING = [
		'LOG_LOCAL0' => LOG_LOCAL0,
		'LOG_LOCAL1' => LOG_LOCAL1,
		'LOG_LOCAL2' => LOG_LOCAL2,
		'LOG_LOCAL3' => LOG_LOCAL3,
		'LOG_LOCAL4' => LOG_LOCAL4,
		'LOG_LOCAL5' => LOG_LOCAL5,
		'LOG_LOCAL6' => LOG_LOCAL6,
		'LOG_LOCAL7' => LOG_LOCAL7,
	];

	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	Dependencies injection component.
	 */
	public function __construct(\Temma\Base\Loader $loader) {
		$facility = LOG_USER;
		if (($facility = $loader->config->xtra('syslog', 'facility')))
			$facility = self::FACILITY_MAPPING[$facility] ?? LOG_USER;
		openlog('', LOG_PERROR, $facility);
	}
	/**
	 * Sends the application logs to the local Syslog server.
	 * @param	string	$traceId	Request identifier.
	 * @param	string	$text		Log text.
	 * @param	?string	$priority	Priority of the log message.
	 * @param	?string	$class		Class of the log message.
	 */
	public function log(string $traceId, string $text,
	                    ?string $priority, ?string $class) : void {
		// creation of the message
		$message = "[$traceId] ";
		if ($class)
			$message .= "($class) ";
		$message .= $text;
		// send to syslog
		syslog((self::PRIO_MAPPING[$priority] ?? LOG_NOTICE), $message);
	}
}

