<?php

/**
 * Plugin
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019, Amaury Bouchard
 */

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Basic object for plugin management.
 */
class Plugin extends \Temma\Web\Controller {
	/**
	 * Method called only when the plugin is executed as pre-plugin.
	 * The return could be an constant (e.g. `return self::EXEC_QUIT;`) or null (`return (null);`) or nothing (`return;`).
	 * Null and empty return values are the same than returning `self::EXEC_FORWARD`.
	 * @link	https://www.temma.net/en/documentation/flow
	 */
	public function preplugin() {
	}
	/**
	 * Method call only when the plugin is executed as post-plugin.
	 * The return could be an constant (e.g. `return self::EXEC_QUIT;`) or null (`return (null);`) or nothing (`return;`).
	 * Null and empty return values are the same than returning `self::EXEC_FORWARD`.
	 * @link	https://www.temma.net/en/documentation/flow
	 */
	public function postplugin() {
	}
	/**
	 * Method called when the plugin is executed (as pre-plugin or post-plugin),
	 * and the corresponding method (preplugin() or postplugin()) is not defined.
	 * The return could be an constant (e.g. `return self::EXEC_QUIT;`) or null (`return (null);`) or nothing (`return;`).
	 * Null and empty return values are the same than returning `self::EXEC_FORWARD`.
	 * @link	https://www.temma.net/en/documentation/flow
	 */
	public function plugin() {
	}
}

