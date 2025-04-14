<?php

/**
 * Api
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;

/**
 * Plugin used to manage API access.
 *
 * API URLs must be in the form "/v[version number]/[controller]/[action]".
 * Examples:
 * - /v1/user/list
 * - /v2/message/add
 *
 * This plugin uses HTTP Basic authentication method to authenticate users.
 * If a pair of public/private keys are sent (as HTTP login/password), they
 * are used to fetch the user and his/her access rights.
 * Then, controllers can use the Auth attribute (see https://www.temma.net/documentation/attribut-auth)
 * to restrict access to their features, depending on user roles and services.
 *
 * The authentication process needs two tables in the database.
 * First, a table named "User" with the following fields:
 * - id:               (int) Primary key of the table.
 * - date_creation:    (datetime) User creation date.
 * - date_last_login:  (datetime) Date of user's last authentication.
 * - date_last_access: (datetime) Date of user's last access.
 * - email:            (string) Email address of the user.
 * - name:             (string) User name.
 * - roles:            (set) Assigned roles of the user.
 * - services:         (set) Services accessible by the user.
 * And another table named "ApiKey" with these fields:
 * - public_key:  (string) A 32 characters-long public key.
 * - private_key: (string) A 64 characters-long SHA-256 hash of the private key.
 * - user_id:     (int) Foreign key to the user.
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
 *     roles            SET('admin', 'writer', 'reviewer'), -- define your own set values
 *     services         SET('articles', 'news', 'images'), -- define your own set values
 *     PRIMARY KEY (id),
 *     UNIQUE INDEX email (email(255))
 * ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
 *
 * CREATE TABLE ApiKey (
 *     public_key    CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     private_key   CHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 *     name          TINYTEXT NOT NULL DEFAULT ('Default'),
 *     user_id       INT UNSIGNED NOT NULL,
 *     PRIMARY KEY (public_key),
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
 *                 "services": "user_services"
 *             },
 *             "apiKeyData": {
 *                 "base":        "auth_app",
 *                 "table":       "tToken",
 *                 "public_key":  "pubkey_string",
 *                 "private_key": "privkey_string",
 *                 "user_id":     "identifier_user"
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
 *             "userDao":   "\\MyApp\\UserDao",
 *             "apiKeyDao": "\\MyApp\\ApiKeyDao"
 *         }
 *     }
 * }
 * ```
 *
 * @link	https://www.temma.net/documentation/plugin-api
 */
class Api extends \Temma\Web\Plugin {
	/** User DAO. */
	private ?\Temma\Dao\Dao $_userDao = null;
	/** ApiKey DAO. */
	private ?\Temma\Dao\Dao $_apiKeyDao = null;

        /**
	 * Preplugin method.
	 * @return	mixed	EXEC_HALT if a redirection is needed. EXEC_FORWARD otherwise.
	 * @throws	\Temma\Exceptions\FlowHalt	If the URL is not well formed.
	 */
        public function preplugin() {
		// check if the current user has already been fetch by another plugin
		if ($this['currentUser']) {
			TµLog::log('Temma/Web', 'WARN', "The user has already been defined.");
			return;
		}
		// special headers
		header('Access-Control-Allow-Origin: *');
		// JSON view
		$this->_view('\Temma\Views\Json');
		// manage namespace using the requested API version number
		$this->_manageUrl();
		// check authentication
		$this->_authenticateUser();
        }
	/**
	 * Static method used to generate a public/private couple of keys.
	 * @return	array	Associative array with a 'public' and a 'private' keys.
	 */
	static public function generateKeys() : array {
		// public key
		$public = '';
		while (mb_strlen($public, 'ascii') < 32) {
			$key = bin2hex(random_bytes(64));
			$key = hash('sha256', $key);
			$key = \Temma\Utils\BaseConvert::convertToSpecialBase($key, 16, 71);
			$public .= $key;
		}
		$public = mb_substr($public, 0, 32);
		// private key
		$private = '';
		while (mb_strlen($private, 'ascii') < 64) {
			$key = bin2hex(random_bytes(64));
			$key = hash('sha256', $key);
			$key = \Temma\Utils\BaseConvert::convertToSpecialBase($key, 16, 71);
			$private .= $key;
		}
		$private = mb_substr($private, 0, 64);
		return ([
			'public'  => $public,
			'private' => $private,
		]);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Manage the URL: extract the API version and shift all other elements
	 * (the action becomes the controller, the first parameter becomes the action).
	 * Adjust the name of the controller with the version as namespace prefix.
	 * @throws	\Temma\Exceptions\FlowHalt	If the URL is ont well formed.
	 */
	private function _manageUrl() : void {
		// get the version ("v1", "v2"...)
		$version = $this->_loader->request->getController();
		// check the version
		if ($version[0] != 'v' || !ctype_digit(mb_substr($version, 1))) {
			TµLog::log('Temma/Web', 'WARN', "Incorrect API version number '$version'.");
			$this->_httpError(400);
			throw new \Temma\Exceptions\FlowHalt();
		}
		// manage the URL
		$url = $this['URL'];
		$url = mb_substr($url, mb_strlen($version) + 1); 
		$this['URL'] = $url;
		$controller = $this->_loader->request->getAction();
		$this['CONTROLLER'] = $controller;
		$params = $this->_loader->request->getParams();
		$action = array_shift($params);
		$this->_loader->request->setAction($action);
		$this['ACTION'] = $action;
		$this->_loader->request->setParams($params);
		// set the controller's namespace prefix
		$ctrlName = $version . "\\" . $controller;
		$this->_loader->request->setController($ctrlName);
	}
	/** Fetch the user from the given API keys. */
	private function _authenticateUser() : void {
		// create DAO objects
		$this->_createDao();
		// get the keys from HTTP Basic Authentication
		$publicKey = $_SERVER['PHP_AUTH_USER'] ?? null;
		$privateKey = $_SERVER['PHP_AUTH_PW'] ?? null;
		if (!$publicKey || !$privateKey)
			return;
		// get key data from database
		$apiKeyData = $this->_apiKeyDao->get($publicKey);
		// check private key
		if (!password_verify($privateKey, ($apiKeyData['private_key'] ?? '')))
			return;
		// check user ID
		if (!($apiKeyData['user_id'] ?? null))
			return;
		// fetch user data
		$user = $this->_userDao->get($apiKeyData['user_id']);
		if (!$user)
			return;
		// update user last access
		$this->_userDao->update($user['id'], ['date_last_access' => date('c')]);
		// extract and index roles
		$roles = str_getcsv($user['roles']);
		$roles = (($roles[0] ?? null) === null) ? [] : $roles;
		$user['roles'] = array_fill_keys($roles, true);
		// extract and index services
		$services = str_getcsv($user['services']);
		$services = (($services[0] ?? null) === null) ? [] : $services;
		$user['services'] = array_fill_keys($services, true);
		// create template variables
		$this['currentUser'] = $user;
		$this['currentUserId'] = $apiKeyData['user_id'];
	}
	/** Load the User and ApiKey DAOs. */
	private function _createDao() : void {
		if ($this->_userDao && $this->_apiKeyDao)
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
		// API key DAO
		if (($conf['apiKeyDao'] ?? null)) {
			$this->_apiKeyDao = $this->_loadDao($conf['apiKeyDao']);
		} else {
			$params = [
				'cache' => false,
				'table' => 'ApiKey',
			];
			if (isset($conf['apiKeyData']['base']))
				$params['base'] = $conf['apiKeyData']['base'];
			if (isset($conf['apiKeyData']['table']))
				$params['table'] = $conf['apiKeyData']['table'];
			$params['fields'] = [];
			if (isset($conf['apiKeyData']['public_key'])) {
				$params['id'] = $conf['apiKeyData']['public_key'];
				$params['fields'][$conf['apiKeyData']['public_key']] = 'public_key';
			} else {
				$params['id'] = 'public_key';
				$params['fields'][] = 'public_key';
			}
			if (isset($conf['apiKeyData']['private_key']))
				$params['fields'][$conf['apiKeyData']['private_key']] = 'private_key';
			else
				$params['fields'][] = 'private_key';
			if (isset($conf['apiKeyData']['name']))
				$params['fields'][$conf['apiKeyData']['name']] = 'name';
			else
				$params['fields'][] = 'name';
			if (isset($conf['apiKeyData']['user_id']))
				$params['fields'][$conf['apiKeyData']['user_id']] = 'user_id';
			else
				$params['fields'][] = 'user_id';
			$this->_apiKeyDao = $this->_loadDao($params);
		}
	}
}

