<?php

/**
 * Cache
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-cli_cache
 */

namespace Temma\Cli;

use \Temma\Utils\Ansi as TµAnsi;

/**
 * Cache management CLI controller.
 */
class Cache extends \Temma\Web\Controller {
	/**
	 * Clear the cache.
	 * @param	string	$datasource	Name of the data source to purge. (defaults to "cache")
	 */
	public function clear(string $datasource='cache') {
		$cache = $this->$datasource;
		if (!$cache) {
			print(TµAnsi::color('red', "No '$datasource' data source.\n"));
			exit(1);
		}
		$cache->flush();
		print(TµAnsi::color('green', "Cache cleared.\n"));
	}
}

