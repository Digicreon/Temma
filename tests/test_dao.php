#!/usr/bin/php
<?php

/**
 * DAO validation script:
 * - default table name derived from the controller class name (not from the URL anymore);
 * - support of the 'source' parameter of _loadDao();
 * - SQL identifiers escaping (database, table, fields) in Dao and Criteria;
 * - SQL generation adapted to the database engine (MySQL, PostgreSQL, SQLite);
 * - SQL DSN parsing (including SQLite DSNs, which contain a file path).
 * Uses a fake SQL data source which records the generated queries instead of executing them.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** FAKE SQL DATA SOURCE ********** */
class FakeSql extends \Temma\Datasources\Sql {
	/** Received SQL queries. */
	public array $queries = [];
	/** Result to be returned by queryOne(). */
	public mixed $nextResult = null;

	public function __construct(string $type='mysqli') {
		parent::__construct($type, null, null, 'localhost', null, 'testbase');
	}
	public function quote(mixed $str) : string {
		if (is_null($str))
			return ('NULL');
		return ("'" . addslashes((string)$str) . "'");
	}
	public function exec(string $sql, bool $buffered=false, ?array $parameters=null) : int {
		$this->queries[] = $sql;
		return (1);
	}
	public function queryOne(string $sql, ?string $valueField=null, ?array $parameters=null) : mixed {
		$this->queries[] = $sql;
		$res = $this->nextResult ?? ['nb' => 0];
		return ($valueField ? ($res[$valueField] ?? null) : $res);
	}
	public function queryAll(string $sql, ?string $keyField=null, ?string $valueField=null, ?array $parameters=null) : array {
		$this->queries[] = $sql;
		return ([]);
	}
	public function lastInsertId() : int {
		return (42);
	}
	public function lastQuery() : string {
		return (end($this->queries) ?: '');
	}
}

/* ********** TEST CONTROLLERS ********** */
class TestUser extends \Temma\Web\Controller {
	protected $_temmaAutoDao = true;
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}
class ArticleController extends \Temma\Web\Controller {
	protected $_temmaAutoDao = true;
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}
class TestExplicit extends \Temma\Web\Controller {
	protected $_temmaAutoDao = ['table' => 'explicit'];
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}
class TestSource extends \Temma\Web\Controller {
	protected $_temmaAutoDao = ['source' => 'other'];
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}
// namespaced controller
eval('namespace Blog;
      class Post extends \Temma\Web\Controller {
	protected $_temmaAutoDao = true;
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}');

/* ********** TEST HARNESS ********** */
$count = 0;
$failed = 0;
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}

/* ********** DEFAULT TABLE NAME ********** */
print(TµAnsi::bold("Default table name\n"));
$db = new FakeSql();
$loader = new TµLoader([
	'dataSources' => ['db' => $db],
	'response'    => new \Temma\Web\Response(),
]);
// simple class name
$ctrl = new TestUser($loader);
check("class TestUser => table 'testUser'", $ctrl->getDao()->getTableName() === 'testUser');
// the table name doesn't depend on the CONTROLLER template variable (URL)
$executor = new \Temma\Web\Controller($loader);
$executor['CONTROLLER'] = 'weird-url-name';
$ctrl = new TestUser($loader);
check("independent from the URL (CONTROLLER variable ignored)", $ctrl->getDao()->getTableName() === 'testUser');
// namespace removed
$ctrl = new \Blog\Post($loader);
check("class \\Blog\\Post => table 'post'", $ctrl->getDao()->getTableName() === 'post');
// controllers' suffix removed ('controllersSuffix' configuration)
$appPath = sys_get_temp_dir() . '/temma-dao-test-' . getmypid();
mkdir("$appPath/etc", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});
file_put_contents("$appPath/etc/temma.php", "<?php return ['application' => ['controllersSuffix' => 'Controller']];");
$config = new \Temma\Web\Config($appPath);
$config->readConfigurationFile();
$loaderSuffix = new TµLoader([
	'dataSources' => ['db' => $db],
	'response'    => new \Temma\Web\Response(),
	'config'      => $config,
]);
$ctrl = new ArticleController($loaderSuffix);
check("class ArticleController + suffix => table 'article'", $ctrl->getDao()->getTableName() === 'article');
// explicit table name takes precedence
$ctrl = new TestExplicit($loader);
check("explicit 'table' parameter takes precedence", $ctrl->getDao()->getTableName() === 'explicit');
// 'source' parameter honored
$db2 = new FakeSql();
$loaderSources = new TµLoader([
	'dataSources' => ['db' => $db, 'other' => $db2],
	'response'    => new \Temma\Web\Response(),
]);
$ctrl = new TestSource($loaderSources);
check("'source' parameter honored", $ctrl->getDao()->getDataBase() === $db2);

/* ********** SQL IDENTIFIERS ESCAPING ********** */
print(TµAnsi::bold("SQL identifiers escaping\n"));
$dao = new \Temma\Dao\Dao($db, null, 'user');
$evil = new \Temma\Dao\Dao($db, null, 'us`er', 'i`d', 'ba`se');
// count
$dao->count();
check('count: backquoted table', str_contains($db->lastQuery(), 'FROM `user`'));
$evil->count();
check('count: escaped database and table', str_contains($db->lastQuery(), 'FROM `ba``se`.`us``er`'));
// injection through the table name neutralized
$inject = new \Temma\Dao\Dao($db, null, 'user` WHERE 1 -- ');
$inject->count();
check('count: injection through the table name neutralized', str_contains($db->lastQuery(), 'FROM `user`` WHERE 1 -- `'));
// get
$db->nextResult = ['id' => 3];
$evil->get(3);
check('get: escaped primary key', str_contains($db->lastQuery(), "WHERE `i``d` = '3'"));
$db->nextResult = null;
// create
$id = $dao->create(['na`me' => 'bob']);
check('create: backquoted INSERT INTO', str_starts_with($db->lastQuery(), 'INSERT INTO `user` ('));
check('create: escaped field', str_contains($db->lastQuery(), "(`na``me`) VALUES ('bob')"));
check('create: lastInsertId returned', $id === 42);
$evil->create(['a' => 1]);
check('create: escaped database and table', str_starts_with($db->lastQuery(), 'INSERT INTO `ba``se`.`us``er` ('));
// safe-mode create
$dao->create(['name' => 'bob'], 'counter');
check('safe create: escaped LAST_INSERT_ID', str_contains($db->lastQuery(), "`id` = LAST_INSERT_ID(`id`)"));
check('safe create: absent field kept (`counter` = `counter`)', str_contains($db->lastQuery(), '`counter` = `counter`'));
$dao->create(['name' => 'bob', 'n`b' => 3], ['n`b']);
check('safe create: escaped fields list', str_contains($db->lastQuery(), "ON DUPLICATE KEY UPDATE") && str_contains($db->lastQuery(), "`n``b` = '3'"));
$dao->create(['name' => 'bob'], 12);
check('safe create: primary key kept (`id` = `id`)', str_contains($db->lastQuery(), '`id` = `id`'));
// update
$dao->update(5, ['fi`eld' => 'v']);
check('update: escaped field', str_contains($db->lastQuery(), "`fi``eld` = 'v'"));
check('update: escaped primary key', str_contains($db->lastQuery(), "WHERE `id` = '5'"));
$dao->update(5, ['flag' => true, 'x' => null]);
check('update: backquoted boolean and NULL', str_contains($db->lastQuery(), '`flag` = TRUE') && str_contains($db->lastQuery(), '`x` = NULL'));
// remove
$evil->remove(7);
check('remove: escaped identifiers', str_contains($db->lastQuery(), "DELETE FROM `ba``se`.`us``er` WHERE `i``d` = '7'"));
// sort
$dao->search(null, 'na`me');
check('search: escaped sort field', str_contains($db->lastQuery(), 'ORDER BY `na``me`'));
$dao->search(null, '-age');
check('search: escaped descending sort', str_contains($db->lastQuery(), 'ORDER BY `age` DESC'));
$dao->search(null, ['age' => 'desc', 'name']);
check('search: escaped multiple sort', str_contains($db->lastQuery(), 'ORDER BY `age` DESC, `name` ASC'));
$dao->search(null, true);
check('search: escaped primary key sort', str_contains($db->lastQuery(), 'ORDER BY `id` DESC'));
// criteria
$dao->search(['na`me' => 'x']);
check('search: escaped criteria field', str_contains($db->lastQuery(), "WHERE `na``me` = 'x'"));
$crit = $dao->criteria()->equal('em`ail', 'a@b.c');
$dao->search($crit);
check('criteria: escaped field', str_contains($db->lastQuery(), "WHERE `em``ail` = 'a@b.c'"));
// fields list
$daoFields = new \Temma\Dao\Dao($db, null, 'user', 'id', null, ['first_name' => 'firstName', 'age']);
$daoFields->search();
check('fields: escaped aliases', str_contains($db->lastQuery(), 'SELECT `first_name` AS `firstName`, `age` FROM `user`'));

/* ********** SQL DSN PARSING ********** */
print(TµAnsi::bold("SQL DSN parsing\n"));
$reflection = new ReflectionClass(\Temma\Datasources\Sql::class);
$typeProperty = $reflection->getProperty('_type');
$baseProperty = $reflection->getProperty('_base');
$sql = \Temma\Datasources\Sql::factory('sqlite:/tmp/test.sq3');
check("factory('sqlite:/absolute/path')", $typeProperty->getValue($sql) === 'sqlite' && $baseProperty->getValue($sql) === '/tmp/test.sq3');
$sql = \Temma\Datasources\Sql::factory('sqlite:data/test.sq3');
check("factory('sqlite:relative/path')", $baseProperty->getValue($sql) === 'data/test.sq3');
$sql = \Temma\Datasources\Sql::factory('sqlite::memory:');
check("factory('sqlite::memory:')", $baseProperty->getValue($sql) === ':memory:');
$sql = \Temma\Datasources\Sql::factory('sqlite2:/tmp/test.sq3');
check("factory('sqlite2:...')", $typeProperty->getValue($sql) === 'sqlite2');
$sql = \Temma\Base\Datasource::factory('sqlite:/tmp/test.sq3');
check('Datasource::factory() routes SQLite DSNs', $sql instanceof \Temma\Datasources\Sql);
$sql = \Temma\Datasources\Sql::factory('mysql://user:pwd@localhost/mybase');
check('classic DSN still parsed', $typeProperty->getValue($sql) === 'mysql' && $baseProperty->getValue($sql) === 'mybase');

/* ********** POSTGRESQL AND SQLITE DIALECTS ********** */
print(TµAnsi::bold("PostgreSQL and SQLite dialects\n"));
check("Sql::getType()", $db->getType() === 'mysql' && (new FakeSql('pgsql'))->getType() === 'pgsql');
// PostgreSQL
$pgDb = new FakeSql('pgsql');
$pgEvil = new \Temma\Dao\Dao($pgDb, null, 'us"er', 'id', 'ba"se');
$pgEvil->count();
check('pgsql: escaped double-quoted identifiers', str_contains($pgDb->lastQuery(), 'FROM "ba""se"."us""er"'));
$pg = new \Temma\Dao\Dao($pgDb, null, 'user');
$pgDb->nextResult = ['id' => 7];
$newId = $pg->create(['name' => 'bob']);
check('pgsql: INSERT ... RETURNING', str_contains($pgDb->lastQuery(), 'INSERT INTO "user" ("name") VALUES (\'bob\') RETURNING "id"'));
check('pgsql: id fetched from RETURNING', $newId === 7);
$pg->create(['name' => 'bob'], 'counter');
check('pgsql: ON CONFLICT upsert, absent field kept', str_contains($pgDb->lastQuery(), 'ON CONFLICT ("id") DO UPDATE SET "counter" = "user"."counter" RETURNING "id"'));
$pg->create(['name' => 'bob'], 12);
check('pgsql: no-op upsert', str_contains($pgDb->lastQuery(), 'DO UPDATE SET "id" = "user"."id"'));
$pgDb->nextResult = null;
$pg->search(null, false);
check('pgsql: RANDOM()', str_contains($pgDb->lastQuery(), 'ORDER BY RANDOM()'));
$pg->search(null, null, 5, 10);
check('pgsql: LIMIT ... OFFSET', str_contains($pgDb->lastQuery(), 'LIMIT 10 OFFSET 5'));
$pg->search(null, null, 5);
check('pgsql: bare OFFSET', str_ends_with($pgDb->lastQuery(), 'OFFSET 5') && !str_contains($pgDb->lastQuery(), 'LIMIT'));
try {
	$pg->update(3, ['a' => 'b'], null, 10);
	check('pgsql: update with limit => exception', false);
} catch (\Temma\Exceptions\Dao $e) {
	check('pgsql: update with limit => exception', true);
}
$pgDb->nextResult = ['cnt' => 1];
check('pgsql: tableExists using information_schema', $pg->tableExists() && str_contains($pgDb->lastQuery(), 'current_schema()'));
$pgDb->nextResult = ['dbname' => 'testbase'];
check('pgsql: getDatabaseName using current_database()', $pg->getDatabaseName() === 'testbase' && str_contains($pgDb->lastQuery(), 'current_database()'));
$pgDb->nextResult = null;
// SQLite
$slDb = new FakeSql('sqlite');
$sl = new \Temma\Dao\Dao($slDb, null, 'user');
$sl->count();
check('sqlite: backquotes kept', str_contains($slDb->lastQuery(), 'FROM `user`'));
$slId = $sl->create(['name' => 'bob']);
check('sqlite: simple INSERT without RETURNING', str_ends_with($slDb->lastQuery(), "VALUES ('bob')") && $slId === 42);
$slDb->nextResult = ['id' => 9];
$slId = $sl->create(['name' => 'bob'], true);
check('sqlite: ON CONFLICT upsert + RETURNING', str_contains($slDb->lastQuery(), "ON CONFLICT (`id`) DO UPDATE SET `name` = 'bob' RETURNING `id`") && $slId === 9);
$slDb->nextResult = null;
$sl->search(null, false);
check('sqlite: RANDOM()', str_contains($slDb->lastQuery(), 'ORDER BY RANDOM()'));
$sl->search(null, null, 5);
check('sqlite: LIMIT -1 OFFSET', str_contains($slDb->lastQuery(), 'LIMIT -1 OFFSET 5'));
$slDb->nextResult = ['cnt' => 1];
check('sqlite: tableExists using sqlite_master', $sl->tableExists() && str_contains($slDb->lastQuery(), 'sqlite_master'));
$slDb->nextResult = null;
check("sqlite: getDatabaseName => 'main'", $sl->getDatabaseName() === 'main');
$sl2 = new \Temma\Dao\Dao(new FakeSql('sqlite2'), null, 'user');
check("sqlite2: treated as sqlite", str_contains((function($d) { $d->search(null, false); return ($d->getDataBase()->lastQuery()); })($sl2), 'RANDOM()'));
// back to MySQL: offset without limit
$dao->search(null, null, 5);
check('mysql: bare offset => huge LIMIT', str_contains($db->lastQuery(), 'LIMIT 18446744073709551615 OFFSET 5'));
// Sql::lastInsertId() on PostgreSQL (LASTVAL), without overriding lastInsertId()
class FakePgRaw extends \Temma\Datasources\Sql {
	public array $queries = [];
	public function __construct() {
		parent::__construct('pgsql', null, null, 'localhost', null, 'testbase');
	}
	public function queryOne(string $sql, ?string $valueField=null, ?array $parameters=null) : mixed {
		$this->queries[] = $sql;
		return (33);
	}
}
$raw = new FakePgRaw();
check('Sql::lastInsertId on pgsql using LASTVAL()', $raw->lastInsertId() === 33 && str_contains((string)end($raw->queries), 'LASTVAL()'));

/* ********** SQLITE INTEGRATION ********** */
// these tests execute the generated SQL against a real SQLite database
if (!extension_loaded('pdo_sqlite'))
	print(TµAnsi::bold("SQLite integration (skipped: no pdo_sqlite extension)\n"));
else {
	print(TµAnsi::bold("SQLite integration\n"));
	$dbFile = "$appPath/test.sq3";
	$sqlite = \Temma\Datasources\Sql::factory("sqlite:$dbFile");
	$sqlite->exec('CREATE TABLE `user` (
			`id`    INTEGER PRIMARY KEY AUTOINCREMENT,
			`name`  TEXT,
			`email` TEXT,
			`age`   INTEGER
		)');
	check('connection through a SQLite DSN', is_file($dbFile));
	$dao = new \Temma\Dao\Dao($sqlite, null, 'user');
	check('tableExists() on an existing table', $dao->tableExists());
	check('tableExists() on a missing table', !$dao->tableExists('nosuchtable'));
	check("getDatabaseName() => 'main'", $dao->getDatabaseName() === 'main');
	// create
	$id1 = $dao->create(['name' => 'Alice', 'email' => 'alice@x.com', 'age' => 30]);
	$id2 = $dao->create(['name' => 'Bob', 'email' => 'bob@x.com', 'age' => 20]);
	check('create() returns the new primary key', $id1 === 1 && $id2 === 2);
	// get
	$user = $dao->get($id1);
	check('get() by primary key', ($user['name'] ?? null) === 'Alice' && (int)($user['age'] ?? 0) === 30);
	// count
	check('count() without criteria', $dao->count() === 2);
	check('count() with criteria', $dao->count(['name' => 'Bob']) === 1);
	// search with criteria and sort
	$users = $dao->search(null, 'age');
	check('search() with ascending sort', array_keys($users) === [2, 1]);
	$users = $dao->search(null, '-age');
	check('search() with descending sort', array_keys($users) === [1, 2]);
	$users = $dao->search($dao->criteria()->greaterThan('age', 25));
	check('search() with a criteria', count($users) === 1 && isset($users[1]));
	// limit and offset
	$users = $dao->search(null, 'age', 1, 1);
	check('search() with limit and offset', array_keys($users) === [1]);
	$users = $dao->search(null, 'age', 1);
	check('search() with a bare offset', array_keys($users) === [1]);
	// random sort
	$users = $dao->search(null, false);
	check('search() with random sort (RANDOM())', count($users) === 2);
	// fields aliasing
	$daoAlias = new \Temma\Dao\Dao($sqlite, null, 'user', 'id', null, ['id' => 'id', 'name' => 'userName']);
	$user = $daoAlias->get(1);
	check('search() with aliased fields', ($user['userName'] ?? null) === 'Alice');
	// update
	$nbr = $dao->update($id2, ['age' => 21, 'name' => 'Bobby']);
	check('update() returns the number of modified records', $nbr === 1);
	check('update() really updated the record', (int)$dao->get($id2)['age'] === 21);
	// update with sort/limit is not supported outside MySQL
	try {
		$dao->update($id2, ['age' => 22], null, 1);
		check('update() with a limit throws an exception', false);
	} catch (\Temma\Exceptions\Dao $e) {
		check('update() with a limit throws an exception', true);
	}
	// safe-mode create (upsert): the conflicting record is updated, not duplicated
	$id3 = $dao->create(['id' => $id1, 'name' => 'Alicia', 'age' => 31], true);
	check('safe create() returns the primary key (RETURNING)', $id3 === $id1);
	check('safe create() updated the record', $dao->get($id1)['name'] === 'Alicia');
	check('safe create() did not duplicate the record', $dao->count() === 2);
	// safe-mode create keeping the former value of a field
	$dao->create(['id' => $id1, 'name' => 'Ignored'], 'email');
	check('safe create() keeps the former value of an absent field', $dao->get($id1)['email'] === 'alice@x.com');
	// remove
	check('remove() returns the number of deleted records', $dao->remove($id2) === 1);
	check('remove() really deleted the record', $dao->count() === 1);
	$dao->remove();
	check('remove() without criteria empties the table', $dao->count() === 0);
	// a failed query must not stay in the buffered requests list and poison the connection
	$dao->create(['id' => 10, 'name' => 'Ten']);
	try {
		$dao->create(['id' => 10, 'name' => 'Duplicate']);
		check('a duplicate primary key is rejected', false);
	} catch (\Throwable $e) {
		check('a duplicate primary key is rejected', true);
	}
	try {
		$dao->create(['id' => 11, 'name' => 'Eleven']);
		check('a failed write doesn\'t poison the next write', true);
	} catch (\Throwable $e) {
		check('a failed write doesn\'t poison the next write', false);
	}
	try {
		check('a failed write doesn\'t poison the next read', $dao->count() === 2);
	} catch (\Throwable $e) {
		check('a failed write doesn\'t poison the next read', false);
	}
}

/* ********** RESULT ********** */
print(TµAnsi::bold($failed ? "$failed test(s) failed out of $count\n" : "All tests passed ($count)\n"));
exit($failed ? 1 : 0);
