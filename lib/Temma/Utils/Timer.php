<?php

/**
 * Timer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-timer
 */

namespace Temma\Utils;

/**
 * Timing object.
 *
 * ```
 * // creates a timer
 * $timer = new \Temma\Utils\Timer();
 * // starts the timer
 * $timer->start();
 * // stops the timer
 * $timer->stop();
 * // show the duration
 * print($timer->getTime());
 * ```
 */
class Timer {
	/** Date of timing start. */
	protected ?float $_begin = null;
	/** Date of timing end. */
	protected ?float $_end = null;
	/** already elapsed time. */
	protected float $_elapsed = 0.0;

	/**
	 * Starts a timer.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function start() : \Temma\Utils\Timer {
		$this->_begin = microtime(true);
		$this->_end = null;
		$this->_elapsed = 0.0;
		return ($this);
	}
	/**
	 * Stops a timer.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function stop() : \Temma\Utils\Timer {
		$this->_end = microtime(true);
		return ($this);
	}
	/**
	 * Resume a timer.
	 * @return	\Temma\Utils\Timer	The current instance.
	 */
	public function resume() : \Temma\Utils\Timer {
		$this->_elapsed = $this->getTime();
		$this->_begin = microtime(true);
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
		$total = ($end - $this->_begin) + $this->_elapsed;
		return ($total);
	}
}

