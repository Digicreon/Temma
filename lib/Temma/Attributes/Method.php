<?php

/**
 * Method
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_method
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define the method(s) accepted by an action or by all actions of a controller.
 *
 * Examples:
 * - Accept GET method only, for all actions of a controller:
 * use \Temma\Attributes\Method as TµMethod;
 * #[TµMethod('GET')]
 * class SomeController extends \Temma\Web\Controller {
 *     ...
 * }
 *
 * - Accept POST and PUT methods only:
 * #[TµMethod(['POST', 'PUT'])]
 *
 * - Accept all méthods but GET and DELETE:
 * #[TµMethod(forbidden: ['GET', 'DELETE'])]
 *
 * - Accept POST method only, redirects invalid requests
 * #[TµMethod('POST', redirect: '/')]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Method extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$allowed	(optional) Allowed method(s).
	 * @param	null|string|array	$forbidden	(optional) Forbidden method(s).
	 * @param	?string			$redirect	(optional) Redirection URL if a forbidden (or not authorized) method is used.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		protected null|string|array $allowed=null,
		protected null|string|array $forbidden=null,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If a forbidden (or not authorized) method is used.
	 */
	public function apply(\Reflector $context) : void {
		try {
			if ($this->forbidden) {
				if (is_string($this->forbidden))
					$this->forbidden = [$this->forbidden];
				foreach ($this->forbidden as $method) {
					if (strtoupper($method) === $_SERVER['REQUEST_METHOD']) {
						TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
						throw new TµApplicationException("Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
					}
				}
			}
			if (!$this->allowed)
				return;
			if (is_string($this->allowed))
				$this->allowed = [$this->allowed];
			foreach ($this->allowed as $method) {
				if (strtoupper($method) === $_SERVER['REQUEST_METHOD'])
					return;
			}
			TµLog::log('Temma/Web', 'WARN', "Invalid méthod '{$_SERVER['REQUEST_METHOD']}'.");
			throw new TµApplicationException("Invalid method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                                    // direct URL
			       $this[$this->redirectVar] ?:                          // template variable
			       $this->_config->xtra('security', 'methodRedirect') ?: // specific configuration
			       $this->_config->xtra('security', 'redirect');         // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_redirect($url);
				throw new \Temma\Exceptions\FlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}

