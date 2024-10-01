<?php

/**
 * Log
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Object for log management.
 *
 * <b>Basic usage</b>
 *
 * This object must be manipulated using its static methods, to write in a centralized log file.
 * <code>
 * // basic usage, default INFO criticity level
 * \Temma\Base\Log::log("Log message.");
 * // set the message criticity
 * \Temma\Base\Log::log('WARN', "Warning message.");
 * // call from global context (not from inside a function nor a method)
 * \Temma\Base\Log::fullLog('ERROR', "Error message.", __FILE__, __LINE__);
 * </code>
 *
 * <b>Log thresholds</b>
 *
 * It is possible to define a criticity threshold. All messages with a criticity below this
 * threshold will not be written.
 * There is six criticity levels:
 * - DEBUG : debugging message (lowest criticity)
 * - INFO : informational message (default level for messages without a given criticity)
 * - NOTE : notification; normal but significant message (default threshold)
 * - WARN : alert message; the application doesn't work as it should, but it could continue to run.
 * - ERROR : error message; the application doesn't work as it should and must stop.
 * - CRIT : critical error message; the application may damage its environment (filesystem or database).
 *
 * <code>
 * // definition of the threshold
 * \Temma\Base\Log::setThreshold('INFO');
 * // this message will not be written
 * \Temma\Base\Log::log('DEBUG', "Debug message.");
 * // this message will be written
 * \Temma\Base\Log::log('NOTE', "Notification");
 * </code>
 *
 * The path to the log file is set by calling the \Temma\Base\setLogFile($path) method.
 *
 * <b>Log classes</b>
 *
 * It is possible to group log messages using labels called classes. Each class may have its own criticity threshold.
 * A message without a specified class will be associated to the "default" class.
 * <code>
 * // definition of the default threshold
 * \Temma\Base\Log::setThreshold('ERROR');
 * // this message will not be written
 * \Temma\Base\Log::log('WARN', "Classless message.");
 * // definition of classes and their associated thresholds
 * $thresholds = [
 *         'default' => 'ERROR',
 *         'testing' => 'DEBUG',
 * ];
 * \Temma\Base\Log::setThreshold($thresholds);
 * // add another class, with its associated threshold
 * \Temma\Base\Log::setThreshold('foo', 'INFO');
 * // this message will be written
 * \Temma\Base\Log::log('default', 'ERROR', "Message using the default class.");
 * // this message will be written too
 * \Temma\Base\Log::log('testing', 'CRIT', "Critical message.");
 * </code>
 */
class Log {
	/** Constant - debug message (lowest criticity level). */
	const DEBUG = 'DEBUG';
	/** Constant - informational message (default criticity level). */
	const INFO = 'INFO';
	/** Constant - notification; normal but significant message (default threshold). */
	const NOTE = 'NOTE';
	/** Constant - alert message; the application doesn't work as it should but it could continue to run. */
	const WARN = 'WARN';
	/** Constant - error message; the application doesn't work as it should and it must stop. */
	const ERROR = 'ERROR';
	/** Constant - critical error message; the application may damage its environment (filesystem or database). */
	const CRIT = 'CRIT';
	/** Constant: Nameof the default log class. */
	const DEFAULT_CLASS = 'default';
	/** Constant: array of sorted log levels. */
	const LEVELS = [
		'DEBUG'	=> 10,
		'INFO'	=> 20,
		'NOTE'	=> 30,
		'WARN'	=> 40,
		'ERROR'	=> 50,
		'CRIT'	=> 60
	];
	/** Constant: array of log level text labels. */
	const LABELS = [
		'DEBUG'	=> 'DEBUG',
		'INFO'	=> 'INFO ',
		'NOTE'	=> 'NOTE ',
		'WARN'	=> 'WARN ',
		'ERROR'	=> 'ERROR',
		'CRIT'	=> 'CRIT '
	];
	/** Request identifier. */
	static private ?string $_requestId = null;
	/** Path to the log file. */
	static private ?string $_logPath = null;
	/** List of callback functions that must be called to write custom log messages. */
	static private array $_logCallbacks = [];
	/** Flag for STDOUT writing. */
	static private bool $_logToStdOut = false;
	/** Flag for STDERR writing. */
	static private ?bool $_logToStdErr = null;
	/** Log writing flag. */
	static private bool $_enable = true;
	/** Current threshold. */
	static private array $_threshold = [
		self::DEFAULT_CLASS => self::NOTE,
	];
	/** Threshold for buffered messages. */
	static private ?array $_bufferingThreshold = null;
	/** Buffer of waiting messages. */
	static private ?array $_messageBuffer = null;

	/* ********** PUBLIC METHODS ********** */
	/**
	 * Set the path ot the log file.
	 * @param	string	$path	Path to the log file.
	 */
	static public function setLogFile(string $path) : void {
		self::$_enable = true;
		self::$_logPath = $path;
	}
	/**
	 * Add a callback function, that will be used to write custom log messages.
	 * @param	callable	$func	The callback function.
	 */
	static public function addCallback(callable $func) : void {
		self::$_enable = true;
		self::$_logCallbacks[] = $func;
	}
	/**
	 * Tell if it must write to STDOUT.
	 * @param	bool	$activate	(optional) False to disable writing to STDOUT. True by default.
	 */
	static public function logToStdOut(bool $activate=true) : void {
		self::$_enable = true;
		self::$_logToStdOut = ($activate === false) ? false : true;
	}
	/**
	 * Tell if it must wirte to STDERR.
	 * @param	bool	$activate	(optional) False to disable writing to STDERR. True by default.
	 */
	static public function logToStdErr(bool $activate=true) : void {
		self::$_enable = true;
		self::$_logToStdErr = ($activate === false) ? false : true;
	}
	/** Disable all log writing. */
	static public function disable() : void {
		self::$_enable = false;
	}
	/** Enable log writing. */
	static public function enable() : void {
		self::$_enable = true;
	}
	/**
	 * Define the criticity threshold.
	 * @param	string|array	$classOrThreshold	Name of the class for which the threshold is defined (in the second parameter)
	 *							or value of the default threshold, or list of classes with their associated thresholds.
	 * @param	?string		$threshold		(optional) Threshold value.
	 */
	static public function setThreshold(string|array $classOrThreshold, ?string $threshold=null) : void {
		if (is_string($classOrThreshold) && is_string($threshold))
			self::$_threshold[$classOrThreshold] = $threshold;
		else if (is_array($classOrThreshold))
			self::$_threshold = $classOrThreshold;
		else if (is_string($classOrThreshold))
			self::$_threshold[self::DEFAULT_CLASS] = $classOrThreshold;
	}
	/**
	 * Define the criticity threshold for buffered messages.
	 * By default, messages are not buffered.
	 * @param	null|string|array	$classOrThreshold	Name of the class for which the buffering threshold is defined (in the second parameter)
	 *								or value of the default buffering threshold, or list of classes with their associated thresholds,
	 *								or null to remove message buffering.
	 * @param	?string			$threshold		(optional) Threshold value.
	 */
	static public function setBufferingThreshold(null|string|array $classOrThreshold, ?string $threshold=null) : void {
		if (is_null($classOrThreshold)) {
			self::$_bufferingThreshold = null;
			return;
		}
		self::$_bufferingThreshold = is_array(self::$_bufferingThreshold) ? self::$_bufferingThreshold : [];
		if (is_string($classOrThreshold) && is_string($threshold))
			self::$_bufferingThreshold[$classOrThreshold] = $threshold;
		else {
			if (is_array($classOrThreshold))
				self::$_bufferingThreshold = $classOrThreshold;
			else if (is_string($classOrThreshold))
				self::$_bufferingThreshold[self::DEFAULT_CLASS] = $classOrThreshold;
		}
	}
	/**
	 * Write a log message.
	 * @param	mixed	$classOrMessageOrPriority	Log message (1 param) or criticity level (2 params) or log class (3 params).
	 * @param	mixed	$messageOrPriority		(optional) Log message (2 params) or criticity level (3 params).
	 * @param	mixed	$message			(optional) Log message (3 params).
	 */
	static public function log(mixed $classOrMessageOrPriority, mixed $messageOrPriority=null, mixed $message=null) : void {
		// parameters processing
		if (!is_null($message) && !is_null($messageOrPriority)) {
			$class = $classOrMessageOrPriority;
			$priority = $messageOrPriority;
		} else if (!is_null($messageOrPriority)) {
			$class = self::DEFAULT_CLASS;
			$priority = $classOrMessageOrPriority;
			$message = $messageOrPriority;
		} else {
			$class = self::DEFAULT_CLASS;
			$priority = self::INFO;
			$message = $classOrMessageOrPriority;
		}
		$priority = isset(self::LEVELS[$priority]) ? $priority : self::INFO;
		$writeLog = true;
		$bufferLog = false;
		// the message is not written if its criticity is lower than the defined threshold
		if ((isset(self::$_threshold[$class]) && self::LEVELS[$priority] < self::LEVELS[self::$_threshold[$class]]) ||
		    (!isset(self::$_threshold[$class]) && (!isset(self::$_threshold[self::DEFAULT_CLASS]) || self::LEVELS[$priority] < self::LEVELS[self::$_threshold[self::DEFAULT_CLASS]]))) {
			$writeLog = false;
			// message critivity is too low
			// check if the message must be buffered
			if (isset(self::$_bufferingThreshold[$class]) && self::LEVELS[$priority] >= self::LEVELS[self::$_bufferingThreshold[$class]])
				$bufferLog = true;
		}
		if (!$writeLog && !$bufferLog)
			return;
		// log processing
		if (!is_string($message))
			$message = print_r($message, true);
		$backtrace = debug_backtrace();
		if (is_array($backtrace) && count($backtrace) > 1) {
			$txt = '';
			if (isset($backtrace[0]['file']) && isset($backtrace[0]['line']))
				$txt .= '[' . basename($backtrace[0]['file']) . ':' . $backtrace[0]['line'] . '] ';
			if (isset($backtrace[1]['class']) && isset($backtrace[1]['type']))
				$txt .= $backtrace[1]['class'] . $backtrace[1]['type'];
			if (isset($backtrace[1]['function']))
				$txt .= $backtrace[1]['function'] . "(): ";
			$txt .= $message;
			if ($priority == self::CRIT) {
				$offset = 0;
				foreach ($backtrace as $trace) {
					if (++$offset < 2)
						continue;
					$txt .= "\n\t#" . ($offset - 1) . '  ' . 
						($trace['class'] ?? '') . 
						($trace['type'] ?? '') . 
						$trace['function'] . '() called at [' .
						($trace['file'] ?? '') . ':' . 
						($trace['line'] ?? '') . ']';
				}
			}
			$message = $txt;
		}
		// add message to buffer if needed
		if ($bufferLog) {
			self::$_messageBuffer ??= [];
			self::$_messageBuffer[] = [$class, $priority, $message];
		}
		// quit if the message must not be written
		if (!$writeLog)
			return;
		// write all buffered and the message
		if (self::$_messageBuffer) {
			foreach (self::$_messageBuffer as $log) {
				self::_writeLog($log[0], $log[1], $log[2]);
			}
			self::$_messageBuffer = null;
		}
		self::_writeLog($class, $priority, $message);
	}
	/**
	 * Write a simple log message. This message will always be writtent.
	 * @param	mixed	$message	Log message.
	 */
	static public function l(mixed $message) : void {
		if (!is_string($message))
			$message = print_r($message, true);
		self::_writeLog(null, null, $message);
	}
	/**
	 * Utility function, used to validate a log level string.
	 * @param	mixed	$loglevel	The variable that should be a valid log level.
	 * @return	?string	The upper-case log level, if the input was valid, or null.
	 */
	static public function checkLogLevel(mixed $loglevel) : ?string {
		if (is_string($loglevel) &&
		    ($loglevel = strtoupper($loglevel)) &&
		    isset(self::LEVELS[$loglevel]))
			return ($loglevel);
		return (null);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Write a message into the right media.
	 * @param	string|null	$class			Log class of the message. Could be null.
	 * @param	string|null	$priority		Criticity level of the message. Could be null.
	 * @param	string		$message		Text message.
	 * @throws	\Temma\Exceptions\IO		If there was a writing error.
	 */
	static private function _writeLog(?string $class, ?string $priority, string $message) : void {
		if (!self::$_enable)
			return;
		if (is_null(self::$_requestId)) {
			self::$_requestId = substr(base_convert(bin2hex(random_bytes(3)), 16, 36), 0, 4);
		}
		// create the message
		$text = date('c') . ' [' . self::$_requestId . '] ' . (isset(self::LABELS[$priority]) ? (self::LABELS[$priority] . ' ') : '');
		if (!empty($class) && $class != self::DEFAULT_CLASS)
			$text .= "-$class- ";
		$text .= $message . "\n";
		// check if there is a configured output
		if (!self::$_logPath && !self::$_logToStdOut && is_null(self::$_logToStdErr) && !self::$_logCallbacks) {
			// no configured output: write to stderr
			file_put_contents('php://stderr', $text);
			return;
		}
		// output: callbacks
		foreach (self::$_logCallbacks as $callback) {
			$callback(self::$_requestId, $message, $priority, $class);
		}
		// output: stdout
		if (self::$_logToStdOut)
			print($text);
		// output: stderr
		if (self::$_logToStdErr)
			file_put_contents('php://stderr', $text);
		// output: log file
		if (self::$_logPath) {
			$path = self::$_logPath;
			$flags = (substr($path, 0, 6) != 'php://') ? FILE_APPEND : null;
			if (file_put_contents($path, $text, $flags) === false)
				throw new TµIOException("Unable to write on log file '$path'.", TµIOException::UNWRITABLE);
		}
	}
}

