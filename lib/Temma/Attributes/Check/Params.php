<?php

/**
 * Params
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_check_get
 */

namespace Temma\Attributes\Check;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate action parameters.
 *
 * This attribute can be used on a specific action.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check\Params as TµCheckParams;
 *
 * class User extends \Temma\Web\Controller {
 *     // the first parameter is an integer
 *     // the second parameter is an enum ('member' or admin')
 *     #[TµCheckParams(['int', 'enum; values: member, admin'])]
 *     public function addUser(int $parentId, string $type) {
 *         // ...
 *     }
 *
 *     // use the 'userType' contract declared in the configuration file
 *     #[TµCheckParams('userType')]
 *     public function changeUserType(int $id, string $type) {
 *         // ...
 *     }
 *
 *     // the first parameter is an int, the second an hexa color
 *     // with strict validation
 *     #[TµCheckParams(['color'], strict: true)]
 *     public function setColor(int $id, string $color) {
 *         // ...
 *     }
 * }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Params extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	array	$parameters	Associative array of parameters to check.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @param	?string	$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string	$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @param	?string	$flashVar	(optional) Name of the session flash variable which will contain the invalid GET variable in case of redirection.
	 */
	public function __construct(
		protected array $parameters,
		protected bool $strict=false,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
		protected ?string $flashVar=null,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If the parameters are not valid.
	 * @throws	\Temma\Exceptions\FlowHalt	If the parameters are not valid and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		try {
			$this->_request->validateParams($this->parameters, $this->strict);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                              // direct URL
			       $this[$this->redirectVar] ?:                    // template variable
			       $this->_config->xtra('security', 'redirect');   // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				if ($this->flashVar)
					$this->_session['__' . $this->flashVar] = $_GET;
				$this->_redirect($url);
				throw new TµFlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}
