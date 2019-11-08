<?php

namespace Temma\Web;

/**
 * Object used for controllers management.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2019, Amaury Bouchard
 * @package	Temma
 * @subpackage	Web
 * @see		\Temma\Web\BaseController
 */
class Controller extends \Temma\Web\BaseController {
	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader		Dependency injection object, with (at least) the keys
	 *							'dataSources', 'session', 'config', 'request', 'response'.
	 * @param	\Temma\Web\Controller	$executor	(optional) Executor controller object (the one who called this controller).
	 */
	final public function __construct(\Temma\Base\Loader $loader, ?\Temma\Web\Controller $executor=null) {
		parent::__construct($loader, $executor);
	}
}

