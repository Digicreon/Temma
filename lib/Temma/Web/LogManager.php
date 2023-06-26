<?php

/**
 * LogManager
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2021-2023, Amaury Bouchard
 */

namespace Temma\Web;

/**
 * Interface for objects that could be used as log managers by Temma's log system.
 */
interface LogManager {
	/**
	 * Receives a log message and handle it.
	 * @param	string	$traceId	Trace identifier.
	 * @param	string	$text		Text of the message.
	 * @param	?string	$priority	Priority of the message (DEBUG, INFO, NOTE, WARN, ERROR, CRIT). Could be null.
	 * @param	?string	$class		Log class of the message.
	 */
	public function log(string $traceId, string $text, ?string $priority, ?string $class) : void;
}
