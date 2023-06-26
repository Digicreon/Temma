<?php

/**
 * Method
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define the method(s) accepted by an action or by all actions of a controller.
 *
 * Examples:
 * - Accept GET method only, for all actions of a controller:
 * use \Temma\Web\Attributes\Method as TµMethod;
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
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Method extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$allowed	(optional) Allowed method(s).
	 * @param	null|string|array	$forbidden	(optional) Forbidden method(s).
	 * @throws	\Temma\Exceptions\Application	If a forbidden (or not authorized) method is used.
	 */
	public function __construct(null|string|array $allowed=null, null|string|array $forbidden=null) {
		if ($forbidden) {
			if (is_string($forbidden))
				$forbidden = [$forbidden];
			foreach ($forbidden as $method) {
				if (strtoupper($method) === $_SERVER['REQUEST_METHOD']) {
					TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
					throw new TµApplicationException("Unauthoried method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
				}
			}
		}
		if (!$allowed)
			return;
		if (is_string($allowed))
			$allowed = [$allowed];
		foreach ($allowed as $method) {
			if (strtoupper($method) === $_SERVER['REQUEST_METHOD'])
				return;
		}
		TµLog::log('Temma/Web', 'WARN', "Invalid méthod '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Invalid method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

