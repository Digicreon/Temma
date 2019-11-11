<?php

namespace Temma\Web;

use \Temma\Base\Log as TµLog;

/**
 * Basic object for plugin management.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Web
 */
class Plugin extends \Temma\Web\Controller {
	/**
	 * Method called only when the plugin is executed as pre-plugin.
	 * @return	?int	Execution status (self::EXEC_QUIT, ...). Could be null (==self::EXEC_FORWARD).
	 */
	public function preplugin() /* : ?int */ {
	}
	/**
	 * Method call only when the plugin is executed as post-plugin.
	 * @return	?int	Execution status (self::EXEC_QUIT, ...). Could be null (==self::EXEC_FORWARD).
	 */
	public function postplugin() /* : ?int */ {
	}
	/**
	 * Method called when the plugin is executed (as pre-plugin or post-plugin),
	 * and the corresponding method (preplugin() or postplugin()) is not defined.
	 * @return	?int	Execution status (self::EXEC_QUIT, ...). Could be null (==self::EXEC_FORWARD).
	 */
	public function plugin() /* : ?int */ {
	}
}

