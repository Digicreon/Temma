<?php

/**
 * Log
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Base;

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
	/** Constant - alert message; the applicatino doesn't work as it should but it could continue to run. */
	const WARN = 'WARN';
	/** Constant - error message; the application doesn't work as it should and it must stop. */
	const ERROR = 'ERROR';
	/** Constant - critical error message; the application may damage its environment (filesystem or database). */
	const CRIT = 'CRIT';
	/** Nameof the default log class. */
	const DEFAULT_CLASS = 'default';
	/** Request identifier. */
	static private $_requestId = null;
	/** Path to the log file. */
	static private $_logPath = null;
	/** List of callback functions that must be called to write custom log messages. */
	static private $_logCallbacks = [];
	/** Flag for STDOUT writing. */
	static private $_logToStdOut = false;
	/** Flag for STDERR writing. */
	static private $_logToStdErr = false;
	/** Log writing flag. */
	static private $_enable = true;
	/** Current threshold. */
	static private $_threshold = [
		self::DEFAULT_CLASS	=> self::NOTE,
	];
	/** Array of sorted log levels.*/
	static private $_levels = [
		'DEBUG'	=> 10,
		'INFO'	=> 20,
		'NOTE'	=> 30,
		'WARN'	=> 40,
		'ERROR'	=> 50,
		'CRIT'	=> 60
	];
	/** Array of log level text labels. */
	static private $_labels = [
		'DEBUG'	=> 'DEBUG',
		'INFO'	=> 'INFO ',
		'NOTE'	=> 'NOTE ',
		'WARN'	=> 'WARN ',
		'ERROR'	=> 'ERROR',
		'CRIT'	=> 'CRIT '
	];

	/* ******************** PUBLIC METHODS ****************** */
	/**
	 * Set the path ot the log file.
	 * @param	string	path	Path to the log file.
	 */
	static public function setLogFile(string $path) : void {
		self::$_enable = true;
		self::$_logPath = $path;
	}
	/**
	 * Add a callback function, that will be used to write custom log messages.
	 * @param	\Closure	$func	The callback function.
	 */
	static public function addCallback(\Closure $func) : void {
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
	 * @param	string		$threshold		(optional) Threshold value.
	 */
	static public function setThreshold(/* mixed */ $classOrThreshold, ?string $threshold=null) : void {
		if (is_string($classOrThreshold) && is_string($threshold))
				self::$_threshold[$classOrThreshold] = $threshold;
		else {
			if (is_array($classOrThreshold))
				self::$_threshold = $classOrThreshold;
			else if (is_int($classOrThreshold))
				self::$_threshold[self::DEFAULT_CLASS] = $classOrThreshold;
		}
	}
	/**
	 * Write a log message.
	 * @param	mixed	$classOrMessageOrPriority	Log message (1 param) or criticity level (2 params) or log class (3 params).
	 * @param	mixed	$messageOrPriority		(optional) Log message (2 params) or criticity level (3 params).
	 * @param	string	$message			(optional) Log message (3 params).
	 */
	static public function log(/* mixed */ $classOrMessageOrPriority, /* mixed */ $messageOrPriority=null, ?string $message=null) : void {
		if (is_null(self::$_requestId)) {
			self::$_requestId = substr(base_convert(hash('md5', mt_rand()), 16, 36), 0, 4);
		}
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
		// the message is not written if its criticity is lower than the defined threshold
		if ((isset(self::$_threshold[$class]) && self::$_levels[$priority] < self::$_levels[self::$_threshold[$class]]) ||
		    (!isset(self::$_threshold[$class]) && (!isset(self::$_threshold[self::DEFAULT_CLASS]) || self::$_levels[$priority] < self::$_levels[self::$_threshold[self::DEFAULT_CLASS]])))
			return;
		// log processing
		$backtrace = debug_backtrace();
		if (is_array($backtrace) && count($backtrace) > 1) {
			$txt = '';
			if (isset($backtrace[1]['file']) && isset($backtrace[1]['line']))
				$txt .= '[' . basename($backtrace[1]['file']) . ':' . $backtrace[1]['line'] . '] ';
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
						(isset($trace['class']) ? $trace['class'] : '') . 
						(isset($trace['type']) ? $trace['type'] : '') . 
						(isset($trace['function']) ? $trace['function'] : '') . '() called at [' .
						(isset($trace['file']) ? $trace['file'] : '') . ':' . 
						(isset($trace['line']) ? $trace['line'] : '') . ']';
				}
			}
			$message = $txt;
		}
		self::_writeLog($class, $priority, $message);
	}
	/**
	 * Write a simple log message. This message will always be writtent.
	 * @param	string	$message	Log message.
	 */
	static public function l(string $message) : void {
		self::_writeLog(null, null, $message);
	}
	/**
	 * Write a detailed log message.
	 * The first parameter (log class) if optional.
	 * @param	string		$classOrPriority	Log class or criticity level.
	 * @param	string		$priorityOrMessage	Criticity level or log message.
	 * @param	string		$messageOrFile		Log message of the name of the file from where the method was called.
	 * @param	string|int	$fileOrLine		File name or line number where the method was called.
	 * @param	int|string	$lineOrCaller		Number of the line where the method was called, or the name of the caller function.
	 * @param	string		$caller			(optional) Name of the caller function.
	 */
	static public function fullLog(string $classOrPriority, string $priorityOrMessage, string $messageOrFile,
	                               /* mixed */ $fileOrLine, /* mixed */ $lineOrCaller, ?string $caller=null) : void {
		// parameters processing
		if (is_null($caller)) {
			// 5 parameters: no log class
			$caller = $lineOrCaller;
			$line = $fileOrLine;
			$file = $messageOrFile;
			$message = $priorityOrMessage;
			$priority = $classOrPriority;
			$class = self::DEFAULT_CLASS;
		} else {
			// 6 parameters: log class given
			$line = $lineOrCaller;
			$file = $fileOrLine;
			$message = $messageOrFile;
			$priority = $priorityOrMessage;
			$class = $classOrPriority;
		}
		// the message is not written if its criticity level if lower than the defined theshold
		if ((isset(self::$_threshold[$class]) && self::$_levels[$priority] < self::$_levels[self::$_threshold[$class]]) ||
		    (!isset(self::$_threshold[$class]) && self::$_levels[$priority] < self::$_levels[self::$_threshold[self::DEFAULT_CLASS]]))
			return;
		// traitement
		$txt = '[' . basename($file) . ":$line]";
		if (!empty($caller))
			$txt .= " $caller()";
		self::_writeLog($class, $priority, "$txt: $message");
	}

	/* ********************** PRIVATE METHODS *************** */
	/**
	 * Write a message into the right media.
	 * @param	string|null	$class			Log class of the message. Could be null.
	 * @param	string|null	$priority		Criticity level of the message. Could be null.
	 * @param	string		$message		Text message.
	 * @throws	\Temma\Exceptions\ApplicationException	If no log file was defined.
	 * @throws	\Temma\Exceptions\IOException		If there was a writing error.
	 */
	static private function _writeLog(?string $class, ?string $priority, string $message) : void {
		if (!self::$_enable)
			return;
		// open the file if needed
		if (isset(self::$_logPath) && !empty(self::$_logPath))
			$path = self::$_logPath;
		else if (!self::$_logToStdOut && !self::$_logToStdErr && empty(self::$_logCallbacks))
			throw new \Temma\Exceptions\ApplicationException('No log file set.', \Temma\Exceptions\ApplicationException::API);
		$text = date('c') . ' [' . self::$_requestId . '] ' . (isset(self::$_labels[$priority]) ? (self::$_labels[$priority] . ' ') : '');
		if (!empty($class) && $class != self::DEFAULT_CLASS)
			$text .= "-$class- ";
		$text .= $message . "\n";
		if (isset($path))
			if (file_put_contents($path, $text, (substr($path, 0, 6) != 'php://' ? FILE_APPEND : null)) === false)
				throw new \Temma\Exceptions\IOException("Unable to write on log file '$path'.", \Temma\Exceptions\IOException::UNWRITABLE);
		if (self::$_logToStdOut)
			print($text);
		if (self::$_logToStdErr)
			fwrite(STDERR, $text);
		foreach (self::$_logCallbacks as $callback)
			$callback($message, self::$_labels[$priority], $class);
	}
}

