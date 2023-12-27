<?php

/**
 * Template
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;

/**
 * Attribute used to define the template used by a controller or an action.
 *
 * Example:
 * use \Temma\Attributes\Template as TµTemplate;
 *
 * class SomeController extends \Temma\Web\Controller {
 *     // use the 'otherCtrl/someAction.tpl' template instead of 'someController/someAction.tpl'
 *     #[TµTemplate('otherCtrl/someAction.tpl')]
 *     public function someAction() {
 *     }
 * }
 *
 * - Reset to the default template (controller/action.tpl):
 * #[TµTemplate]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Template extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$template	(optional) Path to the template file to use.
	 *					If left empty (or set to null), use the default template (constroller/action.tpl).
	 */
	public function __construct(?string $template=null) {
		$this->_getResponse()->setTemplate($template);
	}
}

