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
 * - mWrite() converts numbers to strings and skips the other non-string values (arrays, objects, booleans),
 *   without aborting; mSet() skips the values which can't be JSON-encoded;
 * - mCopyFrom() counts only the copied keys (not the missing ones);
 * - a disabled data source behaves like an empty one, and writings are ignored (matrix of all the methods,
 *   including the Memcache data source);
 * - the "$source[] = $value" syntax (without key) is forbidden on key-value data sources.
 * The DSN masking, the generic factory, mWrite()/mSet() on a data source which is not a key-value data source
 * (null values skipped), and the Dummy data source are tested too.
 *
 * The File data source and the Sql data source (on an in-memory SQLite database) are always tested.
 * The other data sources are tested only if their DSN is given in an environment variable; the test only uses
 * (and removes) keys starting with "temma-test-<pid>":
 * - TEMMA_TEST_REDIS: Redis DSN (e.g. "redis://localhost/9"). The disabled flush() is tested on this database.
 * - TEMMA_TEST_SQL: MySQL DSN (e.g. "mysql://user:password@localhost/test"); the TemmaData table is created if it
 *   doesn't exist (and removed at the end of the test).
 * - TEMMA_TEST_MEMCACHE: Memcache DSN (e.g. "memcache://localhost:11211"; the super-prefix "temma-test" is added if
 *   the DSN has none). Only the disabled matrix and mWrite() are tested. The disabled flush() is tested on this server.
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
	check('mWrite() converts numbers to strings, and skips the other non-string values without aborting', function() use ($ds, $prefix) {
		$written = $ds->mWrite(["{$prefix}conv-int" => 12, "{$prefix}conv-array" => ['a'], "{$prefix}conv-float" => 1.5,
		                        "{$prefix}conv-bool" => true, "{$prefix}conv-object" => new \stdClass(), "{$prefix}conv-str" => 'ok']);
		return (($written === 3) && ($ds->read("{$prefix}conv-int") === '12') && ($ds->read("{$prefix}conv-float") === '1.5') &&
		        ($ds->read("{$prefix}conv-str") === 'ok') && !$ds->isSet("{$prefix}conv-array") &&
		        !$ds->isSet("{$prefix}conv-bool") && !$ds->isSet("{$prefix}conv-object"));
	});
	check('mSet() skips the values which can\'t be JSON-encoded', function() use ($ds, $prefix) {
		$set = $ds->mSet(["{$prefix}json-inf" => INF, "{$prefix}json-ok" => [1]]);
		return (($set === 1) && !$ds->isSet("{$prefix}json-inf") && ($ds->get("{$prefix}json-ok") === [1]));
	});
	check('mCopyFrom() counts only the copied keys', function() use ($ds, $prefix, $localPath) {
		$ds->write("{$prefix}mcopy", 'copied');
		$copied = $ds->mCopyFrom(["{$prefix}mcopy" => "$localPath/mcopy-1.txt", "{$prefix}mcopy-missing" => "$localPath/mcopy-2.txt"]);
		return (($copied === 1) && (file_get_contents("$localPath/mcopy-1.txt") === 'copied') && !file_exists("$localPath/mcopy-2.txt"));
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

/**
 * Runs the tests of a disabled data source on a data source: it must behave like an empty data source, and writings
 * must be ignored.
 * @param	\Temma\Base\Datasource	$ds		The data source.
 * @param	string			$prefix		Prefix of the keys used by the test.
 * @param	string			$localPath	Path of the directory used for the local files.
 * @param	bool			$search		(optional) True if the data source supports find() and search().
 * @param	bool			$flush		(optional) True to test flush() (the data source must contain only test data).
 */
function disabledMatrix(\Temma\Base\Datasource $ds, string $prefix, string $localPath, bool $search=true, bool $flush=false) : void {
	$key = "{$prefix}dis-key";
	$new = "{$prefix}dis-new";
	$missing = "{$prefix}dis-missing";
	$ds->write($key, 'value');
	file_put_contents("$localPath/dis-source.txt", 'source');
	if (file_exists("$localPath/dis-copy.txt"))
		unlink("$localPath/dis-copy.txt");
	$ds->disable();
	try {
		check('disabled: isSet() and isset() return false', fn() => ($ds->isSet($key) === false && !isset($ds[$key])));
		check('disabled: count() returns 0', fn() => ($ds->count() === 0 && $ds->count($prefix) === 0 && count($ds) === 0));
		if ($search) {
			check('disabled: find() and search() return an empty array', function() use ($ds, $prefix) {
				return ($ds->find("$prefix*") === [] && $ds->find($prefix, true) === [] &&
				        $ds->search("$prefix*") === [] && $ds->search($prefix, true) === []);
			});
		}
		check('disabled: read(), get() and the array syntax return null or the default value', function() use ($ds, $key) {
			return ($ds->read($key) === null && $ds->read($key, 'default') === 'default' && $ds->read($key, 12) === '12' &&
			        $ds->get($key) === null && $ds->get($key, ['default']) === ['default'] && $ds[$key] === null);
		});
		check('disabled: read() and get() return the result of the callback', function() use ($ds, $missing) {
			return ($ds->read($missing, fn() => 'abc') === 'abc' && $ds->get($missing, fn() => ['a']) === ['a']);
		});
		check('disabled: copyFrom() returns false without default value, writes the default value otherwise', function() use ($ds, $key, $missing, $localPath) {
			$withoutDefault = $ds->copyFrom($key, "$localPath/dis-copy.txt");
			$fileCreated = file_exists("$localPath/dis-copy.txt");
			return ($withoutDefault === false && !$fileCreated &&
			        $ds->copyFrom($key, "$localPath/dis-copy.txt", '0') === true && file_get_contents("$localPath/dis-copy.txt") === '0' &&
			        $ds->copyFrom($missing, "$localPath/dis-copy.txt", fn() => 'cb') === true && file_get_contents("$localPath/dis-copy.txt") === 'cb');
		});
		check('disabled: mRead() and mGet() associate every key to null; mCopyFrom() returns 0', function() use ($ds, $key, $missing, $localPath) {
			return ($ds->mRead([$key, $missing]) === [$key => null, $missing => null] &&
			        $ds->mGet([$key, $missing]) === [$key => null, $missing => null] &&
			        $ds->mRead([]) === [] && $ds->mGet([]) === [] &&
			        $ds->mCopyFrom([$key => "$localPath/dis-mcopy.txt"]) === 0 && !file_exists("$localPath/dis-mcopy.txt"));
		});
		check('disabled: write(), set() and copyTo() return false; mWrite(), mSet() and mCopyTo() return 0', function() use ($ds, $new, $localPath) {
			return ($ds->write($new, 'value') === false && $ds->set($new, ['value']) === false && $ds->set($new, null) === false &&
			        $ds->copyTo($new, "$localPath/dis-source.txt") === false &&
			        $ds->mWrite([$new => 'value']) === 0 && $ds->mSet([$new => 'value']) === 0 &&
			        $ds->mCopyTo([$new => "$localPath/dis-source.txt"]) === 0);
		});
		check('disabled: the array syntax doesn\'t write nor remove anything', function() use ($ds, $key, $new) {
			$ds[$new] = 'value';
			$ds[$key] = null;
			unset($ds[$key]);
			return (true);
		});
		check('disabled: remove(), mRemove() and clear() don\'t remove anything', function() use ($ds, $key, $prefix, $flush) {
			$ds->remove($key);
			$ds->mRemove([$key]);
			$ds->clear($prefix);
			if ($flush)
				$ds->flush();
			return (true);
		});
	} finally {
		$ds->enable();
	}
	check('disabled: nothing was written nor removed (checked after enable())', function() use ($ds, $key, $new, $missing) {
		$ok = ($ds->read($key) === 'value' && !$ds->isSet($new) && !$ds->isSet($missing));
		$ds->remove($key);
		return ($ok);
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
// data source which is not a key-value data source (like a message queue), using the methods of the base class
$queue = new class extends \Temma\Base\Datasource {
	/** List of the written messages. */
	public array $written = [];
	/** List of the removed keys. */
	public array $removed = [];
	/**
	 * Write a message.
	 * @param	string	$key		Key.
	 * @param	string	$value		Message.
	 * @param	mixed	$options	Not used.
	 * @return	bool	Always true.
	 * @throws	\Exception	If the message is 'fail'.
	 */
	public function write(string $key, string $value, mixed $options=null) : bool {
		if ($value === 'fail')
			throw new \Exception('Failure.');
		$this->written[$key] = $value;
		return (true);
	}
	/**
	 * Remove a key.
	 * @param	string	$key	Key.
	 */
	public function remove(string $key) : void {
		$this->removed[] = $key;
	}
};
check('mWrite() on a data source which is not a key-value one: null values skipped (not removed, not counted)', function() use ($queue) {
	$written = $queue->mWrite(['a' => 'one', 'b' => null, 'c' => 2]);
	return ($written === 2 && $queue->written === ['a' => 'one', 'c' => '2'] && $queue->removed === []);
});
check('mWrite(): arrays, objects, booleans and failing values are skipped, the other values are written', function() use ($queue) {
	$queue->written = [];
	$written = $queue->mWrite(['a' => ['x'], 'b' => new \stdClass(), 'c' => false, 'd' => 'fail', 'e' => 'ok', 'f' => 1.5]);
	return ($written === 2 && $queue->written === ['e' => 'ok', 'f' => '1.5']);
});
check('mSet() on a data source which is not a key-value one: values JSON-encoded, null values skipped', function() use ($queue) {
	$queue->written = [];
	$set = $queue->mSet(['a' => ['x' => 1], 'b' => null, 'c' => INF]);
	return ($set === 1 && $queue->written === ['a' => '{"x":1}'] && $queue->removed === []);
});
check('disabled: set() and copyTo() of the base class return false (not the object)', function() use ($queue, $tmpPath) {
	file_put_contents("$tmpPath/queue.txt", 'data');
	$queue->disable();
	try {
		return ($queue->set('k', 'v') === false && $queue->copyTo('k', "$tmpPath/queue.txt") === false &&
		        $queue->mWrite(['k' => 'v']) === 0 && $queue->mGet(['k']) === ['k' => null] && $queue->mRead(['k']) === ['k' => null]);
	} finally {
		$queue->enable();
	}
});
check('the base write() method throws an exception mentioning write()', function() {
	try {
		(new class extends \Temma\Base\Datasource {})->write('k', 'v');
	} catch (TµDatabaseException $e) {
		return (str_contains($e->getMessage(), 'write()'));
	}
	return (false);
});

// Dummy
print("\n" . TµAnsi::bold("Dummy data source\n"));
$dummy = \Temma\Base\Datasource::factory('dummy://');
check('Dummy: behaves like an empty data source', function() use ($dummy, $tmpPath) {
	return ($dummy->isSet('k') === false && count($dummy) === 0 && $dummy->find('*') === [] && $dummy->search('*') === [] &&
	        $dummy->read('k') === null && $dummy->read('k', 'def') === 'def' && $dummy->get('k', fn() => [1]) === [1] &&
	        $dummy->mRead(['a', 'b']) === ['a' => null, 'b' => null] && $dummy->mGet(['a']) === ['a' => null] &&
	        $dummy->copyFrom('k', "$tmpPath/dummy.txt") === false && !file_exists("$tmpPath/dummy.txt") &&
	        $dummy->copyFrom('k', "$tmpPath/dummy.txt", 'def') === true && file_get_contents("$tmpPath/dummy.txt") === 'def' &&
	        $dummy->mCopyFrom(['k' => "$tmpPath/dummy2.txt"]) === 0);
});
check('Dummy: writings are ignored', function() use ($dummy, $tmpPath) {
	return ($dummy->write('k', 'v') === false && $dummy->set('k', 'v') === false && $dummy->copyTo('k', "$tmpPath/dummy.txt") === false &&
	        $dummy->mWrite(['k' => 'v']) === 0 && $dummy->mSet(['k' => 'v']) === 0 && $dummy->mCopyTo(['k' => "$tmpPath/dummy.txt"]) === 0);
});

// File
print("\n" . TµAnsi::bold("File data source\n"));
$file = \Temma\Datasources\File::factory("file://$tmpPath/root");
scenario($file, $prefix, $tmpPath);
disabledMatrix($file, $prefix, $tmpPath, true, true);

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
		disabledMatrix($sql, $prefix, $tmpPath, true, $tableCreated);
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
		disabledMatrix($redis, $prefix, $tmpPath, true, true);
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

// Memcache
$memcacheDsn = getenv('TEMMA_TEST_MEMCACHE');
if ($memcacheDsn && extension_loaded('memcached')) {
	if (!str_contains($memcacheDsn, '#'))
		$memcacheDsn .= '#temma-test';
	print("\n" . TµAnsi::bold("Memcache data source ($memcacheDsn)\n"));
	$memcache = \Temma\Datasources\Memcache::factory($memcacheDsn);
	try {
		disabledMatrix($memcache, $prefix, $tmpPath, false, true);
		check('mWrite() converts numbers to strings, and skips the other non-string values', function() use ($memcache, $prefix) {
			$written = $memcache->mWrite(["{$prefix}conv-int" => 12, "{$prefix}conv-array" => ['a'], "{$prefix}conv-str" => 'ok', "{$prefix}conv-null" => null]);
			return ($written === 2 && $memcache->read("{$prefix}conv-int") === '12' && $memcache->read("{$prefix}conv-str") === 'ok' &&
			        !$memcache->isSet("{$prefix}conv-array"));
		});
	} finally {
		$memcache->mRemove(["{$prefix}conv-int", "{$prefix}conv-str", "{$prefix}dis-key"]);
	}
} else
	print("\n" . TµAnsi::color('yellow', 'Memcache data source skipped (TEMMA_TEST_MEMCACHE not defined or memcached extension not loaded).') . "\n");

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
