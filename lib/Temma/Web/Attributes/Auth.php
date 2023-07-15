<?php

/**
 * Auth
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define the user authorizations needed to access to an action.
 *
 * It uses a 'currentUser' template variable, which is an associative array with the following keys:
 * - id:       User's identifier, set if the user is authenticated.
 * - isAdmin:  Boolean value, set to true if the user has super-administrator rights.
 * - roles:    List of strings, each string being a granted role of the user.
 * - services: List of strings, each string being the name of a services accessible by the user.
 *
 * Examples:
 * - Access to authenticated users only, for all actions of a controller:
 * use \Temma\Web\Attributes\Auth as TµAuth;
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
 * #[TµAuth(isAdmin: true)]
 *
 * - Access to managers only:
 * #[TµAuth('manager')
 *
 * - The same as the previous one:
 * #[TµAuth(role: 'manager')
 *
 * - Access to managers and writers:
 * #[TµAuth(roles: ['manager', 'writer'])]
 *
 * - Access to users who have access rights on images:
 * #[TµAuth(service: 'image')]
 *
 * - Access to writers who have access rights on texts or images:
 * #[TµAuth(role: 'writer', services: ['text', 'image'])]
 *
 * - Access to users who are writers and reviewers at the same time:
 * #[TµAuth('writer')]
 * #[TµAuth('reviewer')]
 *
 * - Redirects unauthenticated users to the given URL:
 * #[TµAuth(redirect: '/login')]
 *
 * - Redirects non-administrators to the given URL:
 * #[TµAuth(isAdmin: true, redirect: '/unauthorized')]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Auth extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|string|array	$role		(optional) One or many user roles that must be matched (at least one).
	 * @param	null|string|array	$roles		(optional) Same as the previous parameter.
	 * @param	null|string|array	$service	(optional) One or many services that must be matched (at least one).
	 * @param	null|string|array	$services	(optional) Same as the previous paramter.
	 * @param	?bool			$isAdmin	(optional) Tell if the user must be super-administrator or not. (default value: null).
	 *							- true if the user must be an administrator.
	 *							- false if the user must not be an administrator.
	 *							- null if the user can be an administrator or not.
	 * @param	?bool			$authenticated	(optional) Tell if the user must be authenticated or not. (default value: true)
	 *							- true if the user must be authenticated.
	 *							- false if the user must not be authenticated.
	 *							- null if the user can be authenticated or not.
	 * @param	?string			$redirect	(optional) Redirection URL used if there is an authentication problem (instead of throwing an exception).
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @throws	\Temma\Exceptions\Application	If the user is not authorized.
	 * @throws	\Temma\Exceptions\FlowHalt	If the user is not authorized and a redirect URL has been given.
	 */
	public function __construct(null|string|array $role=null, null|string|array $roles=null,
	                            null|string|array $service=null, null|string|array $services=null,
	                            ?bool $isAdmin=null, ?bool $authenticated=true, ?string $redirect=null, ?string $redirectVar=null) {
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
			// check admin rights
			if ($isAdmin === true && !($this['currentUSer']['isAdmin'] ?? false)) {
				TµLog::log('Temma/Web', 'WARN', "User is not administrator (while an administrator is expected).");
				throw new TµApplicationException("User is not administrator.", TµApplicationException::UNAUTHORIZED);
			}
			if ($isAdmin === false && ($this['currentUSer']['isAdmin'] ?? false)) {
				TµLog::log('Temma/Web', 'WARN', "User is administrator (while a non-administrator is expected).");
				throw new TµApplicationException("User is administrator.", TµApplicationException::UNAUTHORIZED);
			}
			// check roles
			$authRoles = [];
			if (is_string($role))
				$authRoles[] = $role;
			else if (is_array($role))
				$authRoles = $role;
			if (is_string($roles))
				$authRoles[] = $roles;
			else if (is_array($roles))
				$authRoles = array_merge($authRoles, $roles);
			if ($authRoles) {
				$userRoles = array_fill_keys(($this['currentUser']['roles'] ?? []), true);
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
			$authServices = [];
			if (is_string($service))
				$authServices = [$service];
			else if (is_array($service))
				$authServices = $service;
			if (is_string($services))
				$authServices[] = $services;
			else if (is_array($services))
				$authServices = array_merge($authServices, $services);
			if ($authServices) {
				$userServices = array_fill_keys(($this['currentUser']['services'] ?? []), true);
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

