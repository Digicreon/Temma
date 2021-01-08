<?php

/**
 * LogManager
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2021, Amaury Bouchard
 */

namespace Temma\Web;

/**
 * Interface for objects that could be used as log managers by Temma's log system.
 */
interface LogManager {
	/**
	 * Receives a log message and handle it.
	 * @param	string	$text		Text of the message.
	 * @param	?string	$priority	Priority of the message (DEBUG, INFO, NOTE, WARN, ERROR, CRIT). Could be null.
	 * @param	?string	$class		Log class of the message.
	 * @return	mixed	Several return values are possible:
	 *			- nothing or null: Continue the processing as usual.
	 *			- false: Stop all log processing (no other log managers and no writing to file).
	 *			- associative array: May change some features
	 *				- logPath: Change to path to the log file (muts be an absolute path).
	 *				- logToStdOut: Set to true to force log output to the standard output.
	 *				- logToStdErr: Set to true to force log output to the error output.
	 */
	public function log(string $text, ?string $priority, ?string $class);
}
