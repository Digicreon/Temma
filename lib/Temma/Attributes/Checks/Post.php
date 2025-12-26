<?php

/**
 * CheckPost
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_checkpost
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate POST parameters.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\CheckPost as TµCheckPo	st;
 *
 * // check for a "name" parameter (string of at least 2 characters)
 * #[TµCheckPost(['name' => 'string; minLen: 2'])]
 *
 * // check for a "name" parameter (string of at least 2 characters),
 * // a "mail" parameter (email), and a "balance" parameter (optional float)
 * #[TµCheckPost([
 *     'name'     => 'string; minLen: 2',
 *     'mail'     => 'email',
 *     'balance?' => 'float'
 * ])]
 *
 * // check for a raw POST payload (int greater or equal to 3)
 * #[TµCheckPost('int; min: 3')]
 *
 * // check for a JSON payload
 * #[TµCheckPost(json: [
 *     'type' => 'assoc',
 *     'keys' => [
 *         'int'   => 'int',
 *         'name?' => 'string',
 *     ]
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class CheckPost extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$parameters	(optional) Associative array of parameters to check, or string for raw payload check.
	 * @param	null|string|array	$json		(optional) JSON contract to check the payload.
	 * @param	bool			$strict		(optional) True to use strict matching. False by default.
	 * @param	?string			$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		protected null|string|array $parameters=null,
		protected null|string|array $json=null,
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
			// check JSON payload
			if ($this->json) {
				$body = file_get_contents('php://input');
				$data = json_decode($body, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					TµLog::log('Temma/Web', 'WARN', "Invalid JSON payload.");
					throw new TµApplicationException("Invalid JSON payload.", TµApplicationException::API);
				}
				TµDataFilter::process($data, $this->json, $this->strict);
			} else if (is_string($this->parameters)) {
				// check raw payload
				$body = file_get_contents('php://input');
				TµDataFilter::process($body, $this->parameters, $this->strict);
			} else if (is_array($this->parameters)) {
				// check POST parameters
				foreach ($this->parameters as $name => $contract) {
					// manage optional parameters
					$optional = false;
					if (str_ends_with($name, '?')) {
						$optional = true;
						$name = substr($name, 0, -1);
					}
					// check if the parameter exists
					if (!array_key_exists($name, $_POST)) {
						// if the parameter is optional, it's fine
						if ($optional)
							continue;
						// try to use the default value defined in contract
						try {
							$_POST[$name] = TµDataFilter::process(null, $contract, $this->strict);
							continue;
						} catch (TµApplicationException $e) {
							// missing parameter
							TµLog::log('Temma/Web', 'WARN', "Missing POST parameter '$name'.");
							throw new TµApplicationException("Missing POST parameter '$name'.", TµApplicationException::API);
						}
					}
					// check the parameter
					$_POST[$name] = TµDataFilter::process($_POST[$name], $contract, $this->strict);
				}
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
