<?php

/**
 * Check
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_check
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate GET and/or POST parameters.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check as TµCheck;
 *
 * // check for a "id" GET or POST parameter
 * #[TµCheck(['id' => 'int'])]
 *
 * // check for an "id" GET parameter (integer between 5 and 128)
 * #[TµCheck(['id' => 'int; min: 5; max: 128'], 'get')]
 *
 * // check for three POST parameters:
 * // a "name" parameter (string of at least 2 characters),
 * // a "mail" parameter (email), and a "balance" parameter (optional float)
 * #[TµCheck([
 *     'name'     => 'string; minLen: 2',
 *     'mail'     => 'email',
 *     'balance?' => 'float',
 * ], type: 'post')]
 *
 * // check for a raw POST payload (int greater or equal to 3)
 * #[TµCheck('int; min: 3', type: 'post')]
 *
 * // check for a JSON POST payload
 * #[TµCheck(json: [
 *     'type' => 'assoc',
 *     'keys' => [
 *         'int'   => 'int',
 *         'name?' => 'string',
 *     ]
 * ], 'post')]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Check extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$parameters	(optional) Associative array of parameters to check, or string for raw payload check.
	 * @param	?string			$type		(optional) Type of check ('GET', 'POST'). If null, checks both if data exists.
	 * @param	bool			$strict		(optional) True to use strict matching. False by default.
	 * @param	null|string|array	$json		(optional) JSON contract to check the payload.
	 * @param	?string			$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		protected null|string|array $parameters=null,
		protected ?string $type=null,
		protected bool $strict=false,
		protected null|string|array $json=null,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
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
			$this->_request->validate($this->parameters, $this->type, $this->strict, $this->json);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                              // direct URL
			       $this[$this->redirectVar] ?:                    // template variable
			       $this->_config->xtra('security', 'redirect');   // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_redirect($url);
				throw new TµFlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}
