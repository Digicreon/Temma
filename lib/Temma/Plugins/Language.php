<?php

/**
 * Language
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2011-2021, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Serializer as TµSerializer;
use \Temma\Exceptions\IO as TµIOException;

/**
 * Plugin used to manage website localization.
 *
 * @see	https://www.temma.fr/fr/documentation/plugin-language
 */
class Language extends \Temma\Web\Plugin {
        /**
	 * Plugin method.
	 * @return	mixed	EXEC_HALT if a redirection is needed. EXEC_FORWARD otherwise.
	 */
        public function plugin() {
		$lang = $this['lang'];
		if (isset($lang))
			return (self::EXEC_FORWARD);
		$defaultLanguage = $this->_loader->config->xtra('language', 'default');
		$supportedLanguages = $this->_loader->config->xtra('language', 'supported');
		$protectedUrls = $this->_loader->config->xtra('language', 'protectedUrls', []);
		if (!$defaultLanguage || !is_array($supportedLanguages) || !is_array($protectedUrls)) {
			// the configuration is wrong
			TµLog::log('Temma/Web', 'WARN', "Wrong configuration for language plugin.");
			return (self::EXEC_FORWARD);
		}
		$currentLang = $this['CONTROLLER'];
		if (!in_array($currentLang, $supportedLanguages)) {
			/* the language wasn't given as first URL chunk */
			// no language required if the method is POST or PUT
			if (in_array(($_SERVER['REQUEST_METHOD'] ?? null), ['POST', 'PUT'])) {
				TµLog::log('Temma/Web', 'DEBUG', "No language required for POST/PUT request.");
				return (self::EXEC_FORWARD);
			}
			// no language required for protected URLs
			if (in_array($this['URL'], $protectedUrls)) {
				TµLog::log('Temma/Web', 'DEBUG', "No language required for protected URL.");
				return (self::EXEC_FORWARD);
			}
			// search for the navigator's preferred language
			$acceptedLanguages = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
			foreach (explode(',', $acceptedLanguages) as $acceptedLanguage) {
				if (($pos = strpos($acceptedLanguage, ';')) !== false ||
				    ($pos = strpos($acceptedLanguage, '-')) !== false)
					$acceptedLanguage = substr($acceptedLanguage, 0, $pos);
				if (in_array($acceptedLanguage, $supportedLanguages)) {
					$defaultLanguage = $acceptedLanguage;
					break;
				}
			}
			// redirection
			$this->_redirect("/$defaultLanguage" . $_SERVER['REQUEST_URI']);
			return (self::EXEC_HALT);
		}
		/* The language was given as first URL chunk. */
		// we shift all URL chunks
		$newController = $this['ACTION'];
		$this->_loader->request->setController($newController);
		$params = $this->_loader->request->getParams();
		$newAction = array_shift($params);
		$this->_loader->request->setAction($newAction);
		$this->_loader->request->setParams($params);
		/* Read the translation file if needed. */
		$readTranslationFile = $this->_loader->config->xtra('language', 'readTranslationFile', true);
		if ($readTranslationFile) {
			$langPath = $this->_loader->config->etcPath . "/lang/$currentLang";
			try {
				$langFile = TµSerializer::readFromPrefix($langPath);
			} catch (TµIOException $ioe) {
				$logLevel = ($currentLang == $defaultLanguage) ? 'NOTE' : 'WARN';
				TµLog::log('Temma/Web', $logLevel, "Unable to read language file '$langPath'.");
				$langFile = null;
			}
			$this['l10n'] = $langFile;
		}
		$this['lang'] = $currentLang;
		// URL update
		$url = $this['URL'];
		if (substr($url, 0, strlen("/$currentLang")) == "/$currentLang") {
			$url = trim(substr($url, strlen("/$currentLang")));
			$url = empty($url) ? '/' : $url;
			$this['URL'] = $url;
		}
		$this['CONTROLLER'] = $newController;
		$this['ACTION'] = $newAction;
		// set the template prefix
		$prefix = $this->_loader->config->xtra('language', 'templatePrefix', $currentLang);
		if ($prefix)
			$this->_templatePrefix($currentLang);
		// update error pages' path
		$errorPages = $this->_loader->config->errorPages;
		if ($errorPages) {
			foreach ($errorPages as &$page) {
				if (!$page)
					continue;
				$page = "error-pages/$currentLang/$page";
			}
			$this->_loader->config->errorPages = $errorPages;
		}
        }
}

