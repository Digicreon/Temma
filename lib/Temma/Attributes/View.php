<?php

/**
 * View
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-attr_view
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;

/**
 * Attribute used to define the view used by a controller or an action.
 *
 * Examples:
 * - Tell Temma to use the \Temma\Views\Json view on all actions of a controller:
 * use \Temma\Attributes\View as TµView;
 *
 * #[TµView('\Temma\Views\Json')]
 * class SomeController extends \Temma\Web\Controller {
 *     public function someAction() { }
 * }
 *
 * - The same, but only for one action of the controller:
 * use \Temma\Attributes\View as TµView;
 *
 * class SomeController extends \Temma\Web\Controller {
 *     #[TµView('\Temma\Views\Json')]
 *     public function someAction() { }
 * }
 * 
 * - The same (written differently):
 * #[TµView(\Temma\Views\Json::class)]
 *
 * - The same (telling to use Temma's standard Json view):
 * #[TµView('~Json')]
 *
 * - Tell Temma to use the standard RSS view:
 * #[TµView('~Rss')]
 *
 * - Reset to the default view (as configured in the 'temma.json' configuration file):
 * #[TµView]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class View extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|false|string	$view	(optional) The fully-namespaced name of the view object to use.
	 *						If left empty (or set to null), use the default view as configured in the 'temma.json' configuration file.
	 *						If set to false, disable the processing of the view.
	 */
	public function __construct(null|false|string $view=null) {
		$this->_getResponse()->setView($view);
	}
}

