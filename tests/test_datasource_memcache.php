#!/usr/bin/php
<?php

/**
 * Validation script for the Memcache data source:
 * - "falsy" values (0, "0", false, "", []) are not taken for missing values;
 * - mGet()/mRead() return the user keys, with null for missing keys;
 * - mRemove() removes the given keys; clear() invalidates a group of keys;
 * - mSet()/mWrite() store data with an expiration, remove the keys associated to null, and return the number
 *   of written data (removed keys are not counted);
 * - isSet() returns false when the server is not reachable;
 * - a string default value is never called as a function;
 * - a disabled datasource behaves like an always-empty cache (default value or callback result returned, nothing stored).
 *
 * The tests which don't need a server use an unreachable server (127.0.0.1:1).
 * The other tests are executed only if a Memcache DSN is given in the TEMMA_TEST_MEMCACHE environment variable
 * (e.g. "memcache://localhost:11211"; the super-prefix "temma-test" is added if the DSN has none). They use keys
 * starting with "temma-test-<pid>-", and groups (see setPrefix()) named "temma-test-<pid>-...". All the created
 * keys are removed at the end, including the salt keys of the groups and the keys invalidated by clear().
 * The expiration test waits 2 seconds.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
if (!extension_loaded('memcached')) {
	print(TµAnsi::color('yellow', "The 'memcached' PHP extension is not loaded, tests skipped.") . "\n");
	exit(0);
}

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
/**
 * Executes a test and displays its result.
 * @param	string		$label	Description of the test.
 * @param	callable	$test	Function which returns true if the test is successful.
 *					If it throws an exception, the test fails and the exception is displayed.
 */
function check(string $label, callable $test) : void {
	global $count, $failed;
	$count++;
	$error = null;
	try {
		$ok = ($test() === true);
	} catch (\Throwable $t) {
		$ok = false;
		$error = get_class($t) . ': ' . $t->getMessage();
	}
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
	if ($error)
		print('   ' . TµAnsi::faint($error) . "\n");
}

/**
 * Read the value of a protected property.
 * @param	object	$object	The object.
 * @param	string	$name	Name of the property.
 * @return	mixed	The property's value.
 */
function property(object $object, string $name) : mixed {
	return ((new \ReflectionProperty($object, $name))->getValue($object));
}

/* ********** TESTS WITHOUT SERVER ********** */
print(TµAnsi::bold("Memcache data source, unreachable server\n"));
$down = \Temma\Datasources\Memcache::factory('memcache://127.0.0.1:1#temma-test');
check('isSet() returns false when the server is not reachable', fn() => ($down->isSet('key') === false));
check('get() returns the default value when the server is not reachable', fn() => ($down->get('key', 'default') === 'default'));
check('get() with a string default value which is a function name returns it', fn() => ($down->get('key', 'date') === 'date'));
check('get() calls the callback when the server is not reachable', fn() => ($down->get('key', fn() => ['a' => 1]) === ['a' => 1]));
check('read() converts a numeric default value to a string', fn() => ($down->read('key', 12) === '12'));
check('read() with a string default value which is a function name returns it', fn() => ($down->read('key', 'time') === 'time'));
check('read() throws an exception if the callback returns an array', function() use ($down) {
	try {
		$down->read('key', fn() => ['x']);
	} catch (\Temma\Exceptions\Database $e) {
		return (true);
	}
	return (false);
});
check('mGet() and mRead() return the keys with null values when the server is not reachable', function() use ($down) {
	return (($down->mGet(['a', 'b']) === ['a' => null, 'b' => null]) &&
	        ($down->mRead(['a']) === ['a' => null]) &&
	        ($down->mGet([]) === []));
});
check('A disabled datasource returns the default value, or the result of the callback', function() {
	$disabled = \Temma\Datasources\Memcache::factory('memcache://127.0.0.1:1#temma-test')->disable();
	return (($disabled->read('key', 'default') === 'default') && ($disabled->read('key', fn() => 'callback') === 'callback') &&
	        ($disabled->get('key', 0) === 0) && ($disabled->get('key', fn() => ['a' => 1]) === ['a' => 1]) &&
	        ($disabled->set('key', 'value') === false) && ($disabled->mSet(['key' => 'value']) === 0) &&
	        ($disabled->mWrite(['key' => 'value']) === 0));
});
check('A DSN without server throws a Database exception', function() {
	try {
		\Temma\Datasources\Memcache::factory('memcache://;');
	} catch (\Temma\Exceptions\Database $e) {
		return (true);
	}
	return (false);
});

/* ********** TESTS WITH SERVER ********** */
$dsn = getenv('TEMMA_TEST_MEMCACHE');
if (!$dsn) {
	print("\n" . TµAnsi::color('yellow', 'Tests with a Memcache server skipped (TEMMA_TEST_MEMCACHE not defined).') . "\n");
} else {
	print("\n" . TµAnsi::bold("Memcache data source ($dsn)\n"));
	if (!str_contains($dsn, '#'))
		$dsn .= '#temma-test';
	$cache = \Temma\Datasources\Memcache::factory($dsn);
	$prefix = 'temma-test-' . getmypid() . '-';
	$falsy = ['zero' => 0, 'zero-string' => '0', 'false' => false, 'empty' => '', 'empty-array' => []];
	// groups used by the tests (their salt keys are removed at the end), and raw keys to remove at the end
	$groups = ["{$prefix}group", "{$prefix}clear", "{$prefix}keys"];
	$rawKeys = [];
	try {
		check('get() returns stored falsy values without calling the callback', function() use ($cache, $prefix, $falsy) {
			$calls = 0;
			foreach ($falsy as $name => $value) {
				$cache->set("{$prefix}falsy-$name", $value);
				$result = $cache->get("{$prefix}falsy-$name", function() use (&$calls) {
					$calls++;
					return ('callback');
				});
				if ($result !== $value || ($cache->get("{$prefix}falsy-$name") !== $value))
					return (false);
			}
			return ($calls === 0);
		});
		check('isSet() returns true for stored falsy values', function() use ($cache, $prefix, $falsy) {
			foreach ($falsy as $name => $value) {
				if (!$cache->isSet("{$prefix}falsy-$name"))
					return (false);
			}
			return (!$cache->isSet("{$prefix}missing"));
		});
		check('isSet() works with the key "0"', function() use ($cache, $prefix) {
			$cache->setPrefix("{$prefix}keys");
			$cache->set('0', 'value');
			$result = $cache->isSet('0');
			$cache->remove('0');
			$cache->setPrefix();
			return ($result && ($cache->getPrefix() === ''));
		});
		check('read() returns a stored "0" and an empty string', function() use ($cache, $prefix) {
			return (($cache->read("{$prefix}falsy-zero-string", 'default') === '0') &&
			        ($cache->read("{$prefix}falsy-empty", 'default') === ''));
		});
		check('read() with a callback stores the value, also at the second call', function() use ($cache, $prefix) {
			$calls = 0;
			$callback = function() use (&$calls) {
				$calls++;
				return ('abc');
			};
			return (($cache->read("{$prefix}read-cb", $callback) === 'abc') &&
			        ($cache->read("{$prefix}read-cb", $callback) === 'abc') &&
			        $calls === 1);
		});
		check("read() with a string default value doesn't store it", function() use ($cache, $prefix) {
			return (($cache->read("{$prefix}read-default", 'xyz') === 'xyz') && !$cache->isSet("{$prefix}read-default"));
		});
		check('get() with a callback returning null stores nothing', function() use ($cache, $prefix) {
			return (($cache->get("{$prefix}get-null", fn() => null) === null) && !$cache->isSet("{$prefix}get-null"));
		});
		check('A disabled datasource calls the callback without storing its result', function() use ($cache, $prefix) {
			$cache->disable();
			$read = $cache->read("{$prefix}disabled", fn() => 'value');
			$get = $cache->get("{$prefix}disabled", fn() => ['value']);
			$cache->enable();
			return ($read === 'value' && ($get === ['value']) && !$cache->isSet("{$prefix}disabled"));
		});
		check('mGet() returns the user keys, with null for missing keys', function() use ($cache, $prefix) {
			$cache->set("{$prefix}a", 'A');
			$cache->set("{$prefix}b", ['B']);
			$cache->set("{$prefix}c", 0);
			$result = $cache->mGet(["{$prefix}a", "{$prefix}b", "{$prefix}c", "{$prefix}missing"]);
			return ($result === ["{$prefix}a" => 'A', "{$prefix}b" => ['B'], "{$prefix}c" => 0, "{$prefix}missing" => null]);
		});
		check('mGet() works with a group prefix', function() use ($cache, $prefix) {
			$cache->setPrefix("{$prefix}group");
			$cache->set("{$prefix}g", 'G');
			$result = $cache->mGet(["{$prefix}g", "{$prefix}a"]);
			$cache->remove("{$prefix}g");
			$cache->setPrefix();
			return ($result === ["{$prefix}g" => 'G', "{$prefix}a" => null]);
		});
		check('mWrite() and mRead() store and return values', function() use ($cache, $prefix) {
			$written = $cache->mWrite(["{$prefix}w1" => 'one', "{$prefix}w2" => '0']);
			$result = $cache->mRead(["{$prefix}w1", "{$prefix}w2"]);
			return ($written === 2 && ($result === ["{$prefix}w1" => 'one', "{$prefix}w2" => '0']));
		});
		check('mSet() and mWrite() remove the keys associated to null, and only count the written data', function() use ($cache, $prefix) {
			$set = $cache->mSet(["{$prefix}w1" => null, "{$prefix}w3" => 'three']);
			$written = $cache->mWrite(["{$prefix}w2" => null, "{$prefix}w4" => 'four', "{$prefix}w5" => 'five']);
			$removedOnly = $cache->mSet(["{$prefix}w5" => null]);
			return ($set === 1 && $written === 2 && $removedOnly === 0 &&
			        !$cache->isSet("{$prefix}w1") && !$cache->isSet("{$prefix}w2") && !$cache->isSet("{$prefix}w5") &&
			        ($cache->get("{$prefix}w3") === 'three') && ($cache->get("{$prefix}w4") === 'four'));
		});
		check('mRemove() removes the given keys (not their indexes)', function() use ($cache, $prefix) {
			$cache->setPrefix("{$prefix}keys");
			$cache->set('0', 'zero');
			$cache->set('1', 'one');
			$cache->set("{$prefix}a", 'A');
			$cache->set("{$prefix}b", 'B');
			$cache->mRemove(["{$prefix}a", "{$prefix}b"]);
			$result = (!$cache->isSet("{$prefix}a") && !$cache->isSet("{$prefix}b") &&
			           $cache->isSet('0') && $cache->isSet('1'));
			$cache->mRemove(['0', '1']);
			$result = ($result && !$cache->isSet('0') && !$cache->isSet('1'));
			$cache->setPrefix();
			return ($result);
		});
		check('mRemove() accepts an empty list', function() use ($cache) {
			$cache->mRemove([]);
			return (($cache->mGet([]) === []) && ($cache->mSet([]) === 0));
		});
		check('clear() invalidates the keys of a group, not the others', function() use ($cache, $prefix, &$rawKeys) {
			$cache->set("{$prefix}outside", 'outside');
			$cache->setPrefix("{$prefix}clear");
			$cache->set("{$prefix}inside", 'inside');
			// the invalidated key is not reachable anymore after clear(), its raw key is kept to remove it at the end
			$saltedPrefix = (new \ReflectionMethod($cache, '_getSaltedPrefix'))->invoke($cache);
			$rawKeys[] = $saltedPrefix . "{$prefix}inside";
			$before = $cache->get("{$prefix}inside");
			$cache->clear("{$prefix}clear");
			$after = $cache->get("{$prefix}inside");
			$cache->setPrefix();
			return ($before === 'inside' && $after === null && ($cache->get("{$prefix}outside") === 'outside'));
		});
		check('mSet() and mWrite() store data with the default expiration', function() use ($cache, $prefix) {
			$expiration = $cache->getExpiration();
			$cache->setExpiration(1);
			$cache->mSet(["{$prefix}exp1" => 'value']);
			$cache->mWrite(["{$prefix}exp2" => 'value']);
			$cache->setExpiration($expiration);
			$cache->mSet(["{$prefix}exp3" => 'value'], 1);
			$before = $cache->mGet(["{$prefix}exp1", "{$prefix}exp2", "{$prefix}exp3"]);
			sleep(2);
			$after = $cache->mGet(["{$prefix}exp1", "{$prefix}exp2", "{$prefix}exp3"]);
			return (($before === ["{$prefix}exp1" => 'value', "{$prefix}exp2" => 'value', "{$prefix}exp3" => 'value']) &&
			        ($after === ["{$prefix}exp1" => null, "{$prefix}exp2" => null, "{$prefix}exp3" => null]));
		});
	} finally {
		// keys created without group
		$cache->setPrefix();
		$keys = ['read-cb', 'read-default', 'get-null', 'disabled', 'a', 'b', 'c', 'w1', 'w2', 'w3', 'w4', 'w5',
		         'outside', 'exp1', 'exp2', 'exp3'];
		foreach ($falsy as $name => $value)
			$keys[] = "falsy-$name";
		$cache->mRemove(array_map(fn($key) => ($prefix . $key), $keys));
		// keys of the groups (the salt keys are stored with the super-prefix, which is added by remove())
		foreach ($groups as $group) {
			$cache->setPrefix($group);
			$cache->mRemove(['0', '1', "{$prefix}a", "{$prefix}b", "{$prefix}g", "{$prefix}inside"]);
			$cache->setPrefix();
			$cache->remove(\Temma\Datasources\Memcache::PREFIX_SALT_PREFIX . "|$group|");
		}
		// raw keys
		foreach ($rawKeys as $rawKey)
			property($cache, '_memcache')->delete($rawKey);
	}
}

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
