<?php

/**
 * File.
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-file
 */

namespace Temma\Utils;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Object used to manage files and folders.send emails.
 */
class File {
	/**
	 * Recursively copy the content of a file or a directory.
	 * @param	string	$from	Source.
	 * @param	string	$to	Destination.
	 * @param	bool	$force	(optional) If true, remove files, symlinks and directories with the same name but a different type
	 *				than a copied element (default to false).
	 * @param	bool	$sync	(optional) If true, remove unkown files from destination (defaults to false).
	 * @param	bool	$hidden	(optional) If true, copy files and directories starting with a dot (defaults to false).
	 * @throws	\Temma\Exceptions\IO	If a copy failed.
	 */
	static public function recursiveCopy(string $from, string $to, bool $force=false, bool $sync=false, bool $hidden=false) : void {
		// remove unknown files
		if ($sync && is_dir($to)) {
			if (($dir = opendir($to)) === false)
				throw new TµIOException("Unable to open directory '$to'.", TµIOException::UNREADABLE);
			while (($item = readdir($dir))) {
				if ($item == '.' || $item == '..' ||
				    (!$hidden && str_starts_with($item, '.')))
					continue;
				$fromItem = $from . DIRECTORY_SEPARATOR . $item;
				$toItem = $to . DIRECTORY_SEPARATOR . $item;
				if (is_dir($toItem) && !is_dir($fromItem))
					self::recursiveRemove($toItem);
				else if (((is_file($toItem) && !is_file($fromItem)) ||
				          (is_link($toItem) && !is_link($fromItem))) &&
					 !unlink($toItem))
					throw new TµIOException("Unable to remove file '$toItem'.", TµIOException::FUNDAMENTAL);
			}
			closedir($dir);
		}
		// recreate a symlink
		if (is_link($from)) {
			// end of processing if a file or a dir exists and we don't force the copy
			if (file_exists($to) && !is_link($to) && !$force)
				return;
			// remove existing file or link with the destination name
			if ((is_file($to) || is_link($to)) && !unlink($to))
				throw new TµIOException("Unable to remove '$to'.", TµIOException::FUNDAMENTAL);
			// remove existing directory with the destination name
			if (is_dir($to))
				self::recursiveRemove($to);
			// read the source link to get the target
			if (!($target = readlink($from)))
				throw new TµIOException("Unable to read link '$from'.", TµIOException::UNREADABLE);
			// create the destination link
			if (!symlink($target, $to))
				throw new TµIOException("Unable to create link '$to' linking to '$target'.", TµIOException::UNWRITABLE);
			return;
		}
		// copy a file
		if (is_file($from)) {
			// enid of processing if a symlink or a dir exists and we don't force the copy
			if (file_exists($to) && !is_file($to) && !$force)
				return;
			// remove existing link with the destination name)
			if (is_link($to) && !unlink($to))
				throw new TµIOException("Unable to remove '$to'.", TµIOException::FUNDAMENTAL);
			// remove existing directory with the destination name
			if (is_dir($to))
				self::recursiveRemove($to);
			// copy the file
			if (!copy($from, $to))
				throw new TµIOException("Unable to copy '$from' to '$to'.", TµIOException::FUNDAMENTAL);
			return;
		}
		// check source
		if (!is_dir($from))
			throw new TµIOException("Unknown file type '$from'.", TµIOException::BAD_FORMAT);
		// remove and create destination if needed
		if (!is_dir($to)) {
			if (file_exists($to)) {
				if (!$force)
					return;
				if (!unlink($to))
					throw new TµIOException("Unable to remove file '$to'.", TµIOException::FUNDAMENTAL);
			}
			if (!mkdir($to, recursive: true))
				throw new TµIOException("Unable to create directory '$to'.", TµIOException::UNWRITABLE);
		}
		// copy the directory content
		if (($dir = opendir($from)) === false)
			throw new TµIOException("Unable to open directory '$from'.", TµIOException::UNREADABLE);
		while (($item = readdir($dir))) {
			if ($item == '.' || $item == '..' ||
			    ($hidden && str_starts_with($item, '.')))
				continue;
			$fromItem = $from . DIRECTORY_SEPARATOR . $item;
			$toItem = $to . DIRECTORY_SEPARATOR . $item;
			self::recursiveCopy($fromItem, $toItem, $force, $sync, $hidden);
		}
		closedir($dir);
	}
	/**
         * Remove a directory and its content.
         * @param       string  $path   Path to the directory.
	 * @throws	\Temma\Exceptions\IO	If an error occurred.
         */
        static public function recursiveRemove(string $path) : void {
		// remove file/symlink
                if (!is_dir($path)) {
			if (!unlink($path))
				throw new TµIOException("Unable to remove '$path'.", TµIOException::FUNDAMENTAL);
			return;
		}
		// remove directory content
		if (($dir = opendir($path)) === false)
			throw new TµIOException("Unable to open directory '$path'.", TµIOException::UNREADABLE);
		while (($item = readdir($dir))) {
			if ($item == '.' || $item == '..')
				continue;
			self::recursiveRemove($path . DIRECTORY_SEPARATOR . $item);
                }
		closedir($dir);
		// remove directory
                rmdir($path);
        }
}

