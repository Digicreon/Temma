#!/usr/bin/php
<?php

/**
 * Validation script for the key-value methods of the data sources (read(), get(), set(), write(), mGet(), mRead()...),
 * with the same scenario on each data source:
 * - read() with a callback stores the raw value (it was JSON-encoded, so the next calls returned a JSON string);
 * - get() with a callback stores the JSON-encoded value (it stored the raw value);
 * - "falsy" values ("0", 0, false, "") are not taken for missing values;
 * - a string default value is never called as a function;
 * - missing keys and null values are managed the same way by all data sources;
 * - a disabled data source behaves like an empty one;
 * - the "$source[] = $value" syntax (without key) is forbidden on key-value data sources.
 * The DSN masking and the generic factory of the base class are tested too.
 *
 * The File data source and the Sql data source (on an in-memory SQLite database) are always tested.
 * The other data sources are tested only if their DSN is given in an environment variable; the test only uses
 * (and removes) keys starting with "temma-test-<pid>":
 * - TEMMA_TEST_REDIS: Redis DSN (e.g. "redis://localhost/9").
 * - TEMMA_TEST_SQL: MySQL DSN (e.g. "mysql://user:password@localhost/test"); the TemmaData table is created if it
 *   doesn't exist (and removed at the end of the test).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Database as TµDatabaseException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
// temporary directory for the File data source and the local files
$tmpPath = sys_get_temp_dir() . '/temma-keyvalue-test-' . getmypid();
mkdir("$tmpPath/root", 0755, true);
register_shutdown_function(function() use ($tmpPath) {
	exec('rm -rf ' . escapeshellarg($tmpPath));
});

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
 * Executes a function and tells if it throws a Database exception.
 * @param	callable	$function	The function to execute.
 * @return	bool	True if a Database exception was thrown.
 */
function throwsDatabaseException(callable $function) : bool {
	try {
		$function();
	} catch (TµDatabaseException $e) {
		return (true);
	}
	return (false);
}
/**
 * Runs the key-value scenario on a data source.
 * @param	\Temma\Base\Datasource	$ds		The data source.
 * @param	string			$prefix		Prefix of the keys used by the test.
 * @param	string			$localPath	Path of the directory used for the local files.
 */
function scenario(\Temma\Base\Datasource $ds, string $prefix, string $localPath) : void {
	// read()
	check('read() with a callback returns the raw value, also at the second call', function() use ($ds, $prefix) {
		$calls = 0;
		$callback = function() use (&$calls) {
			$calls++;
			return ('abc');
		};
		return (($ds->read("{$prefix}read-cb", $callback) === 'abc') &&
		        ($ds->read("{$prefix}read-cb", $callback) === 'abc') &&
		        ($calls === 1));
	});
	check('read() with a scalar default value returns it, without storing it', function() use ($ds, $prefix) {
		return (($ds->read("{$prefix}read-default", 'xyz') === 'xyz') && !$ds->isSet("{$prefix}read-default"));
	});
	check('read() with a "0" default value returns it', fn() => ($ds->read("{$prefix}read-zero-default", '0') === '0'));
	check('read() never calls a string default value naming a PHP function', function() use ($ds, $prefix) {
		return (($ds->read("{$prefix}read-function-name", 'key') === 'key') &&
		        ($ds->get("{$prefix}get-function-name", 'strtoupper') === 'strtoupper'));
	});
	check('read() with a callback returning null returns null, without storing anything', function() use ($ds, $prefix) {
		return (($ds->read("{$prefix}read-null", fn() => null) === null) && !$ds->isSet("{$prefix}read-null"));
	});
	check('read() with a callback returning an array raises a Database exception, without storing anything', function() use ($ds, $prefix) {
		return (throwsDatabaseException(fn() => $ds->read("{$prefix}read-array", fn() => ['a'])) &&
		        !$ds->isSet("{$prefix}read-array"));
	});
	check('read() converts a number returned by a callback to a string', fn() => ($ds->read("{$prefix}read-int", fn() => 12) === '12'));
	check('read() returns a stored "0" value', function() use ($ds, $prefix) {
		$ds->write("{$prefix}read-zero", '0');
		return ($ds->read("{$prefix}read-zero", 'default') === '0');
	});
	// get()
	check('get() with a callback returning a string stores it JSON-encoded', function() use ($ds, $prefix) {
		return (($ds->get("{$prefix}get-str", fn() => 'abc') === 'abc') &&
		        ($ds->get("{$prefix}get-str", fn() => 'other') === 'abc') &&
		        ($ds->read("{$prefix}get-str") === '"abc"'));
	});
	check('get() with a callback returning an array stores it JSON-encoded', function() use ($ds, $prefix) {
		$data = ['a' => 1, 'b' => [true, null, 1.5]];
		return (($ds->get("{$prefix}get-array", fn() => $data) === $data) &&
		        ($ds->get("{$prefix}get-array", fn() => []) === $data));
	});
	check('get() with a callback returning null returns null, without storing anything', function() use ($ds, $prefix) {
		return (($ds->get("{$prefix}get-null", fn() => null) === null) && !$ds->isSet("{$prefix}get-null"));
	});
	check('get() with a scalar default value returns it, without storing it', function() use ($ds, $prefix) {
		return (($ds->get("{$prefix}get-default", 0) === 0) && !$ds->isSet("{$prefix}get-default"));
	});
	check('set() and get() keep the types of falsy and scalar values', function() use ($ds, $prefix) {
		foreach (['zero' => 0, 'false' => false, 'empty' => '', 'float' => 1.5, 'nested' => ['a' => ['b' => 1]]] as $name => $value) {
			$ds->set("{$prefix}set-$name", $value);
			if ($ds->get("{$prefix}set-$name", 'default') !== $value)
				return (false);
		}
		return (true);
	});
	// multiple requests
	check('mSet() and mGet() return JSON-decoded associative arrays, with null for missing keys', function() use ($ds, $prefix) {
		$ds->mSet(["{$prefix}m1" => ['a' => 1], "{$prefix}m2" => 'text']);
		$result = $ds->mGet(["{$prefix}m1", "{$prefix}missing", "{$prefix}m2"]);
		return ($result === ["{$prefix}m1" => ['a' => 1], "{$prefix}missing" => null, "{$prefix}m2" => 'text']);
	});
	check('mWrite() and mRead() store and return raw values, with null for missing keys', function() use ($ds, $prefix) {
		$written = $ds->mWrite(["{$prefix}w1" => 'one', "{$prefix}w2" => 'two']);
		$writtenAgain = $ds->mWrite(["{$prefix}w1" => 'one', "{$prefix}w2" => 'three']);
		$result = $ds->mRead(["{$prefix}w1", "{$prefix}missing", "{$prefix}w2"]);
		return (($written === 2) && ($writtenAgain === 2) &&
		        ($result === ["{$prefix}w1" => 'one', "{$prefix}missing" => null, "{$prefix}w2" => 'three']));
	});
	check('mWrite() removes the keys with a null value', function() use ($ds, $prefix) {
		$ds->write("{$prefix}w-null", 'value');
		$written = $ds->mWrite(["{$prefix}w-null" => null, "{$prefix}w-other" => 'other']);
		return (($written === 1) && !$ds->isSet("{$prefix}w-null") && ($ds->read("{$prefix}w-other") === 'other'));
	});
	check('mSet() removes the keys with a null value', function() use ($ds, $prefix) {
		$ds->set("{$prefix}s-null", 'value');
		$set = $ds->mSet(["{$prefix}s-null" => null, "{$prefix}s-other" => 'other']);
		return (($set === 1) && !$ds->isSet("{$prefix}s-null") && ($ds->get("{$prefix}s-other") === 'other'));
	});
	check('mRead(), mGet() and mRemove() accept an empty list', function() use ($ds) {
		$ds->mRemove([]);
		return (($ds->mRead([]) === []) && ($ds->mGet([]) === []));
	});
	// copyFrom()
	check('copyFrom() with a "0" default value writes it in the local file', function() use ($ds, $prefix, $localPath) {
		return (($ds->copyFrom("{$prefix}copy-missing", "$localPath/copy.txt", '0') === true) &&
		        (file_get_contents("$localPath/copy.txt") === '0'));
	});
	// array syntax
	check('the "$source[] = $value" syntax raises a Database exception', function() use ($ds) {
		return (throwsDatabaseException(function() use ($ds) {
			$ds[] = 'value';
		}));
	});
	// disabled data source
	check('a disabled data source behaves like an empty one (default values returned, callbacks called, nothing stored)', function() use ($ds, $prefix) {
		$ds->write("{$prefix}disabled", 'value');
		$ds->disable();
		try {
			$ok = (($ds->read("{$prefix}disabled", 'default') === 'default') &&
			       ($ds->get("{$prefix}disabled", 'default') === 'default') &&
			       ($ds->read("{$prefix}disabled-cb", fn() => 'abc') === 'abc') &&
			       ($ds->get("{$prefix}disabled-cb", fn() => ['a']) === ['a']));
		} finally {
			$ds->enable();
		}
		return ($ok && ($ds->read("{$prefix}disabled") === 'value') && !$ds->isSet("{$prefix}disabled-cb"));
	});
}

/* ********** TESTS ********** */
$prefix = 'temma-test-' . getmypid() . '-';
// base class
print(TµAnsi::bold("Base data source object\n"));
check('DSN masking keeps only the scheme and the host', function() {
	$mask = fn(string $dsn) => (new \ReflectionMethod(\Temma\Base\Datasource::class, '_maskDsn'))->invoke(null, $dsn);
	return (($mask('mysql://user:p@ss@localhost/base') === 'mysql://***@localhost') &&
	        ($mask('slack://name@hooks.slack.com/services/T0/B0/SECRET') === 'slack://***@hooks.slack.com') &&
	        ($mask('ai://openai/gpt#sk-ab@cd') === 'ai://***') &&
	        ($mask('smtp://u:p@host?token=a@b') === 'smtp://***@host') &&
	        ($mask('redis://localhost/2') === 'redis://***') &&
	        ($mask('sqlite::memory:') === 'sqlite:***'));
});
check('the generic factory accepts an object name between brackets, and DSNs containing brackets', function() {
	return ((\Temma\Base\Datasource::factory('[\Temma\Datasources\Dummy]dummy://') instanceof \Temma\Datasources\Dummy) &&
	        (\Temma\Base\Datasource::factory('ai://[http://127.0.0.1:1/v1]/model#KEY') instanceof \Temma\Datasources\Ai));
});

// File
print("\n" . TµAnsi::bold("File data source\n"));
$file = \Temma\Datasources\File::factory("file://$tmpPath/root");
scenario($file, $prefix, $tmpPath);

// Sql (SQLite in memory, and MySQL if a DSN is given)
$sqlDsns = ['sqlite::memory:'];
if (($mysqlDsn = getenv('TEMMA_TEST_SQL')))
	$sqlDsns[] = $mysqlDsn;
foreach ($sqlDsns as $dsn) {
	$sql = \Temma\Datasources\Sql::factory($dsn);
	print("\n" . TµAnsi::bold('Sql data source (' . $sql->getType() . ")\n"));
	$tableCreated = true;
	if ($sql->getType() == 'mysql') {
		$tableCreated = !$sql->queryOne("SHOW TABLES LIKE 'TemmaData'");
		$sql->exec("CREATE TABLE IF NOT EXISTS TemmaData (
		                `key`  VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
		                data   LONGTEXT,
		                PRIMARY KEY (`key`)
		            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
		$keyField = '`key`';
	} else {
		$sql->exec('CREATE TABLE TemmaData (
		                "key"  VARCHAR(255) NOT NULL PRIMARY KEY,
		                data   TEXT
		            )');
		$keyField = '"key"';
	}
	try {
		scenario($sql, $prefix, $tmpPath);
		check('count(), find() and clear() use the prefix literally ("_" and "%" are not wildcards, case-sensitive keys)', function() use ($sql, $prefix) {
			$sql->mWrite(["{$prefix}u_1" => 'a', "{$prefix}uX1" => 'b', "{$prefix}U_2" => 'c', "{$prefix}u%3" => 'd']);
			$found = $sql->find("{$prefix}u_", false, true);
			$foundPercent = $sql->find("{$prefix}u%");
			$counted = $sql->count("{$prefix}u_");
			$sql->clear("{$prefix}u_");
			return (($found === ["{$prefix}u_1"]) && ($foundPercent === ["{$prefix}u%3"]) && ($counted === 1) &&
			        $sql->isSet("{$prefix}uX1") && $sql->isSet("{$prefix}U_2") && !$sql->isSet("{$prefix}u_1"));
		});
		check('find() sorts on the key and the data', function() use ($sql, $prefix) {
			$sql->mWrite(["{$prefix}sort-1" => 'b', "{$prefix}sort-2" => 'a']);
			return (($sql->find("{$prefix}sort-", true, 'data') === ["{$prefix}sort-2" => 'a', "{$prefix}sort-1" => 'b']) &&
			        ($sql->find("{$prefix}sort-", false, '-key') === ["{$prefix}sort-2", "{$prefix}sort-1"]));
		});
	} finally {
		// cleanup without the key-value methods
		if ($tableCreated)
			$sql->exec('DROP TABLE TemmaData');
		else
			$sql->exec("DELETE FROM TemmaData WHERE SUBSTR($keyField, 1, " . mb_strlen($prefix) . ') = ' . $sql->quote($prefix));
	}
}

// Redis
$redisDsn = getenv('TEMMA_TEST_REDIS');
if ($redisDsn) {
	print("\n" . TµAnsi::bold("Redis data source ($redisDsn)\n"));
	$redis = \Temma\Datasources\Redis::factory($redisDsn);
	// direct connection, used to check the expirations and to clean up
	$url = parse_url($redisDsn);
	$rawRedis = new \Redis();
	$rawRedis->connect($url['host'], ($url['port'] ?? 6379));
	$rawRedis->select((int)trim(($url['path'] ?? '/0'), '/'));
	try {
		scenario($redis, $prefix, $tmpPath);
		check('read() and get() with a callback use the expiration timeout', function() use ($redis, $rawRedis, $prefix) {
			$redis->read("{$prefix}ttl-read", fn() => 'abc', 100);
			$redis->get("{$prefix}ttl-get", fn() => 'abc', 100);
			$ttlRead = $rawRedis->ttl("{$prefix}ttl-read");
			$ttlGet = $rawRedis->ttl("{$prefix}ttl-get");
			return (($ttlRead > 0) && ($ttlRead <= 100) && ($ttlGet > 0) && ($ttlGet <= 100));
		});
		check('clear() removes all the keys matching a pattern', function() use ($redis, $prefix) {
			$redis->clear("$prefix*");
			return ($redis->find("$prefix*") === []);
		});
	} finally {
		// cleanup without the key-value methods
		$it = null;
		do {
			$keys = $rawRedis->scan($it, "$prefix*");
			if ($keys)
				$rawRedis->del($keys);
		} while ($it > 0);
	}
} else
	print("\n" . TµAnsi::color('yellow', 'Redis data source skipped (TEMMA_TEST_REDIS not defined).') . "\n");

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
