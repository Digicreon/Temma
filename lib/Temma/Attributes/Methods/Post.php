<?php

/**
 * Post
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_method#doc-head-get-post-put-patch-delete
 */

namespace Temma\Attributes\Methods;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force the POST method on an action or on all actions of a controller.
 *
 * Examples:
 * use \Temma\Attributes\Methods\Post as TµPost;
 * #[TµPost]
 * class PostOnlyController {
 *     ...
 * }
 *
 * use \Temma\Attributes\Methods\Post as TµPost;
 * class SomeController {
 *     #[TµPost]
 *     public function postOnlyAction() { }
 * }
 *
 * @see \Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Post extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @throws	\Temma\Exceptions\Application	If a method other than POST is used.
	 * @throws 	\Temma\Exceptions\FlowHalt	If a redirection is defined.
	 */
	public function __construct() {
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
			return;
		$url = $this->_getConfig()->xtra('security', 'methodRedirect') ?:
		       $this->_getConfig()->xtra('security', 'redirect');
		if ($url) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			$this->_redirect($url);
			throw new \Temma\Exceptions\FlowHalt();
		}
		TµLog::log('Temma/Web', 'WARN', "Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.");
		throw new TµApplicationException("Unauthorized method '{$_SERVER['REQUEST_METHOD']}'.", TµApplicationException::UNAUTHORIZED);
	}
}

