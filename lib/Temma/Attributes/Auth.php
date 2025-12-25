<?php

/**
 * Auth
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_auth
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define the user authorizations needed to access to an action.
 *
 * It uses a 'currentUser' template variable, which is an associative array with at least the following keys:
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
 * - No access for rookies:
 * #[TµAuth('-rookie')]
 *
 * - Access to users with the 'manager' role, but without the 'rookie' role:
 * #[TµAuth(['manager', '-rookie'])]
 *
 * - The same as the previous one:
 * #[TµAuth(role: ['manager', '-rookie'])]
 *
 * - Access to users who have access rights on images:
 * #[TµAuth(service: 'image')]
 *
 * - No access for user who have access rights on videos:
 * #[TµAuth(service: '-video')]
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
 * - Redirects unauthenticated users, and store the requested URL in the 'authRequestedUrl' session variable:
 * #[TµAuth(storeUrl: true)]
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
	 * @param	bool			$storeUrl	(optional) Tell if the requested URL must be stored in the "authRequestedUrl" session variable. (default value: false)
	 */
	public function __construct(
		protected null|string|array $role=null,
		protected null|string|array $service=null,
	        protected ?bool $authenticated=true,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
		protected bool $storeUrl=false,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If the user is not authorized.
	 * @throws	\Temma\Exceptions\FlowHalt	If the user is not authorized and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		$authError = null;
		$authErrorData = null;
		try {
			$authVariable = $this->_config->xtra('security', 'authVariable', 'currentUser');
			// check authentication
			if ($this->authenticated === true && !($this[$authVariable]['id'] ?? false)) {
				$authError = 'not_authenticated';
				TµLog::log('Temma/Web', 'WARN', "User is not authenticated (while an authenticated user is expected).");
				throw new TµApplicationException("User is not authenticated.", TµApplicationException::AUTHENTICATION);
			}
			if ($this->authenticated === false && ($this[$authVariable]['id'] ?? false)) {
				$authError = 'authenticated';
				TµLog::log('Temma/Web', 'WARN', "User is authenticated (while a unauthenticated user is expected).");
				throw new TµApplicationException("User is authenticated.", TµApplicationException::AUTHENTICATION);
			}
			// check roles
			if ($this->role) {
				$authRoles = is_array($this->role) ? $this->role : [$this->role];
				$userRoles = $this[$authVariable]['roles'] ?? [];
				$found = false;
				foreach ($authRoles as $role) {
					// manage forbidden role
					if (mb_substr($role, 0, 1) == '-') {
						$role = mb_substr($role, 1);
						if (($userRoles[$role] ?? false)) {
							$authError = 'forbidden_role';
							$authErrorData = $role;
							TµLog::log('Temma/Web', 'WARN', "User has the forbidden role '$role'.");
							throw new TµApplicationException("User has the forbidden role '$role'.", TµApplicationException::UNAUTHORIZED);
						}
					}
					// manage searched role
					if (isset($userRoles[$role])) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					$authError = 'no_role';
					TµLog::log('Temma/Web', 'WARN', "User has no matching role.");
					throw new TµApplicationException("User has no matching role.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check services
			if ($this->service) {
				$authServices = is_array($this->service) ? $this->service : [$this->service];
				$userServices = $this[$authVariable]['services'] ?? [];
				$found = false;
				foreach ($authServices as $service) {
					// manage forbidden service
					if (mb_substr($service, 0, 1) == '-') {
						$service = mb_substr($service, 1);
						if (($userServices[$service] ?? false)) {
							$authError = 'forbidden_service';
							$authErrorData = $service;
							TµLog::log('Temma/Web', 'WARN', "User has access to the forbidden service '$service'.");
							throw new TµApplicationException("User has access to the forbidden service '$service'.", TµApplicationException::UNAUTHORIZED);
						}
					}
					// manage search service
					if (isset($userServices[$service])) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					$authError = 'no_service';
					TµLog::log('Temma/Web', 'WARN', "User has no matching access.");
					throw new TµApplicationException("User has no matching access.", TµApplicationException::UNAUTHORIZED);
				}
			}
		} catch (TµApplicationException $e) {
			// store URL
			if ($this->storeUrl)
				$this->_session->set('authRequestedUrl', $this['URL']);
			// manage redirection URL
			$url = $this->redirect ?:                                  // direct URL
			       $this[$this->redirectVar] ?                         // template variable
			       $this->_config->xtra('security', 'authRedirect') ?: // specific configuration
			       $this->_config->xtra('security', 'redirect');       // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_session['__authError'] = $authError;
				if ($authErrorData)
					$this->_session['__authErrorData'] = $authErrorData;
				$this->_redirect($url);
				throw new \Temma\Exceptions\FlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}

