#!/usr/bin/php
<?php

/**
 * Validation script for the Sql data source (with an SQLite database in memory):
 * - queryOne() and queryAll(), on the data source and on prepared statements (SqlStatement), with and without
 *   the $keyField and $valueField parameters;
 * - quote() and quoteNull(), on an enabled and on a disabled data source (no PHP warning or deprecation notice).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings, notices and deprecation notices are converted to exceptions, so they make the tests fail
error_reporting(E_ALL);
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
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

/* ********** TESTS ********** */
if (!extension_loaded('pdo_sqlite')) {
	print(TµAnsi::color('yellow', 'The pdo_sqlite extension is not loaded, tests skipped.') . "\n");
	exit(0);
}
$db = \Temma\Datasources\Sql::factory('sqlite::memory:');
$db->exec('CREATE TABLE article (id INTEGER PRIMARY KEY, title TEXT, status TEXT)');
$db->exec("INSERT INTO article (id, title, status) VALUES (1, 'First', 'valid'), (2, 'Second', 'valid'), (3, 'Third', 'draft')");

print(TµAnsi::bold("queryOne()\n"));
check('Sql::queryOne() returns the line, or an empty array if no line was found', function() use ($db) {
	return ($db->queryOne('SELECT * FROM article WHERE id = 1') === ['id' => 1, 'title' => 'First', 'status' => 'valid'] &&
	        $db->queryOne('SELECT * FROM article WHERE id = 99') === []);
});
check('Sql::queryOne() with a field name returns its value (null if no line or unknown field)', function() use ($db) {
	return ($db->queryOne('SELECT COUNT(*) AS cnt FROM article', 'cnt') === 3 &&
	        $db->queryOne('SELECT title FROM article WHERE id = :id', 'title', [':id' => 2]) === 'Second' &&
	        $db->queryOne('SELECT title FROM article WHERE id = 99', 'title') === null &&
	        $db->queryOne('SELECT title FROM article WHERE id = 1', 'unknown') === null);
});
check('SqlStatement::queryOne() returns the line, or an empty array if no line was found', function() use ($db) {
	$statement = $db->prepare('SELECT * FROM article WHERE id = ?');
	return ($statement->queryOne([3]) === ['id' => 3, 'title' => 'Third', 'status' => 'draft'] &&
	        $statement->queryOne([99]) === []);
});
check('SqlStatement::queryOne() with a field name returns its value (null if no line or unknown field)', function() use ($db) {
	$statement = $db->prepare('SELECT id, title FROM article WHERE id = :id');
	return ($statement->queryOne([':id' => 1], 'title') === 'First' &&
	        $statement->queryOne([':id' => 2], 'id') === 2 &&
	        $statement->queryOne([':id' => 99], 'title') === null &&
	        $statement->queryOne([':id' => 1], 'unknown') === null);
});

print("\n" . TµAnsi::bold("queryAll()\n"));
check('SqlStatement::queryAll() returns all the lines, indexed by a key field and/or reduced to a value field', function() use ($db) {
	$statement = $db->prepare('SELECT id, title FROM article WHERE status = ? ORDER BY id');
	return ($statement->queryAll(['valid']) === [['id' => 1, 'title' => 'First'], ['id' => 2, 'title' => 'Second']] &&
	        $statement->queryAll(['valid'], null, 'title') === ['First', 'Second'] &&
	        $statement->queryAll(['valid'], 'id', 'title') === [1 => 'First', 2 => 'Second'] &&
	        $statement->queryAll(['valid'], 'id') === [1 => ['id' => 1, 'title' => 'First'], 2 => ['id' => 2, 'title' => 'Second']] &&
	        $statement->queryAll(['unknown']) === []);
});

print("\n" . TµAnsi::bold("quote() and quoteNull()\n"));
check('quote() escapes strings and numbers; false gives "0", null gives an empty string', function() use ($db) {
	return ($db->quote("l'article") === "'l''article'" && $db->quote(12) === "'12'" &&
	        $db->quote(false) === "'0'" && $db->quote(null) === "''" && $db->quote('') === "''");
});
check('quoteNull() returns NULL for empty values', function() use ($db) {
	return ($db->quoteNull(null) === 'NULL' && $db->quoteNull('') === 'NULL' && $db->quoteNull("l'article") === "'l''article'");
});
check('quote() on a disabled data source: same results for false and null, without PHP deprecation notice', function() {
	$disabled = \Temma\Datasources\Sql::factory('sqlite::memory:')->disable();
	return ($disabled->quote(null) === "''" && $disabled->quote(false) === "'0'" &&
	        $disabled->quote("l'article") === "'l\\'article'" && $disabled->quote(12) === "'12'" &&
	        $disabled->quoteNull(null) === 'NULL' && $disabled->quoteNull('text') === "'text'");
});

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
