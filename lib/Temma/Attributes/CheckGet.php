<?php

/**
 * CheckGet
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_checkget
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate GET parameters.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\CheckGet as TµCheckGet;
 *
 * // check for an "id" parameter (integer between 5 and 128)
 * #[TµCheckGet(['id' => 'int; min: 5; max: 128'])]
 *
 * // check for an "id" parameter (integer between 5 and 128)
 * // and a "name" parameter (optional string of at least 2 characters)
 * #[TµCheckGet([
 *     'id'    => 'int; min: 5; max: 128',
 *     'name?' => 'string; minLen: 2',
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class CheckGet extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	array	$parameters	Associative array of parameters to check.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @param	?string	$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string	$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		protected array $parameters,
		protected bool $strict=false,
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
			foreach ($this->parameters as $name => $contract) {
				// manage optional parameters
				$optional = false;
				if (str_ends_with($name, '?')) {
					$optional = true;
					$name = mb_substr($name, 0, -1);
				}
				// check if the parameter exists
				if (!array_key_exists($name, $_GET)) {
					// if the parameter is optional, it's fine
					if ($optional)
						continue;
					// try to use the default value defined in contract
					try {
						$_GET[$name] = TµDataFilter::process(null, $contract, $this->strict);
						continue;
					} catch (TµApplicationException $e) {
						// missing parameter
						TµLog::log('Temma/Web', 'WARN', "Missing GET parameter '$name'.");
						throw new TµApplicationException("Missing GET parameter '$name'.", TµApplicationException::API);
					}
				}
				// check the parameter
				$_GET[$name] = TµDataFilter::process($_GET[$name], $contract, $this->strict);
			}
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

