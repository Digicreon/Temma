<?php

/**
 * Lock
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2008-2019, Amaury Bouchard
 */

namespace Temma\Utils;

/**
 * Lock management object.
 *
 * By default, this object try to create a lock for the currently running PHP script.
 * But it is possible to explicitly ask for another file locking.
 *
 * Example of a classic usage, to prevent concurrent execution of the script:
 * <code>
 * $lock = new \Temma\Utils\Lock();
 * try {
 *     // creation of the lock
 *     $lock->lock();
 *     // some processings
 *     ...
 *     // release the lock
 *     $lock->unlock();
 * } catch (\Temma\Exceptions\IOException $e) {
 *     // input-output error, could be thrown by the lock
 * } catch (\Exception $e) {
 *     // error
 * }
 * </code>
 * If the file is already locked, the object checks for how long. If the duration is greater
 * than the default timeout (10 minutes), it checks if the lock creator process is still
 * alive. If not, it tries to unlock and relock.
 */
class Lock {
	/** Constant - suffix added to the lock file names. */
	const LOCK_SUFFIX = ".lck";
	/** Constant - duration of the default lock timeout, in seconds. 10 minutes by default. */
	const LOCK_TIMEOUT = 600;
	/** Lock file's handler. */
	protected $_fileHandle = null;
	/** Path to the lock file. */
	protected $_lockPath = null;

	/**
	 * Creation of a lock.
	 * @param	string	$path		(optional) Path to the file to lock.
	 *					If not set, try to lock the current PHP script.
	 * @param	int	$timeout	(optional) Lock duration, in seconds.
	 * @throws	\Temma\Exceptions\IOException	If the file can't be locked.
	 */
	public function lock(?string $path=null, ?int $timeout=null) : void {
		$filePath = is_null($path) ? $_SERVER['SCRIPT_FILENAME'] : $path;
		$lockPath = $filePath . self::LOCK_SUFFIX;
		$this->_lockPath = $lockPath;
		if (!($this->_fileHandle = fopen($this->_lockPath, "a+"))) {
			$lockPath = $this->_lockPath;
			$this->_reset();
			throw new \Temma\Exceptions\IOException("Unable to open file '$lockPath'.", \Temma\Exceptions\IOException::UNREADABLE);
		}
		if (!flock($this->_fileHandle, LOCK_EX + LOCK_NB)) {
			// unable to lock the file: checkits age
			if (($stat = stat($this->_lockPath)) !== false) {
				$usedTimeout = is_null($timeout) ? self::LOCK_TIMEOUT : $timeout;
				if (($stat['ctime'] + $usedTimeout) < time()) {
					// the timeout has expire: check if the process still exists
					$pid = trim(file_get_contents($this->_lockPath));
					$cmd = 'ps -p ' . escapeshellarg($pid) . ' | wc -l';
					$nbr = trim(shell_exec($cmd));
					if ($nbr < 2) {
						// the process doesn't exist anymore: try to unlock and relock
						$this->unlock();
						$this->lock($filePath, $usedTimeout);
						return;
					}
				}
			}
			fclose($this->_fileHandle);
			$this->_reset();
			throw new \Temma\Exceptions\IOException("Unable to lock file '$lockPath'.", \Temma\Exceptions\IOException::UNLOCKABLE);
		}
		// lock OK: write the PID inside of it
		ftruncate($this->_fileHandle, 0);
		fwrite($this->_fileHandle, getmypid());
	}
	/**
	 * Release a lock.
	 * @throws	\Temma\Exceptions\IOException	If something went wrong.
	 */
	public function unlock() : void {
		if (is_null($this->_fileHandle) || is_null($this->_lockPath)) {
			throw new \Temma\Exceptions\IOException("No file to unlock.", \Temma\Exceptions\IOException::NOT_FOUND);
		}
		flock($this->_fileHandle, LOCK_UN);
		if (!fclose($this->_fileHandle)) {
			$this->_reset();
			throw new \Temma\Exceptions\IOException("Unable to close lock file.", \Temma\Exceptions\IOException::FUNDAMENTAL);
		}
		if (!unlink($this->_lockPath)) {
			$this->_reset();
			throw new \Temma\Exceptions\IOException("Unable to delete lock file.", \Temma\Exceptions\IOException::FUNDAMENTAL);
		}
		$this->_reset();
	}

	/* ********** PRIVATE METHODS ********** */
	/** Flush the private attributes. */
	protected function _reset() : void {
		$this->_fileHandle = null;
		$this->_lockPath = null;
	}
}

