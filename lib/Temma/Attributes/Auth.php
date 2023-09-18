<?php

/**
 * Auth
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define the user authorizations needed to access to an action.
 *
 * It uses a 'currentUser' template variable, which is an associative array with the following keys:
 * - id:       User's identifier, set if the user is authenticated.
 * - roles:    Associative array whose keys are the user's roles (associated with the value true).
 * - services: Associative array whose keys are the services to which the user has access (associated with the value <tt>true</tt>).
 *
 * Examples:
 * - Access to authenticated users only, for all actions of a controller:
 * use \Temma\Attributes\Auth as TµAuth;
 *
 * #[TµAuth]
 * class SomeController extends \Temma\Web\Controller {
 *     public function someAction() { }
 * }
 *
 * - Access to unauthenticated users only:
 * #[TµAuth(authenticated: false)]
 *
 * - Access to administrators only:
 * #[TµAuth('admin')]
 *
 * - Access to managers only:
 * #[TµAuth('manager')
 *
 * - The same as the previous one:
 * #[TµAuth(role: 'manager')
 *
 * - Access to managers and writers:
 * #[TµAuth(role: ['manager', 'writer'])]
 *
 * - Access to users who have access rights on images:
 * #[TµAuth(service: 'image')]
 *
 * - Access to writers who have access rights on texts or images:
 * #[TµAuth(role: 'writer', service: ['text', 'image'])]
 *
 * - Access to users who are writers and reviewers at the same time:
 * #[TµAuth('writer')]
 * #[TµAuth('reviewer')]
 *
 * - Redirects unauthenticated users to the given URL:
 * #[TµAuth(redirect: '/login')]
 *
 * - Redirects non-administrators to the given URL:
 * #[TµAuth('admin', redirect: '/unauthorized')]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Auth extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$role		(optional) One or many user roles that must be matched (at least one).
	 * @param	null|string|array	$service	(optional) One or many services that must be matched (at least one).
	 * @param	?bool			$authenticated	(optional) Tell if the user must be authenticated or not. (default value: true)
	 *							- true if the user must be authenticated.
	 *							- false if the user must not be authenticated.
	 *							- null if the user can be authenticated or not.
	 * @param	?string			$redirect	(optional) Redirection URL used if there is an authentication problem (instead of throwing an exception).
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @throws	\Temma\Exceptions\Application	If the user is not authorized.
	 * @throws	\Temma\Exceptions\FlowHalt	If the user is not authorized and a redirect URL has been given.
	 */
	public function __construct(null|string|array $role=null, null|string|array $service=null,
	                            ?bool $authenticated=true, ?string $redirect=null, ?string $redirectVar=null) {
		try {
			// check authentication
			if ($authenticated === true && !($this['currentUser']['id'] ?? false)) {
				TµLog::log('Temma/Web', 'WARN', "User is not authenticated (while an authenticated user is expected).");
				throw new TµApplicationException("User is not authenticated.", TµApplicationException::AUTHENTICATION);
			}
			if ($authenticated === false && ($this['currentUser']['id'] ?? false)) {
				TµLog::log('Temma/Web', 'WARN', "User is authenticated (while a unauthenticated user is expected).");
				throw new TµApplicationException("User is authenticated.", TµApplicationException::AUTHENTICATION);
			}
			// check roles
			if ($role) {
				$authRoles = is_array($role) ? $role : [$role];
				$userRoles = $this['currentUser']['roles'];
				$found = false;
				foreach ($authRoles as $role) {
					if (isset($userRoles[$role])) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "User has no mathcing role.");
					throw new TµApplicationException("User has no matching role.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check services
			if ($service) {
				$authServices = is_array($service) ? $service : [$service];
				$userServices = $this['currentUser']['services'] ?? [];
				$found = false;
				foreach ($authServices as $service) {
					if (isset($userServices[$service])) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "User has no matching access.");
					throw new TµApplicationException("User has no matching access.", TµApplicationException::UNAUTHORIZED);
				}
			}
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $redirect ?:                                             // direct URL
			       $this[$redirectVar] ?:                                   // template variable
			       $this->_getConfig()->xtra('security', 'authRedirect') ?: // specific configuration
			       $this->_getConfig()->xtra('security', 'redirect');       // general configuration
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

