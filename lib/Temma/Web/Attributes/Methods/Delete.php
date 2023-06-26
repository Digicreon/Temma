<?php

/**
 * Delete
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the DELETE method on an action or on all actions of a controller.
 *
 * Examples:
 * use \Temma\Web\Attributes\Methods\Delete as TµDelete;
 * #[TµDelete]
 * class DeleteOnlyController {
 *     ...
 * }
 *
 * use \Temma\Web\Attributes\Methods\Delete as TµDelete;
 * class SomeController {
 *     #[TµDelete]
 *     public function deleteOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Delete extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @throws	\Temma\Exceptions\Application	If a method other than DELETE is used.
	 */
	public function __construct() {
		if ($_SERVER['REQUEST_METHOD'] == 'DELETE')
			return;
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthoried method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

