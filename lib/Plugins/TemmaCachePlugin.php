<?php

/**
 * Plugin de gestion des URL en cache.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @subpackage	Plugins
 * @see		http://www.temma.net/fr/extensions/doc/TemmaCachePlugin/index.html
 */
class TemmaCachePlugin extends \Temma\Controller {
	/**
	 * Méthode plugin. Définis si la page courante peut être mise en cache.
	 * @return	mixed	Toujours EXEC_FORWARD.
	 */
	public function preplugin() {
		FineLog::log('temma', FineLog::INFO, "TemmaCachePlugin started.");
		// vérifications de base
		if (!empty($_SERVER['QUERY_STRING']) || $_SERVER['REQUEST_METHOD'] != 'GET') {
			FineLog::log('temma', FineLog::DEBUG, "Unable to cache a request with parameters.");
			return (self::EXEC_FORWARD);
		}
		// charge la configuration du cache
		$cacheable = $this->_config->xtra('temma-cache');
		$dataSource = $cacheable['source'];
		if (empty($dataSource))
			return;
		$cache = $this->_dataSources[$dataSource];
		if (!isset($cache))
			return;
		// vérification de non-mise en cache
		if (isset($cacheable['sessionNoCache'])) {
			if (!is_array($cacheable['sessionNoCache']))
				$cacheable['sessionNoCache'] = [$cacheable['sessionNoCache']];
			foreach ($cacheable['sessionNoCache'] as $varName) {
				if (($sessionData = $this->_session->get($varName)) && $sessionData) {
					FineLog::log('temma', FineLog::DEBUG, "Cache disabled by session variable '$varName'.");
					return;
				}
			}
		}
		// vérification URL
		$isCacheable = false;
		if (in_array($_SERVER['REQUEST_URI'], $cacheable['url'])) {
			FineLog::log('temma', FineLog::DEBUG, "Strict URL found.");
			$isCacheable = true;
		} else {
			foreach ($cacheable['prefix'] as $prefix) {
				if (substr($_SERVER['REQUEST_URI'], 0, strlen($prefix)) == $prefix) {
					FineLog::log('temma', FineLog::DEBUG, "Prefixed URL found.");
					$isCacheable = true;
					break;
				}
			}
		}
		// résultat
		if (!$isCacheable)
			return;
		// utilisation du cache
		$this->set('_temmaCacheable', true);
		// on cherche si le contenu est déjà en cache
		$cacheVarName = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['REQUEST_URI'];
		$data = $cache->setPrefix('temma-cache')->get($cacheVarName);
		$cache->setPrefix();
		if (!empty($data)) {
			FineLog::log('temma', FineLog::DEBUG, 'Write from cache.');
			print($data);
			return (self::EXEC_QUIT);
		}
	}
}

