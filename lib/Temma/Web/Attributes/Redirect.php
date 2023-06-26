<?php

/**
 * Redirect
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force a redirection on an action (or an all actions of a controller).
 *
 * Examples:
 * - Redirects access to any action of a controller:
 * use \Temma\Web\Attributes\Auth as TµRedirect;
 * #[TµRedirect['/somewhere/else']
 * class SomeController extends \Temma\Web\Controller {
 *     ...
 * }
 *
 * - Redirect access to one sepcific action:
 * use \Temma\Web\Attributes\Auth as TµRedirect;
 * class SomeController extends \Temma\Web\Controller {
 *     #[TµRedirect('/somewhere/else')]
 *     public function someAction() {
 *         // never executed
 *     }
 * }
 *
 * - Redirect to the URL defined in the 'goRedir' template variable:
 * #[TµRedirect(var: 'goRedir')]
 *
 * - Redirect using the 'redirect' key in the 'x-security' extended configuration:
 * #[TµRedirect(config: true)]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Redirect extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$url	(optional) Redirection URL.
	 * @param	?string	$var	(optional) Name of the template variable which contains the redirection URL.
	 * @param	bool	$config	(optional) True to use the 'refererUrl' key of the 'x-security' extended configuration.
	 * @throws	\Temma\Exceptions\Flow	Always throw a \Temma\Exceptions\FlowHalt exception.
	 */
	public function __construct(?string $url=null, ?string $var=null, bool $config=false) {
		if ($url) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			$this->_redirect($url);
			throw new \Temma\Exceptions\FlowHalt();
		}
		if ($var) {
			$url = $this[$var];
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_redirect($url);
				throw new \Temma\Exceptions\FlowHalt();
			}
		}
		if ($config) {
			$url = $this->_getConfig()->xtra('security', 'redirect');
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_redirect($url);
				throw new \Temma\Exceptions\FlowHalt();
			}
		}
	}
}

