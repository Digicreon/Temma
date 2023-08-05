<?php

/**
 * Auth
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
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
 * * isAdmin:  (boolean) True if the user is a super-administrator (optional).
 * * roles:    (set) Assigned roles of the user.
 * * services: (set) Services accessible by the user.
 * And another table named "AuthToken" with these fields:
 * * token:      (string) A 12 characters-long connection token.
 * * expiration: (datetime) Expiration date of the token.
 * * user_id:    (int) Foreign key to the user.
 *
 * Example of tables creation request:
 * ```sql
 * CREATE TABLE User (
 *     id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *     date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     email           TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     isAdmin         BOOLEAN NOT NULL DEFAULT FALSE,
 *     roles           SET('writer', 'reviewer', 'validator'), -- define your own set values
 *     services        SET('articles', 'news', 'images'), -- define your own set values
 *     PRIMARY KEY (id),
 *     UNIQUE INDEX email (email(255))
 * ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
 *
 * CREATE TABLE AuthToken (
 *     token         CHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     expiration    DATETIME NOT NULL,
 *     user_id       INT UNSIGNED NOT NULL,
 *     PRIMARY KEY token (token(40)),
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
 *                 "isAdmin":  "user_admin",
 *                 "roles":    "user_roles",
 *                 "services": "user_services"
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
 *         "authRedirect": "/account"
 *     }
 * }
 * ```
 */
class Auth extends \Temma\Web\Plugin {
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

	/** Preplugin: check if the user is authenticated. */
	public function preplugin() {
		// fetch user ID from session
		$currentUserId = $this->_session['currentUserId'];
		if (!$currentUserId)
			return;
		// create DAO objects
		$this->_createDao();
		// fetch user data
		$user = $this->_userDao->get($currentUserId);
		if (!$user)
			return;
		$user['roles'] = str_getcsv($user['roles']);
		$user['services'] = str_getcsv($user['services']);
		$user['isAdmin'] = ($user['isAdmin'] ?? null) ? true : false;
		// create template variable
		$this['currentUser'] = $user;
	}
	/** Redirection of the root action. */
	public function __invoke() {
		return $this->_redirect('/auth/login');
	}
	/** Logout of the currently connected user. */
	#[TµAuth(redirect: '/auth/login')]
	public function logout() {
		// remove the user ID from session
		unset($this->_session['currentUserId']);
		// set the status
		if ($this['currentUser'])
			$this->_session['authStatus'] = 'logout';
		// redirection
		return $this->_redirect('/auth/login');
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
		// retrieve any status message
		$this['authStatus'] = $this->_session['authStatus'];
		unset($this->_session['authStatus']);
		// template
		$this->_template('auth/login.tpl');
	}
	/** Authentication. */
	#[TµPost]
	#[TµAuth(authenticated: false, redirect: '/')]
	public function authentication() {
		$this->_redirect('/auth/login');
		// check email address
		$email = trim($_POST['email'] ?? null);
		if (!$email || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			TµLog::log('Temma/App', 'DEBUG', "Invalid email address '$email'.");
			$this->_session['authStatus'] = 'email';
			return (self::EXEC_HALT);
		}
		// check hash (anti-robot system)
		$hash = $_POST['hash'] ?? '';
		if (!$hash || !preg_match('/^([^#]*)#([^#]*)#([^#]*)$/', $hash, $matches)) {
			// empty hash or without '#' character
			$this->_session['authStatus'] = 'robot';
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
			$this->_session['authStatus'] = 'robot';
			return (self::EXEC_HALT);
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
			$conf = $this->_config->xtra('security', 'auth');
			if (!($conf['registration'] ?? false)) {
				// no automatic registration
				$this->_session['authStatus'] = 'tokenSent';
				return (self::EXEC_HALT);
			}
			// register the user
			TµLog::log('Temma/App', 'DEBUG', "Register user '$email'.");
			$this->_userDao->create([
				'email' => $email,
			]);
			$user = $this->_userDao->get($criteria);
			if (!isset($user['id'])) {
				// unable to register the user
				TµLog::log('Temma/App', 'DEBUG', "Unable to register user '$email'.");
				$this->_session['authStatus'] = 'tokenSent';
				return (self::EXEC_HALT);
			}
		}
		// create the connection token
		$token = bin2hex(random_bytes(8));
		$token = \Temma\Utils\BaseConvert::convertToSpecialBase($token, 16, 31);
		$token = substr($token, 0, 12);
		// store the token in database
		$this->_tokenDao->create([
			$this->_tokenFieldName      => \Temma\Utils\BaseConvert::convert(hash('sha256', $token), 16, 85),
			$this->_expirationFieldName => date('Y-m-d H:i:s', strtotime('+1 hour')),
			$this->_userIdFieldName     => $user['id'],
		]);
		// send the token by email
		$this->_sendTokenEmail($email, $token);
		// redirect
		$this->_session['authStatus'] = 'tokenSent';
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
		$token = \Temma\Utils\BaseConvert::convert(hash('sha256', $token), 16, 85);
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
			$this->_session['authStatus'] = 'badToken';
			return $this->_redirect('/auth/login');
		}
		// store the user identifier in session
		$currentUserId = $currentUser['id'] ?? null;
		$this->_session['currentUserId'] = $currentUserId;
		// redirection
		$conf = $this->_config->xtra('security', 'auth');
		$url = ($conf['redirection'] ?? null) ?: '/';
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
				foreach (['id', 'email', 'isAdmin', 'roles', 'services'] as $key) {
					if (!isset($conf['userData'][$key]))
						$fields[] = $key;
				}
				$params['fields'] = $fields;
			}
		}
		$this->_userDao = $this->_loadDao($params);
		// Token DAO
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

