<?php

/**
 * Put
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the PUT method on an action or on all actions of a controller.
 *
 * Examples:
 * use \Temma\Web\Attributes\Methods\Put as TµPut;
 * #[TµPut]
 * class PutOnlyController {
 *     ...
 * }
 *
 * use \Temma\Web\Attributes\Methods\Put as TµPut;
 * class SomeController {
 *     #[TµPut]
 *     public function putOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Put extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @throws	\Temma\Exceptions\Application	If a method other than PUT is used.
	 */
	public function __construct() {
		if ($_SERVER['REQUEST_METHOD'] == 'PUT')
			return;
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthoried method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

