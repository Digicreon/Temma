<?php

/**
 * Timer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 */

namespace Temma\Utils;

/**
 * Timing object.
 */
class Timer {
	/** Date of timing start. */
	protected $_begin = null;
	/** Date of timing end. */
	protected $_end = null;

	/** Starts a timing. */
	public function start() : void {
		$this->_begin = microtime(true);
		$this->_end = null;
	}
	/** Stops a timing. */
	public function stop() : void {
		$this->_end = microtime(true);
	}
	/** Resume a timing. */
	public function resume() : void {
		$this->_end = null;
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

