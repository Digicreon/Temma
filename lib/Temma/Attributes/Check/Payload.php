<?php

/**
 * Payload
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_check_payload
 */

namespace Temma\Attributes\Check;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate request payload.
 *
 * This attribute can be used on a controller class (applied to all methods) or on a specific action.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check\Payload as TµCheckPayload;
 *
 * // check for a raw payload (int greater or equal to 3)
 * #[TµCheckPayload('int; min: 3')]
 *
 * // check for a JSON payload
 * #[TµCheckPayload([
 *     'type' => 'assoc',
 *     'keys' => [
 *         'int'   => 'int',
 *         'name?' => 'string',
 *     ]
 * ])]
 * ```
 *
 * // definition of a redirection URL if validation fails
 * #[TµCheckPayload('int; min: 3', redirect: '/error')]
 *
 * // definition of a redirection URL (from a template variable) if validation fails
 * #[TµCheckPayload('int; min: 3', redirectVar: 'redirectUrl')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Payload extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	string|array	$contract	Name of the contract defined in the configuration file, or name of the validation object,
	 *						or validation contract to check the payload.
	 * @param	bool		$strict		(optional) True to use strict matching. False by default.
	 * @param	?string		$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string		$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @param	?string		$flashVar	(optional) Name of the session flash variable which will contain the invalid GET variable in case of redirection.
	 */
	public function __construct(
		protected string|array $contract,
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
	 * @throws	\Temma\Exceptions\Application	If the payload is not valid.
	 * @throws	\Temma\Exceptions\FlowHalt	If the valid is not valid and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		try {
			$this->_request->validatePayload($this->contract, $this->strict);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                              // direct URL
			       $this[$this->redirectVar] ?:                    // template variable
			       $this->_config->xtra('security', 'redirect');   // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				if ($this->flashVar)
					$this->_session['__' . $this->flashVar] = true;
				$this->_redirect($url);
				throw new TµFlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}
