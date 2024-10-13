<?php

/*
 * KebabCaseUrl
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Text as TµText;

/** 
 * Plugin used to convert controller and action names, from kebab case to camel case.
 *
 * Examples:
 * - `/my-controller`                         => `/myController`
 * - `/my-controller/some-action`             => `/myController/someAction`
 * - `/my-controller/someAction`              => `/myController/someAction`
 * - `/my-controller/some-action/first-param` => `/myController/some/Action/first-param`
 *
 * @see	\Temma\Utils\Text::convertCase
 */
class KebabCaseUrl extends \Temma\Web\Plugin {
	/** Preplugin method. */
	public function preplugin() {
		// fetch requested controller and action names
		$oldController = $this['CONTROLLER'];
		$oldAction = $this['ACTION'];
		// if there is an upper case character, it is camel case => 404 error
		if (TµText::hasUpper($oldController) || TµText::hasUpper($oldAction)) {
			TµLog::log('Temma/Web', 'ERROR', "Controller or action in camel case. Kebab case required.");
			return $this->_httpError(404);
		}
		// check if there is no dash in the controller and action names
		if (!str_contains($oldController, '-') && !str_contains($oldAction, '-'))
			return;
		// controller rewrite
		$controller = TµText::convertCase($oldController ?? '', TµText::KEBAB_CASE, TµText::CAMEL_CASE);
		$this->_loader->request->setController($controller);
		// action rewrite
		$action = TµText::convertCase($oldAction ?? '', TµText::KEBAB_CASE, TµText::CAMEL_CASE);
		$this->_loader->request->setAction($action);
	}
}

