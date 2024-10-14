<?php

/**
 * Template
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/helper-attr_template
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;

/**
 * Attribute used to define the template used by a controller or an action.
 *
 * Examples:
 * - Set the template of an action:
 * ```php
 * use \Temma\Attributes\Template as TµTemplate;
 *
 * class SomeController extends \Temma\Web\Controller {
 *     // use the 'otherCtrl/someAction.tpl' template instead of 'someController/someAction.tpl'
 *     #[TµTemplate('otherCtrl/someAction.tpl')]
 *     public function someAction() {
 *     }
 * }
 * ```
 *
 * - Reset to the default template (controller/action.tpl):
 * ```php
 * #[TµTemplate(null)]
 * ```
 *
 * - Set the template prefix for all actions of a controller:
 * ```php
 * use \Temma\Attributes\Template as TµTemplate;
 *
 * #[TµTemplate(prefix: 'specialTemplates')]
 * class SomeController extends \Temma\Web\Controller {
 *     // use the 'specialTemplates/someController/someAction.tpl' template
 *     // instead of 'someController/someAction.tpl'
 *     #[TµTemplate('otherCtrl/someAction.tpl')]
 *     public function someAction() {
 *     }
 * }
 * ```
 *
 * - Reset the template prefix:
 * ```php
 * #[TµTemplate(prefix: null)]
 * ```
 *
 * - Reset the template to the default template, and reset the template prefix:
 * ```php
 * #[TµTemplate(template: null, prefix: null)]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Template extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$template	(optional) Path to the template file to use.
	 *					If set to null, use the default template (constroller/action.tpl).
	 * @param	?string	$prefix		(optional) Prefix to the used template's path.
	 *					If set to null, reset the prefix.
	 */
	public function __construct(?string $template='', ?string $prefix='') {
		if ($template !== '')
			$this->_getResponse()->setTemplate($template);
		if ($prefix !== '')
			$this->_getResponse()->setTemplatePrefix($prefix);
	}
}

