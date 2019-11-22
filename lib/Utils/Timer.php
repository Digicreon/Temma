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
		$this->_begin = microtime();
		$this->_end = null;
	}
	/** Stops a timing. */
	public function stop() : void {
		$this->_end = microtime();
	}
	/** Resume a timing. */
	public function resume() : void {
		$this->_end = null;
	}
	/**
	 * Returns the elapsed time during a timing.
	 * @return	int	Elapsed time in microseconds.
	 * @throws	\Exception	If the timer wasn't started correctly.
	 */
	public function getTime() : int {
		if (is_null($this->_begin))
			return (0);
		list($uSecondeA, $secondeA) = explode(' ', $this->_begin);
		$end = is_null($this->_end) ? microtime() : $this->_end;
		list($uSecondeB, $secondeB) = explode(' ', $end);
		$total = ($secondeA - $secondeB) + ($uSecondeA - $uSecondeB);
		return (number_format(abs($total), 16));
	}
}

