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
use \Temma\Exceptions\Http as TµHttpException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate action parameters.
 *
 * This attribute can be used on a controller class (applied to all methods) or on a specific action.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check\Params as TµCheckParams;
 *
 * class User extends \Temma\Web\Controller {
 *     // the first parameter is an integer greater or equal than 18
 *     // the second parameter is an enum ('member' or admin')
 *     #[TµCheckParams(['int; min: 18', 'enum; values: member, admin'])]
 *     public function addUser(int $age, string $type) {
 *         // ...
 *     }
 *
 *     // the first parameter is an int, the second an hexa color
 *     // with strict validation
 *     #[TµCheckParams(['~int', 'color'], strict: true)]
 *     public function setColor(int $id, string $color) {
 *         // ...
 *     }
 * }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Params extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	string|array	$contract		Name of the contract defined in the configuration file, or name of the validation object,
	 *							or a list of contracts (one contract per validated parameter).
	 * @param	bool		$strict			(optional) True to use strict matching. False by default.
	 * @param	?string		$redirect		(optional) Redirection URL used if the check fails.
	 * @param	?string		$redirectVar		(optional) Name of the template variable which contains the redirection URL.
	 * @param	bool		$redirectReferer	(optional) True to use the HTTP REFERER as redirection URL. True by default.
	 * @param	?string		$flashVar		(optional) Name of the session flash variable which will contain the invalid data in case of redirection. ("form" by default)
	 */
	public function __construct(
		protected string|array $contract,
		protected bool $strict=false,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
		protected bool $redirectReferer=true,
		protected ?string $flashVar='form',
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Http		If the parameters are not valid and no redirect URL is available (403).
	 * @throws	\Temma\Exceptions\FlowHalt	If the parameters are not valid and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		try {
			$this->_request->validateParams($this->contract, $this->strict);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect                                  // direct URL
			       ?: $this[$this->redirectVar]                     // template variable
			       ?: ($this->redirectReferer                       // REFERER (if enabled)
			           ? ($_SERVER['HTTP_REFERER'] ?? null)
			           : null)
			       ?: $this->_config->xtra('security', 'redirect'); // config
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				if ($this->flashVar)
					$this->_session['__' . $this->flashVar] = $this->_request->getParams();
				$this->_redirect($url);
				throw new TµFlowHalt();
			}
			// no redirection URL available
			throw new TµHttpException("Forbidden.", 403);
		}
	}
}

