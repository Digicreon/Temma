<?php

/**
 * User
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023-2024, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-cli_user
 */

namespace Temma\Cli;

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Term as TµTerm;

/**
 * User management CLI controller.
 *
 * This objet is used to manage users from the command line.
 * The users are managed in a way compatible with the Auth controller/plugin
 * and the Auth attribute.
 *
 * @see	\Temma\Controllers\Auth
 * @see	\Temma\Attributes\Auth
 */
class User extends \Temma\Web\Controller {
	/** User DAO. */
	private ?\Temma\Dao\Dao $_userDao = null;

	/** Init. */
	public function __wakeup() {
		// get configuration
		$conf = $this->_config->xtra('security', 'auth');
		// create User DAO
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
		// check if the User table exists
		if (!$this->_userDao->tableExists()) {
			$tableName = $this->_userDao->getTableName();
			print(TµAnsi::style("<info>The table '$tableName' doesn't exist.</info>"));
			print(TµAnsi::style("Do you want to create it? [Y/n]\n"));
			$res = TµTerm::input();
			if ($res && $res != 'y' && $res != 'Y') {
				print(TµAnsi::style("<alert marginTop='1'>This script needs the table '$tableName'. Abort.</alert>"));
				exit(1);
			}
			$idField = $this->_userDao->getFieldName('id');
			$emailField = $this->_userDao->getFieldName('email');
			$sql = "CREATE TABLE $tableName (
					$idField							INT UNSIGNED NOT NULL AUTO_INCREMENT,
					" . $this->_userDao->getFieldName('date_creation') . "		DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					" . $this->_userDao->getFieldName('date_last_login') . "	DATETIME,
					" . $this->_userDao->getFieldName('date_last_access') . "	DATETIME,
					$emailField							TINYTEXT CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
					" . $this->_userDao->getFieldName('name') . "			TINYTEXT,
					" . $this->_userDao->getFieldName('roles') . "			SET('admin'),
					" . $this->_userDao->getFieldName('services') . "		SET('admin'),
					PRIMARY KEY ($idField),
					UNIQUE INDEX $emailField ($emailField(255))
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
			$this->_userDao->getDatabase()->exec($sql);
			print("\n");
			print(TµAnsi::style("<success>The table '$tableName' has been created.</success>"));
			print(TµAnsi::style("<info>You should customize the 'roles' and 'services' fields.</info>"));
		}
	}
	/**
	 * List users
	 * @param	?string	$email			(optional) Email address prefix.
	 * @param	?string	$name			(optional) Name prefix.
	 * @param	?string	$role			(optional) User role.
	 * @param	?string	$service		(optional) User service.
	 * @param	?string	$dateCreationFrom	(optional) Beginning of date creation period (format YYYY-MM-DD).
	 * @param	?string	$dateCreationTo		(optional) Ending of date creation period (format YYYY-MM-DD).
	 * @param	?string	$dateLastLoginFrom	(optional) Beginning of last authentication date period (format YYYY-MM-DD).
	 * @param	?string	$dateLastLoginTo	(optional) Ending of last authentication date period (format YYYY-MM-DD).
	 * @param	?string $dateLastAccessFrom	(optional) Beginning of last access date period (format YYYY-MM-DD).
	 * @param	?string $dateLastAccessTo	(optionel) Ending of last access date period (format YYYY-MM-DD).
	 * @param	string	$sort			(optional) 'id', 'name', 'date_creation', 'date_last_login' or 'date_last_access'. (defaults to 'id')
	 */
	public function list(?string $email=null, ?string $name=null, ?string $role=null, ?string $service=null,
	                     ?string $dateCreationFrom=null, ?string $dateCreationTo=null,
	                     ?string $dateLastLoginFrom=null, ?string $dateLastLoginTo=null,
	                     ?string $dateLastAccessFrom=null, ?string $dateLastAccessTo=null, string $sort='id') {
		$criteria = $this->_userDao->criteria();
		if ($email)
			$criteria->like('email', "$email%");
		if ($role)
			$criteria->like('roles', "%$role%");
		if ($service)
			$criteria->like('services', "%$service%");
		if ($dateCreationFrom)
			$criteria->greaterOrEqualTo('date_creation', "$dateCreationFrom 00:00:00");
		if ($dateCreationTo)
			$criteria->lessOrEqualTo('date_creation', "$dateCreationTo 23:59:59");
		if ($dateLastLoginFrom)
			$criteria->greaterOrEqualTo('date_last_login', "$dateLastLoginFrom 00:00:00");
		if ($dateLastLoginTo)
			$criteria->lessOrEqualTo('date_last_login', "$dateLastLoginTo 23:59:59");
		if ($dateLastAccessFrom)
			$criteria->greaterOrEqualTo('date_last_access', "$dateLastAccessFrom 00:00:00");
		if ($dateLastAccessTo)
			$criteria->lessOrEqualTo('date_last_access', "$dateLastAccessTo 23:59:59");
		if (str_starts_with($sort, 'date'))
			$sort = [$sort => 'desc'];
		$users = $this->_userDao->search($criteria, $sort);
		foreach ($users as $user) {
			print(TµAnsi::faint("─────────────────────────────\n"));
			print('id:          ' . TµAnsi::color('yellow', $user['id']) . "\n");
			print('name:        ' . TµAnsi::color('white', $user['name']) . "\n");
			print('creation:    ' . TµAnsi::faint($user['date_creation']) . "\n");
			print('last login:  ' . TµAnsi::faint($user['date_last_login']) . "\n");
			print('last access: ' . TµAnsi::faint($user['date_last_login']) . "\n");
			print('email:       ' . TµAnsi::color('green', $user['email']) . "\n");
			if ($user['roles']) {
				$roles = str_getcsv($user['roles']);
				print("roles:\n");
				foreach ($roles as $role)
					print('           - ' . TµAnsi::color('blue', $role) . "\n");
			}
			if ($user['services']) {
				$services = str_getcsv($user['services']);
				print("services:\n");
				foreach ($services as $service)
					print('           - ' . TµAnsi::color('red', $service) . "\n");
			}
			print("\n");
		}
	}
	/**
	 * Add a new user.
	 * @param	string	$email		Email address.
	 * @param	?string	$name		(optional) User role.
	 * @param	?string	$roles		(optional) User role(s).
	 * @param	?string	$services	(optional) User service(s).
	 */
	public function add(string $email, ?string $name=null, ?string $roles=null, ?string $services=null) {
		$data = [
			'date_creation' => date('c'),
			'email'         => $email,
		];
		if (isset($name))
			$data['name'] = $name;
		if (isset($roles))
			$data['roles'] = $roles;
		if (isset($services))
			$data['services'] = $services;
		$id = $this->_userDao->create($data);
		print("Identifier: $id\n");
		print(TµAnsi::color('green', "Done\n"));
	}
	/**
	 * Remove a user.
	 * One of the parameters ('id' and 'email') must be given.
	 * @param	?int	$id	(optional) User identifier.
	 * @param	?string	$email	(optional) Email address.
	 */
	public function remove(?int $id=null, ?string $email=null) {
		if (!$id && !$email) {
			print(TµAnsi::color('red', "Need 'id' or 'email' parameter.\n"));
			exit(1);
		}
		if ($id)
			$this->_userDao->remove($id);
		else
			$this->_userDao->remove($this->_userDao->criteria()->equal('email', $email));
		print(TµAnsi::color('green', "Done\n"));
	}
}

