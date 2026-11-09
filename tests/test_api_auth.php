#!/usr/bin/php
<?php

/**
 * Validation script for the authentication of the Api plugin.
 * Checks the bearer token generation, and the user authentication with HTTP Basic credentials
 * and with bearer tokens (valid, invalid, malformed), using fake DAOs.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** FAKE DAOS ********** */
/** Fake DAO returning data from an array, without database. */
class FakeDao extends \Temma\Dao\Dao {
	/** Stored data. */
	public array $data = [];
	/** Number of calls to update(). */
	public int $updates = 0;
	/**
	 * Constructor.
	 * @param	array	$data	Stored data, indexed by identifier.
	 */
	public function __construct(array $data) {
		$this->data = $data;
	}
	/**
	 * Return an item.
	 * @param	int|string|array|\Temma\Dao\Criteria	$id	Identifier.
	 * @return	array	The item, or an empty array.
	 */
	public function get(int|string|array|\Temma\Dao\Criteria $id) : array {
		return ($this->data[$id] ?? []);
	}
	/**
	 * Count the updates.
	 * @param	null|int|string|array|\Temma\Dao\Criteria	$criteria	Criteria.
	 * @param	array					$fields		Fields.
	 * @param	null|bool|string|array			$sort		Sort.
	 * @param	?int					$limit		Limit.
	 * @return	int	Always 1.
	 */
	public function update(null|int|string|array|\Temma\Dao\Criteria $criteria=null, array $fields=[],
	                       null|bool|string|array $sort=null, ?int $limit=null) : int {
		$this->updates++;
		return (1);
	}
}

/* ********** TEST FRAMEWORK ********** */
$nbTests = 0;
$nbFailed = 0;
/**
 * Check a condition.
 * @param	string	$label		Test label.
 * @param	bool	$condition	Condition.
 */
function check(string $label, bool $condition) : void {
	global $nbTests, $nbFailed;
	$nbTests++;
	if ($condition) {
		print(TµAnsi::color('green', 'OK') . "   $label\n");
		return;
	}
	$nbFailed++;
	print(TµAnsi::color('red', 'FAIL') . " $label\n");
}

/* ********** DATA ********** */
$keys = \Temma\Plugins\Api::generateKeys();
$userDao = new FakeDao([
	12 => [
		'id'       => 12,
		'email'    => 'albert@einstein.com',
		'name'     => 'Albert',
		'roles'    => 'admin,writer',
		'services' => 'articles',
	],
]);
$apiKeyDao = new FakeDao([
	$keys['public'] => [
		'public_key'  => $keys['public'],
		'private_key' => password_hash($keys['private'], PASSWORD_BCRYPT),
		'name'        => 'Default',
		'user_id'     => 12,
	],
]);

/**
 * Run the authentication of the Api plugin, with the given $_SERVER values.
 * @param	array	$server	Values added to $_SERVER.
 * @return	?array	The authenticated user, or null.
 */
function authenticate(array $server) : ?array {
	global $userDao, $apiKeyDao;
	foreach (['PHP_AUTH_USER', 'PHP_AUTH_PW', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key)
		unset($_SERVER[$key]);
	$_SERVER = array_merge($_SERVER, $server);
	$loader = new \Temma\Base\Loader([
		'response' => new \Temma\Web\Response(),
	]);
	$plugin = new \Temma\Plugins\Api($loader);
	$reflection = new \ReflectionClass($plugin);
	$reflection->getProperty('_userDao')->setValue($plugin, $userDao);
	$reflection->getProperty('_apiKeyDao')->setValue($plugin, $apiKeyDao);
	$reflection->getMethod('_authenticateUser')->invoke($plugin);
	return ($plugin['currentUser']);
}

/* ********** TOKEN GENERATION ********** */
print(TµAnsi::color('blue', "Token generation\n"));
check('generateKeys() returns a token', isset($keys['token']));
check('token = keysToToken(public, private)', $keys['token'] === \Temma\Plugins\Api::keysToToken($keys['public'], $keys['private']));
check('token only contains base64url characters', (bool)preg_match('/^[A-Za-z0-9_-]+$/', $keys['token']));
$decoded = base64_decode(strtr($keys['token'], '-_', '+/'), true);
check('token decodes to "public:private"', $decoded === $keys['public'] . ':' . $keys['private']);

/* ********** HTTP BASIC ********** */
print(TµAnsi::color('blue', "HTTP Basic authentication\n"));
$user = authenticate(['PHP_AUTH_USER' => $keys['public'], 'PHP_AUTH_PW' => $keys['private']]);
check('valid keys: user authenticated', ($user['id'] ?? null) === 12);
check('valid keys: roles indexed', ($user['roles'] ?? null) === ['admin' => true, 'writer' => true]);
check('valid keys: services indexed', ($user['services'] ?? null) === ['articles' => true]);
check('wrong private key: not authenticated', authenticate(['PHP_AUTH_USER' => $keys['public'], 'PHP_AUTH_PW' => 'wrong']) === null);
check('unknown public key: not authenticated', authenticate(['PHP_AUTH_USER' => 'unknown', 'PHP_AUTH_PW' => $keys['private']]) === null);
check('no credentials: not authenticated', authenticate([]) === null);

/* ********** BEARER TOKEN ********** */
print(TµAnsi::color('blue', "Bearer token authentication\n"));
check('valid token: user authenticated', (authenticate(['HTTP_AUTHORIZATION' => 'Bearer ' . $keys['token']])['id'] ?? null) === 12);
check('valid token, lowercase "bearer": user authenticated', (authenticate(['HTTP_AUTHORIZATION' => 'bearer ' . $keys['token']])['id'] ?? null) === 12);
check('valid token with padding: user authenticated', (authenticate(['HTTP_AUTHORIZATION' => 'Bearer ' . $keys['token'] . '=='])['id'] ?? null) === 12);
check('valid token in REDIRECT_HTTP_AUTHORIZATION: user authenticated', (authenticate(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $keys['token']])['id'] ?? null) === 12);
$wrongToken = \Temma\Plugins\Api::keysToToken($keys['public'], 'wrong');
check('wrong private key: not authenticated', authenticate(['HTTP_AUTHORIZATION' => "Bearer $wrongToken"]) === null);
$noColon = rtrim(strtr(base64_encode($keys['public'] . $keys['private']), '+/', '-_'), '=');
check('token without colon: not authenticated', authenticate(['HTTP_AUTHORIZATION' => "Bearer $noColon"]) === null);
check('invalid characters: not authenticated', authenticate(['HTTP_AUTHORIZATION' => 'Bearer abc!def']) === null);
check('empty token: not authenticated', authenticate(['HTTP_AUTHORIZATION' => 'Bearer ']) === null);
check('Basic scheme in header only: not authenticated', authenticate(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($keys['public'] . ':' . $keys['private'])]) === null);
check('Basic credentials take precedence over bearer token', (authenticate(['PHP_AUTH_USER' => $keys['public'], 'PHP_AUTH_PW' => $keys['private'], 'HTTP_AUTHORIZATION' => "Bearer $wrongToken"])['id'] ?? null) === 12);

/* ********** RESULT ********** */
print("\n");
if ($nbFailed) {
	print(TµAnsi::color('red', "$nbFailed/$nbTests tests failed.\n"));
	exit(1);
}
print(TµAnsi::color('green', "All $nbTests tests passed.\n"));
