<?php

/**
 * Timer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 *
 * <code>
 * // creates a timer
 * $timer = new \Temma\Utils\Timer();
 * // starts the timer
 * $timer->start();
 * // stops the timer
 * $timer->stop();
 * // show the duration
 * print($timer->getTime());
 * </code>
 */

namespace Temma\Utils;

/**
 * Timing object.
 */
class Timer {
	/** Date of timing start. */
	protected ?float $_begin = null;
	/** Date of timing end. */
	protected ?float $_end = null;

	/**
	 * Starts a timing.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function start() : \Temma\Utils\Timer {
		$this->_begin = microtime(true);
		$this->_end = null;
		return ($this);
	}
	/**
	 * Stops a timing.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function stop() : \Temma\Utils\Timer {
		$this->_end = microtime(true);
		return ($this);
	}
	/**
	 * Resume a timing.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function resume() : \Temma\Utils\Timer {
		$this->_end = null;
		return ($this);
	}
	/**
	 * Returns the elapsed time during a timing.
	 * @return	float	Elapsed time in seconds.
	 * @throws	\Exception	If the timer wasn't started correctly.
	 */
	public function getTime() : float {
		if (is_null($this->_begin))
			return (0);
		$end = $this->_end ?? microtime(true);
		$total = $end - $this->_begin;
		return ($total);
	}
}

