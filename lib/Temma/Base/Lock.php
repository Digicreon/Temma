<?php

/**
 * Lock
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2021, Amaury Bouchard
 */

namespace Temma\Base;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Object for lock management.
 *
 * This object may be used to ensure that only one program has access to the same resource,
 * or a program could be executed only once at a time.
 * <code>
 * // basic usage: try to lock the execution of the current program
 * if (!\Temma\Base\Lock::lock(__FILE__)) {
 *     // the program is already in use
 * }
 *
 * // lock the access to a given file
 * // if the file is already locked, wait until it is available
 * try {
 *     $lock = new \Temma\Base\Lock('/path/to/file');
 * } catch (\Temma\Exceptions\IO $eio) {
 *     // an error occurred
 * }
 * // use the resource
 * ...
 * // release the lock
 * $lock->unlock();
 * </code>
 */
class Lock {
	/** Opened semaphore. */
	private $_semaphore = null;

	/**
	 * Contructor. Must be used when a resource need to be locked but not until the end of the program's execution.
	 * @param	string	$path		Path to the file to lock.
	 * @param	bool	$blocking	(optional) Set to true to block until the resource is available, or to false to get an immediate response. (default: true)
	 */
	public function __construct(string $path, bool $blocking=true) {
		TµLog::log('Temma/Base', 'DEBUG', "Lock file '$path'.");
		// get file's inode
		$inode = fileinode($path);
		if (!$inode) {
			TµLog::log('Temma/Base', 'WARN', "Unable to get inode of file '$path'.");
			throw new TµIOException("Unable to get inode of file '$path'.", TµIOException::UNLOCKABLE);
		}
		// create semaphore
		if (($this->_semaphore = sem_get($inode)) === false) {
			$this->_semaphore = null;
			TµLog::log('Temma/Base', 'WARN', "Unable to get semaphore for file '$path'.");
			throw new TµIOException("nable to get semaphore for file '$path'.", TµIOException::UNLOCKABLE);
		}
		// acquire semaphre
		$result = sem_acquire($this->_semaphore, $blocking);
		TµLog::log('Temma/Base', 'DEBUG', "Lock result: " . ($result ? 'success' : 'failure') . ".");
	}
	/** Unlock a locked file. */
	public function unlock() {
		sem_release($this->_semaphore);
	}
	/**
	 * Lock the access to the given file, until the end of the current program's execution.
	 * @param	string	path		Path to the file to lock.
	 * @param	bool	$blocking	(optional) Set to true to block until the resource is available. (default: false)
	 * @return	bool	True if the resource has been successfully locked, false if it was already locked.
	 * @throws	\Temma\Exceptions\IO	If an error occurs.
	 */
	static public function lock(string $path, bool $blocking=false) : bool {
		TµLog::log('Temma/Base', 'DEBUG', "Lock file '$path'.");
		// get file's inode
		$inode = fileinode($path);
		if (!$inode) {
			TµLog::log('Temma/Base', 'WARN', "Unable to get inode of file '$path'.");
			return (false);
		}
		// create semaphore
		$semaphore = sem_get($inode);
		if ($semaphore === false) {
			TµLog::log('Temma/Base', 'WARN', "Unable to get semaphore for file '$path'.");
			return (false);
		}
		// acquire semaphre
		$result = sem_acquire($semaphore, $blocking);
		TµLog::log('Temma/Base', 'DEBUG', "Lock result: " . ($result ? 'success' : 'failure') . ".");
		return ($result);
	}
}

