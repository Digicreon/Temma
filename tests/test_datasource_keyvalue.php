#!/usr/bin/php
<?php

/**
 * Validation script for the key-value methods of the data sources (read(), get(), set(), write(), mGet(), mRead()...),
 * with the same scenario on each data source:
 * - read() with a callback stores the raw value (it was JSON-encoded, so the next calls returned a JSON string);
 * - get() with a callback stores the JSON-encoded value (it stored the raw value);
 * - "falsy" values ("0", 0, false, "") are not taken for missing values.
 *
 * The File data source is always tested. The other data sources are tested only if their DSN is given in an environment
 * variable; the test only uses (and removes) keys starting with "temma-test-<pid>":
 * - TEMMA_TEST_REDIS: Redis DSN (e.g. "redis://localhost/9").
 * - TEMMA_TEST_SQL: MySQL DSN (e.g. "mysql://user:password@localhost/test"); the TemmaData table is created if it doesn't exist.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

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
 * Runs the key-value scenario on a data source.
 * @param	\Temma\Base\Datasource	$ds		The data source.
 * @param	string			$prefix		Prefix of the keys used by the test.
 * @param	string			$localPath	Path of the directory used for the local files.
 */
function scenario(\Temma\Base\Datasource $ds, string $prefix, string $localPath) : void {
	// read()
	check("read() with a callback returns the raw value, also at the second call", function() use ($ds, $prefix) {
		$calls = 0;
		$callback = function() use (&$calls) {
			$calls++;
			return ('abc');
		};
		return ($ds->read("{$prefix}read-cb", $callback) === 'abc' &&
		        $ds->read("{$prefix}read-cb", $callback) === 'abc' &&
		        $calls === 1);
	});
	check("read() with a scalar default value returns it, without storing it", function() use ($ds, $prefix) {
		return ($ds->read("{$prefix}read-default", 'xyz') === 'xyz' && !$ds->isSet("{$prefix}read-default"));
	});
	check("read() with a \"0\" default value returns it", fn() => ($ds->read("{$prefix}read-zero-default", '0') === '0'));
	check("read() with a callback returning null returns null, without storing anything", function() use ($ds, $prefix) {
		return ($ds->read("{$prefix}read-null", fn() => null) === null && !$ds->isSet("{$prefix}read-null"));
	});
	check("read() returns a stored \"0\" value", function() use ($ds, $prefix) {
		$ds->write("{$prefix}read-zero", '0');
		return ($ds->read("{$prefix}read-zero", 'default') === '0');
	});
	// get()
	check("get() with a callback returning a string stores it JSON-encoded", function() use ($ds, $prefix) {
		return ($ds->get("{$prefix}get-str", fn() => 'abc') === 'abc' &&
		        $ds->get("{$prefix}get-str", fn() => 'other') === 'abc' &&
		        $ds->read("{$prefix}get-str") === '"abc"');
	});
	check("get() with a callback returning an array stores it JSON-encoded", function() use ($ds, $prefix) {
		$data = ['a' => 1, 'b' => [true, null, 1.5]];
		return ($ds->get("{$prefix}get-array", fn() => $data) === $data &&
		        $ds->get("{$prefix}get-array", fn() => []) === $data);
	});
	check("get() with a scalar default value returns it, without storing it", function() use ($ds, $prefix) {
		return ($ds->get("{$prefix}get-default", 0) === 0 && !$ds->isSet("{$prefix}get-default"));
	});
	check("set() and get() keep the types of falsy and scalar values", function() use ($ds, $prefix) {
		foreach (['zero' => 0, 'false' => false, 'empty' => '', 'float' => 1.5, 'nested' => ['a' => ['b' => 1]]] as $name => $value) {
			$ds->set("{$prefix}set-$name", $value);
			if ($ds->get("{$prefix}set-$name", 'default') !== $value)
				return (false);
		}
		return (true);
	});
	// multiple requests
	check("mSet() and mGet() return JSON-decoded associative arrays", function() use ($ds, $prefix) {
		$ds->mSet(["{$prefix}m1" => ['a' => 1], "{$prefix}m2" => 'text']);
		$result = $ds->mGet(["{$prefix}m1", "{$prefix}m2"]);
		return ($result === ["{$prefix}m1" => ['a' => 1], "{$prefix}m2" => 'text']);
	});
	check("mWrite() and mRead() store and return raw values", function() use ($ds, $prefix) {
		$written = $ds->mWrite(["{$prefix}w1" => 'one', "{$prefix}w2" => 'two']);
		$writtenAgain = $ds->mWrite(["{$prefix}w1" => 'one', "{$prefix}w2" => 'three']);
		$result = $ds->mRead(["{$prefix}w1", "{$prefix}w2"]);
		return ($written === 2 && $writtenAgain === 2 && $result === ["{$prefix}w1" => 'one', "{$prefix}w2" => 'three']);
	});
	check("mRead() and mRemove() accept an empty list", function() use ($ds) {
		$ds->mRemove([]);
		return ($ds->mRead([]) === []);
	});
	// copyFrom()
	check("copyFrom() with a \"0\" default value writes it in the local file", function() use ($ds, $prefix, $localPath) {
		return ($ds->copyFrom("{$prefix}copy-missing", "$localPath/copy.txt", '0') === true &&
		        file_get_contents("$localPath/copy.txt") === '0');
	});
}

/* ********** TESTS ********** */
$prefix = 'temma-test-' . getmypid() . '-';
// File
print(TµAnsi::bold("File data source\n"));
$file = \Temma\Datasources\File::factory("file://$tmpPath/root");
scenario($file, $prefix, $tmpPath);
// Redis
$redisDsn = getenv('TEMMA_TEST_REDIS');
if ($redisDsn) {
	print("\n" . TµAnsi::bold("Redis data source ($redisDsn)\n"));
	$redis = \Temma\Datasources\Redis::factory($redisDsn);
	try {
		scenario($redis, $prefix, $tmpPath);
		check("clear() removes all the keys matching a pattern", function() use ($redis, $prefix) {
			$redis->clear("$prefix*");
			return ($redis->find("$prefix*") === []);
		});
	} finally {
		$redis->clear("$prefix*");
	}
} else
	print("\n" . TµAnsi::color('yellow', "Redis data source skipped (TEMMA_TEST_REDIS not defined).") . "\n");
// SQL
$sqlDsn = getenv('TEMMA_TEST_SQL');
if ($sqlDsn) {
	print("\n" . TµAnsi::bold("Sql data source\n"));
	$sql = \Temma\Datasources\Sql::factory($sqlDsn);
	$sql->exec("CREATE TABLE IF NOT EXISTS TemmaData (
	                `key`  CHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
	                data   LONGTEXT,
	                PRIMARY KEY (`key`)
	            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
	try {
		scenario($sql, $prefix, $tmpPath);
		check("count(), find() and clear() work with a prefix", function() use ($sql, $prefix) {
			$found = $sql->find($prefix, false, true);
			$counted = $sql->count($prefix);
			$sql->clear($prefix);
			return ($found && count($found) === $counted && $sql->count($prefix) === 0);
		});
	} finally {
		$sql->clear($prefix);
	}
} else
	print("\n" . TµAnsi::color('yellow', "Sql data source skipped (TEMMA_TEST_SQL not defined).") . "\n");

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
