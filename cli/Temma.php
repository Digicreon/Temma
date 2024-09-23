<?php

/**
 * Temma
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Term as TµTerm;
use \Temma\Utils\File as TµFile;

/**
 * Temma management command.
 */
class Temma extends \Temma\Web\Controller {
	/**
	 * Shows Temma information.
	 */
	public function info() {
		print(TµAnsi::bold("Temma version:  ") . \Temma\Web\Framework::TEMMA_VERSION . "\n");
		if (class_exists('\Smarty\Smarty'))
			print(TµAnsi::bold("Smarty version: ") . \Smarty\Smarty::SMARTY_VERSION . "\n");
	}
	/**
	 * Update the current Temma installation.
	 * @param	string		$version	(optional) Desired version of Temma (defaults to 'stable').
	 *						- 'stable': last stable release
	 *						- 'latest': last commited sources
	 *						- 'X.Y.Z' : specific version
	 * @param	bool|string	$force		(optional) True to force update (defaults to false).
	 */
	public function update(string $version='stable', bool|string $force=false) {
		$tmpPath = $tgzPath = null;
		$returnStatus = 0;
		if (is_string($force))
			$force = (!strcasecmp($force, 'true') || !strcasecmp($force, 'yes'));
		try {
			if ($version == 'latest')
				$version = 'main';
			else {
				// fetch all tags
				$ctx = [
					'http' => [
						'method' => 'GET',
						'header' => [
							'User-Agent: Temma-update-' . \Temma\Web\Framework::TEMMA_VERSION,
						],
					],
				];
				$ctx = stream_context_create($ctx);
				$data = file_get_contents('https://api.github.com/repos/Digicreon/Temma/tags', false, $ctx);
				$data = json_decode($data, true);
				$tags = [];
				if (!is_array($data))
					throw new \Exception("Unable to fetch Temma's list of tags.");
				foreach ($data as $tag) {
					if (($tag['name'] ?? null))
						$tags[] = $tag['name'];
				}
				if (!$tags)
					throw new \Exception("Fetched an empty list of tags.");
				// find requested tag
				if ($version == 'stable')
					$version = $tags[0];
				else if (!in_array($version, $tags))
					throw new \Exception("Unknown version '$version'.");
				// get current version
				$currentVersion = \Temma\Web\Framework::TEMMA_VERSION;
				if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $matches))
					throw new \Exception("Unable to split current version.");
				$currentMajor = $matches[1] ?? null;
				$currentMinor = $matches[2] ?? null;
				$currentPatch = $matches[3] ?? null;
				if (is_null($currentMajor) || is_null($currentMinor) || is_null($currentPatch))
					throw new \Exception("Bad current version number.");
				// check requested tag
				if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches))
					throw new \Exception("Unable to split requested version.");
				$versionMajor = $matches[1] ?? null;
				$versionMinor = $matches[2] ?? null;
				$versionPatch = $matches[3] ?? null;
				if (is_null($versionMajor) || is_null($versionMinor) || is_null($versionPatch))
					throw new \Exception("Bad requested version number.");
				if (!$force &&
				    ($versionMajor < $currentMajor ||
				     ($versionMajor == $currentMajor && $versionMinor < $currentMinor) ||
				     ($versionMajor == $currentMajor && $versionMinor == $currentMinor && $versionPatch < $currentPatch)))
					throw new \Exception("Trying to downgrade from '$currentVersion' to '$version'. Use --force.");
				if (!$force && $versionMajor > $currentMajor)
					throw new \Exception("Trying to update over a major version, from '$currentVersion' to '$version'. Use --force.");
			}
			// get archive file
			$url = "https://codeload.github.com/Digicreon/Temma/tar.gz/$version";
			$tmpPath = tempnam(sys_get_temp_dir(), 'temma-tgz-');
			unlink($tmpPath);
			$tgzPath = "$tmpPath.tar.gz";
			if (!copy($url, $tgzPath))
				throw new \Exception("Unable to fetch source archive file ($url).");
			// untar
			$phar = new PharData($tgzPath);
			if (!$phar->extractTo($tmpPath))
				throw new \Exception("Unable to uncompress archive '$tgzPath' to '$tmpPath'.");
			/* Copie des arborescences. */
			$prefix = "Temma-$version";
			// bin/comma
			$this->_copyFilesFromDir('bin', "$tmpPath/$prefix/bin", $this->_config->appPath . '/bin', 'comma', $force);
			// www/index.php
			$this->_copyFilesFromDir('www', "$tmpPath/$prefix/www", $this->_config->appPath . '/www', 'index.php', $force);
			// cli/
			$this->_copyFilesFromDir('cli', "$tmpPath/$prefix/cli", $this->_config->appPath . '/cli', null, $force);
			// tests/
			$this->_copyFilesFromDir('tests', "$tmpPath/$prefix/tests", $this->_config->appPath . "/tests", null, $force);
			// lib/smarty-plugins/
			$this->_copyFilesFromDir('lib/smarty-plugins', "$tmpPath/$prefix/lib/smarty-plugins", $this->_config->appPath . "/lib/smarty-plugins", null, $force);
			// lib/Temma
			$this->_copyFilesFromDir('lib/Temma', "$tmpPath/$prefix/lib/Temma", $this->_config->appPath . "/lib/Temma", null, $force, sync: true);
		} catch (\Exception $e) {
			print(TµAnsi::color('red', "Error\n"));
			print($e->getMessage() . "\n");
			$returnStatus = 1;
		}
		if ($tmpPath)
			TµFile::recursiveRemove($tmpPath);
		if ($tgzPath)
			unlink($tgzPath);
		return ($returnStatus);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Update one file (or a list of files) in a directory.
	 * @param	string			$dirName	Name of the processed directory.
	 * @param	string			$fromDirPath	Path to the source directory.
	 * @param	string			$toDirPath	Path to the destination directory.
	 * @param	null|string|array	$files		File name or list of file names. Null to copy the full directory.
	 * @param	bool			$force		(optional) True to force behaviour. Defaults to false.
	 * @param	bool			$sync		(optional) True to delete unknown files. Defaults to false.
	 * @throws	\Exception		If an error occured.
	 */
	private function _copyFilesFromDir(string $dirName, string $fromDirPath, string $toDirPath,
	                                   null|string|array $files, bool $force=false, bool $sync=false) : void {
		if (!is_dir($toDirPath)) {
			if (file_exists($toDirPath)) {
				if (!$force) {
					print("A file named '" . TµAnsi::bold($dirName) . "' exists, preventing directory creation.\n");
					print("Continue without updating the '" . TµAnsi::bold($dirName) . "' directory? [y/N] ");
					$answer = TµTerm::input();
					print("\n");
					if (strcasecmp($answer, 'y') && strcasecmp($answer, 'yes'))
						throw new \Exception("A file named '$dirName' exists.");
				}
				return;
			} else if (!$force) {
				print("There is no '" . TµAnsi::bold($dirName) . "' directory in the project.\n");
				print("Do you want to create it? [Y/n] ");
				$answer = TµTerm::input();
				print("\n");
				if ($answer && strcasecmp($answer, 'y') && strcasecmp($answer, 'yes'))
					return;
			}
			if (!mkdir($toDirPath))
				throw new \Exception("Unable to create '$dirName' directory.");
		}
		// no files given, copy the directory
		if (is_null($files)) {
			TµFile::recursiveCopy($fromDirPath, $toDirPath, $force, $sync);
			return;
		}
		// copy the chosen files
		$files = is_array($files) ? $files : [$files];
		foreach ($files as $file) {
			if ($file == '.' || $file == '..')
				continue;
			$fromPath = $fromDirPath . DIRECTORY_SEPARATOR . $file;
			$toPath = $toDirPath . DIRECTORY_SEPARATOR . $file;
			TµFile::recursiveCopy($fromPath, $toPath, $force, $sync);
		}
	}
}

