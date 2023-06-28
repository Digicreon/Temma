<?php

/**
 * Patch
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the PATCH method on an action or on all actions of a controller.
 *
 * Examples:
 * use \Temma\Web\Attributes\Methods\Patch as TµPatch;
 * #[TµPatch]
 * class PatchOnlyController {
 *     ...
 * }
 *
 * use \Temma\Web\Attributes\Methods\Patch as TµPatch;
 * class SomeController {
 *     #[TµPatch]
 *     public function patchOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Patch extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @throws	\Temma\Exceptions\Application	If a method other than PATCH is used.
	 */
	public function __construct() {
		if ($_SERVER['REQUEST_METHOD'] == 'PATCH')
			return;
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

