<?php

/**
 * Auth
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-ctrl_auth
 */

namespace Temma\Controllers;

use \Temma\Base\Log as TµLog;
use \Temma\Attributes\Auth as TµAuth;
use \Temma\Attributes\Methods\Post as TµPost;
use \Temma\Utils\Email as TµEmail;

/**
 * Authentication controller.
 *
 * The code of this controller/preplugin needs two tables in the database.
 * First, a table named "User" with the following fields:
 * * id:       (int) Primary key of the table.
 * * email:    (string) Email address of the user.
 * * roles:    (set) Assigned roles of the user.
 * * services: (set) Services accessible by the user.
 * And another table named "AuthToken" with these fields:
 * * token:      (string) A 64 characters-long SHA-256 hash of the connection token.
 * * expiration: (datetime) Expiration date of the token.
 * * user_id:    (int) Foreign key to the user.
 *
 * Example of tables creation request:
 * ```sql
 * CREATE TABLE User (
 *     id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     date_last_login  DATETIME,
 *     date_last_access DATETIME,
 *     email            TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     name             TINYTEXT,
 *     roles            SET('writer', 'reviewer', 'validator'), -- define your own set values
 *     services         SET('articles', 'news', 'images'), -- define your own set values
 *     PRIMARY KEY (id),
 *     UNIQUE INDEX email (email(255))
 * ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
 *
 * CREATE TABLE AuthToken (
 *     token         CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     expiration    DATETIME NOT NULL,
 *     user_id       INT UNSIGNED NOT NULL,
 *     PRIMARY KEY (token),
 *     INDEX expiration (expiration),
 *     FOREIGN KEY (user_id) REFERENCES User (id) ON DELETE CASCADE
 * ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
 * ```
 *
 * Alternatively, all the names of the databases/tables/fields can be defined in the
 * "x-security" extra-configuration (in the "temma.json" file), under the "auth" key:
 * ```json
 * {
 *     "x-security": {
 *         "auth": {
 *             "userData": {
 *                 "base":     "auth_app",
 *                 "table":    "tUser",
 *                 "id":       "user_id",
 *                 "email":    "user_mail",
 *                 "roles":    "user_roles",
 *                 "services": "user_services",
 *                 "org":      "org"
 *             },
 *             "tokenData": {
 *                 "base":       "auth_app",
 *                 "table":      "tToken",
 *                 "token":      "token_string",
 *                 "expiration": "expiration_date",
 *                 "user_id":    "identifier_user"
 *             }
 *         }
 *     }
 * }
 * ```
 * It is also possible to define DAOs to access the tables:
 * ```json
 * {
 *     "x-security": {
 *         "auth": {
 *             "userDao":  "\\MyApp\\UserDao",
 *             "tokenDao": "\\MyApp\\TokenDao"
 *         }
 *     }
 * }
 * ```
 *
 * It is also possible to configure the email sent to users with the connection token:
 * ```json
 * {
 *     "x-security": {
 *         "auth": {
 *             "emailSender":  "no-reply@mycompany.com",
 *             "emailSubject": "Your connection link",
 *             "emailText":    "Here is your connexion link: %s"
 *         }
 *     }
 * }
 * ```
 *
 * By default, when users are authenticated, they are redirected to the homepage of the
 * website ('/'). You can configure this redirection:
 * ```json
 * {
 *     "x-security": {
 *         "auth": {
 *             "redirection": "/account"
 *         }
 *     }
 * }
 * ```
 */
class Auth extends \Temma\Web\Plugin {
	/** Constant: default maximum number of authentication attempts per hour. */
	private const MAX_AUTH_ATTEMPTS_PER_HOUR = 5;
	/** User DAO. */
	private ?\Temma\Dao\Dao $_userDao = null;
	/** Token DAO. */
	private ?\Temma\Dao\Dao $_tokenDao = null;
	/** Name of the "token" field in the "AuthToken" table. */
	private string $_tokenFieldName = 'token';
	/** Name of the "expiration" field in the "AuthToken" table. */
	private string $_expirationFieldName = 'expiration';
	/** Name of the "user_id" field in the "AuthToken" table. */
	private string $_userIdFieldName = 'user_id';
	/** Redirection URL, used for most of operations. */
	private string $_redirectUrl = '/auth/login';

	/** Preplugin: check if the user is authenticated. */
	public function preplugin() {
		// fetch user ID from session (or from template variable)
		$currentUserId = $this['currentUserId'] ?? $this->_session['currentUserId'];
		if (!$currentUserId)
			return;
		// create DAO objects
		$this->_createDao();
		// fetch user data
		$user = $this->_userDao->get($currentUserId);
		if (!$user) {
			unset($this->_session['currentUserId']);
			return;
		}
		// update user last access
		$this->_userDao->update($user['id'], ['date_last_access' => date('c')]);
		// extract and index roles
		$roles = str_getcsv($user['roles'] ?? '');
		$roles = (($roles[0] ?? null) === null) ? [] : $roles;
		$user['roles'] = array_fill_keys($roles, true);
		// extract and index services
		$services = str_getcsv($user['services'] ?? '');
		$services = (($services[0] ?? null) === null) ? [] : $services;
		$user['services'] = array_fill_keys($services, true);
		// create template variables
		$this['currentUser'] = $user;
		$this['currentUserId'] = $currentUserId;
	}
	/** Initialization. */
	public function __wakeup() {
		if ($this['lang'])
			$this->_redirectUrl = '/' . $this['lang'] . $this->_redirectUrl;
	}
	/** Redirection of the root action. */
	public function __invoke() {
		return $this->_redirect($this->_redirectUrl);
	}
	/** Logout of the currently connected user. */
	#[TµAuth(redirect: '/auth/login')]
	public function logout() {
		// remove the user ID from session
		unset($this->_session['currentUserId']);
		// set the status
		if ($this['currentUser'])
			$this->_session['__authStatus'] = 'logout';
		// redirection
		return $this->_redirect($this->_redirectUrl);
	}
	/**
	 * Login page.
	 * @param	?string	$token	(optional) Token given by the connection mail.
	 */
	#[TµAuth(authenticated: false, redirect: '/')]
	public function login(?string $token=null) {
		$conf = $this->_config->xtra('security', 'auth');
		$this['registration'] = $conf['registration'] ?? false;
		// management of the token
		if ($token)
			$this['token'] = $token;
		// template
		$this->_template('auth/login.tpl');
	}
	/** Process the authentication form and send the magic link by email. */
	#[TµPost]
	#[TµAuth(authenticated: false, redirect: '/')]
	public function authentication() {
		$this->_redirect($this->_redirectUrl);
		// get the configuration
		$conf = $this->_config->xtra('security', 'auth');
		// check email address
		$email = trim($_POST['email'] ?? null);
		if (!$email || filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
		    !checkdnsrr(explode('@', $email)[1], 'MX')) {
			TµLog::log('Temma/App', 'DEBUG', "Invalid email address '$email'.");
			$this->_session['__authStatus'] = 'email';
			return (self::EXEC_HALT);
		}
		// check rate limit
		$attempts = $this->_session['authAttempts'] ?? [];
		$attempts['hour'] ??= '';
		$attempts['nb'] ??= 0;
		if ($attempts['hour'] != date('YmdH')) {
			$attempts['hour'] = date('YmdH');
		} else {
			if ($attempts['nb'] > self::MAX_AUTH_ATTEMPTS_PER_HOUR) {
				TµLog::log('Temma/App', 'DEBUG', "Reached maximum authentication attempts per hour for '$email'.");
				$this->_session['__authStatus'] = 'attempts';
				return (self::EXEC_HALT);
			}
			$attempts['nb']++;
		}
		$this->_session['authAttempts'] = $attempts;
		// check hash (anti-robot system)
		if (!($conf['robotCheckDisabled'] ?? false)) {
			$hash = $_POST['hash'] ?? '';
			if (!$hash || !preg_match('/^([^#]*)#([^#]*)#([^#]*)$/', $hash, $matches)) {
				// empty hash or without '#' character
				$this->_session['__authStatus'] = 'robot';
				return (self::EXEC_HALT);
			}
			$timeDiff = intval($matches[1] ?? 0);
			$loginTime = $matches[2] ?? 0;
			$hash = $matches[3] ?? '';
			$computedHash = md5($timeDiff . ':' . $loginTime . ':' . $email . ':' . $_SERVER['HTTP_USER_AGENT']);
			if ($timeDiff < 2000 || $timeDiff > 3600000 || // [1]
			    abs(time() - ($loginTime / 1000)) > 300 || // [2]
			    $hash != $computedHash                     // [3]
			) {
				// [1] time difference between page loading and form submission
				//     is less than 2 second or greater than 1 hour
				// [2] time difference between form submission and now
				//     is greater than 5 minutes
				// [3] received hash is different from the one calculated
				$this->_session['__authStatus'] = 'robot';
				return (self::EXEC_HALT);
			}
		}
		// create DAO objects
		$this->_createDao();
		// search the user
		$criteria = $this->_userDao->criteria()->equal('email', $email);
		$user = $this->_userDao->get($criteria);
		if (!isset($user['id'])) {
			// user not registered
			TµLog::log('Temma/App', 'DEBUG', "Unknown email address '$email'.");
			// check if the user must be registered
			if (!($conf['registration'] ?? false)) {
				// no automatic registration
				$this->_session['__authStatus'] = 'tokenSent';
				return (self::EXEC_HALT);
			}
			// register the user
			TµLog::log('Temma/App', 'DEBUG', "Register user '$email'.");
			$this->_userDao->create([
				'email'           => $email,
				'date_creation'   => date('c'),
				'date_last_login' => date('c'),
			]);
			$user = $this->_userDao->get($criteria);
			if (!isset($user['id'])) {
				// unable to register the user
				TµLog::log('Temma/App', 'DEBUG', "Unable to register user '$email'.");
				$this->_session['__authStatus'] = 'tokenSent';
				return (self::EXEC_HALT);
			}
		}
		// create the connection token
		$token = bin2hex(random_bytes(8));
		$token = \Temma\Utils\BaseConvert::convertToSpecialBase($token, 16, 31);
		$token = substr($token, 0, 12);
		// store the token in database
		TµLog::log('Temma/Web', 'DEBUG', "Token : '$token'");
		$this->_tokenDao->create([
			$this->_tokenFieldName      => hash('sha256', $token),
			$this->_expirationFieldName => date('Y-m-d H:i:s', strtotime('+1 hour')),
			$this->_userIdFieldName     => $user['id'],
		]);
		// send the token by email
		$this->_sendTokenEmail($email, $token);
		// store the token as a template variable (for testing purpose)
		$this['token'] = $token;
		// redirect
		$this->_session['__authStatus'] = 'tokenSent';
		return (self::EXEC_HALT);
	}
	/**
	 * Token check.
	 * @param	string	$token	Authentication token.
	 */
	#[TµAuth(authenticated: false, redirect: '/')]
	public function check(string $token) {
		$this->_createDao();
		// remove expired tokens
		$this->_tokenDao->remove(
			$this->_tokenDao->criteria()->lessThan($this->_expirationFieldName, date('Y-m-d H:i:s'))
		);
		// get user ID from the token
		$token = hash('sha256', $token);
		$criteria = $this->_tokenDao->criteria()->equal($this->_tokenFieldName, $token);
		$tokenData = $this->_tokenDao->get($criteria);
		// remove the token from database
		$this->_tokenDao->remove($criteria);
		// get the user
		$currentUser = null;
		if (($tokenData['user_id'] ?? null))
			$currentUser = $this->_userDao->get($tokenData['user_id']);
		// check the user (hence the token)
		if (!$currentUser) {
			$this->_session['__authStatus'] = 'badToken';
			return $this->_redirect($this->_redirectUrl);
		}
		// store the user identifier in session
		$currentUserId = $currentUser['id'] ?? null;
		$this->_session['currentUserId'] = $currentUserId;
		// reset the rate limit
		unset($this->_session['authAttempts']);
		// redirection
		$url = $this->_session['authRequestedUrl'];
		unset($this->_session['authRequestedUrl']);
		if (!$url) {
			$conf = $this->_config->xtra('security', 'auth');
			$url = ($conf['redirection'] ?? null) ?: '/';
		}
		return $this->_redirect($url);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Send the connection email.
	 * @param	string	$email	Recipient email address.
	 * @param	string	$token	Connection token.
	 */
	protected function _sendTokenEmail(string $email, string $token) : void {
		$conf = $this->_config->xtra('security', 'auth');
		$sender = $conf['emailSender'] ?? ('contact@' . $_SERVER['SERVER_NAME']);
		$subject = $conf['emailSubject'] ?? 'Your connection link';
		$protocol = ($_SERVER['SERVER_PORT'] == '443') ? 'https' : 'http';
		$authUrl = "$protocol://" . $_SERVER['SERVER_NAME'] . "/auth/check/$token";
		if (($conf['emailContent'] ?? null))
			$message = $conf['emailText'];
		else
			$message = "Hi,

Here is your connection link:
%s

It is valid for 1 hour and can only be used once.

Best regards";
		$message = sprintf($message, $authUrl);
		$this->_loader['\Temma\Utils\Email']->textMail($sender, $email, $subject, $message);
	}
	/** Creates the DAO objects. */
	private function _createDao() : void {
		if ($this->_userDao && $this->_tokenDao)
			return;
		$conf = $this->_config->xtra('security', 'auth');
		// User DAO
		if (($conf['userDao'] ?? null)) {
			$this->_userDao = $this->_loadDao($conf['userDao']);
		} else {
			$params = [
				'cache' => false,
				'table' => 'User',
			];
			if (isset($conf['userData'])) {
				$fields = [];
				foreach ($conf['userData'] as $name => $datum) {
					if ($name == 'base')
						$params['base'] = $datum;
					else if ($name == 'table')
						$params['table'] = $datum;
					else if ($name == 'id') {
						$params['id'] = $datum;
						$fields[$datum] = 'id';
					} else if ($name == $datum)
						$fields[] = $name;
					else
						$fields[$datum] = $name;
				}
				if ($fields) {
					foreach (['id', 'date_creation', 'date_last_login', 'date_last_access', 'email', 'name', 'roles', 'services'] as $key) {
						if (!isset($conf['userData'][$key]))
							$fields[] = $key;
					}
					$params['fields'] = $fields;
				}
			}
			$this->_userDao = $this->_loadDao($params);
		}
		// Token DAO
		if (($conf['tokenDao'] ?? null)) {
			$this->_tokenDao = $this->_loadDao($conf['tokenDao']);
		} else {
			$params = [
				'cache' => false,
				'table' => 'AuthToken',
			];
			if (isset($conf['tokenData'])) {
				if (isset($conf['tokenData']['base']))
					$params['base'] = $conf['tokenData']['base'];
				if (isset($conf['tokenData']['table']))
					$params['table'] = $conf['tokenData']['table'];
				$params['fields'] = [];
				if (isset($conf['tokenData']['token'])) {
					$params['id'] = $conf['tokenData']['token'];
					$params['fields'][$conf['tokenData']['token']] = 'token';
					$this->_tokenFieldName = $conf['tokenData']['token'];
				} else {
					$params['id'] = 'token';
					$params['fields'][] = 'token';
				}
				if (isset($conf['tokenData']['expiration'])) {
					$params['fields'][$conf['tokenData']['expiration']] = 'expiration';
					$this->_expirationFieldName = $conf['tokenData']['expiration'];
				} else
					$params['fields'][] = 'expiration';
				if (isset($conf['tokenData']['user_id'])) {
					$params['fields'][$conf['tokenData']['user_id']] = 'user_id';
					$this->_userIdFieldName = $conf['tokenData']['user_id'];
				} else
					$params['fields'][] = 'user_id';
			}
			$this->_tokenDao = $this->_loadDao($params);
		}
	}
}

