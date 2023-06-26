<?php

/**
 * Head
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the HEAD method on an action or an all actions of a controller.
 *
 * Examples:
 * use \Temma\Web\Attributes\Methods\Head as TµHead;
 * #[TµHead]
 * class HeadOnlyController {
 *     ...
 * }
 *
 * use \Temma\Web\Attributes\Methods\Head as TµHead;
 * class SomeController {
 *     #[TµHead]
 *     public function headOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Head extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @throws	\Temma\Exceptions\Application	If a method other than HEAD is used.
	 */
	public function __construct() {
		if ($_SERVER['REQUEST_METHOD'] == 'HEAD')
			return;
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthoried method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}
