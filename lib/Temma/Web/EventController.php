<?php

/**
 * EventController
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2024, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Http as TµHttpException;
use \Temma\Attributes\View as TµView;

/**
 * Enhanced controller object for Server Sent Events management.
 */
#[TµView(false)]
class EventController extends Controller {
	/** Tell if some data has been sent. */
	protected bool $_dataSent = false;
	/** List of used channels. */
	protected array $_channels = [];

	/**
	 * Check if the connection from the client is still alive.
	 * If not, an exception is raised.
	 * @throws	\Temma\Exceptions\FlowQuit	If the connection is closed.
	 */
	protected function _checkConnection() : void {
		if (connection_aborted())
			throw new \Temma\Exceptions\FlowQuit();
	}
	/**
	 * Renew the execution time limit.
	 */
	protected function _renewTimeLimit() : void {
		// check the connection
		$this->_checkConnection();
		// get the configred time limit
		$execTime = ini_get('max_execution_time');
		// quit if there is no limit
		if ($execTime === '0')
			return;
		// set the new time limit
		$execTime = ($execTime === false) ? 30 : $execTime;
		set_time_limit($execTime);
	}
	/**
	 * Send a ping to the client.
	 */
	protected function _ping() : void {
		print("event: ping\r\n");
		print("data: " . json_encode(['time' => date(DATE_ISO8601)]) . "\r\n");
		print("\r\n");
	}

	/* ********** MANAGEMENT OF "TEMPLATE VARIABLES" ********** */
	/**
	 * Send a server event, by defining a value using an array-like syntax (like for template variables).
	 * @param	mixed	$name	Event channel.
	 * @param	mixed	$value	Sent value.
	 * @throws	\Temma\Exceptions\FlowQuit	If the client connection was aborted.
	 */
	public function offsetSet(mixed $name, mixed $value) : void {
		// check the connection
		$this->_checkConnection();
		// if this is the first event, send specific headers first
		if (!$this->_dataSent) {
			header("Content-Type: text/event-stream");
			header('Cache-Control: no-cache');
			header('X-Accel-Buffering: no');
			$this->_dataSent = true;
		}
		// send the event
		print("event: $name\r\n");
		print("data: " . json_encode($value, JSON_THROW_ON_ERROR) . "\r\n");
		print("\r\n");
		ob_flush();
		flush();
		// increment the number ofevents sent for the channel
		if (!isset($this->_channels[$name]))
			$this->_channels[$name] = 1;
		else
			$this->_channels[$name]++;
		// renew the time limit
		$this->_renewTimeLimit();
	}
	/**
	 * Returns the number of events sent for a given channel.
	 * @param	mixed	$name	Event channel..
	 * @return	mixed	The number of events sent for this channel.
	 */
	public function offsetGet(mixed $name) : mixed {
		return ($this->_channels[$name] ?? 0);
	}
	/**
	 * Removes the number of events sent for a given channel.
	 * @param	mixed	$name	Event channel.
	 */
	public function offsetUnset(mixed $name) : void {
		unset($this->_channels[$name]);
	}
	/**
	 * Tell if events have been sent for a given channel.
	 * @param	mixed	$name	Event channel.
	 * @return	bool	True if the channel has already been used, false otherwise.
	 */
	public function offsetExists(mixed $name) : bool {
		return (isset($this->_channels[$name]));
	}
}

