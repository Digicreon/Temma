<?php

/**
 * Redirect
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_redirect
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to force a redirection on an action (or an all actions of a controller).
 *
 * Examples:
 * - Redirects access to any action of a controller:
 * use \Temma\Attributes\Redirect as TµRedirect;
 * #[TµRedirect['/somewhere/else']
 * class SomeController extends \Temma\Web\Controller {
 *     ...
 * }
 *
 * - Redirect access to one sepcific action:
 * use \Temma\Attributes\Redirect as TµRedirect;
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
 * - Redirect to the HTTP REFERER:
 * #[TµRedirect(referer: true)]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Redirect extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	?string	$url		(optional) Redirection URL.
	 * @param	?string	$var		(optional) Name of the template variable which contains the redirection URL.
	 * @param	bool	$referer	(optional) True to use the HTTP REFERER as redirection URL. False by default.
	 */
	public function __construct(
		protected ?string $url=null,
		protected ?string $var=null,
		protected bool $referer=false,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Flow		When a redirection URL has been defined.
	 * @throws	\Temma\Exceptions\Application	If no redirection URL has been defined.
	 */
	public function apply(\Reflector $context) : void {
		$url = $this->url                                       // direct URL
		       ?: $this[$this->var]                             // template variable
		       ?: ($this->referer                               // REFERER (if enabled)
		           ? ($_SERVER['HTTP_REFERER'] ?? null)
		           : null)
		       ?: $this->_config->xtra('security', 'redirect'); // configuration
		if ($url) {
			TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
			$this->_redirect($url);
			throw new \Temma\Exceptions\FlowHalt();
		}
		// no redirection URL defined
		TµLog::log('Temma/Web', 'DEBUG', "No redirection URL defined.");
		throw new \Temma\Exceptions\Application("Redirect attribute with no defined URL.", \Temma\Exceptions\Application::UNAUTHORIZED);
	}
}

