<?php

/**
 * Delete
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_method#doc-head-get-post-put-patch-delete
 */

namespace Temma\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the DELETE method on an action or on all actions of a controller.
 *
 * Examples:
 * use \Temma\Attributes\Methods\Delete as TµDelete;
 * #[TµDelete]
 * class DeleteOnlyController {
 *     ...
 * }
 *
 * use \Temma\Attributes\Methods\Delete as TµDelete;
 * class SomeController {
 *     #[TµDelete]
 *     public function deleteOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Delete extends \Temma\Web\Attribute {
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If a method other than DELETE is used.
	 * @throws 	\Temma\Exceptions\FlowHalt	If a redirection is defined.
	 */
	public function apply(\Reflector $context) : void {
		if ($_SERVER['REQUEST_METHOD'] == 'DELETE')
			return;
		$url = $this->_config->xtra('security', 'methodRedirect') ?:
		       $this->_config->xtra('security', 'redirect');
		if ($url) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			$this->_redirect($url);
			throw new \Temma\Exceptions\FlowHalt();
		}
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

