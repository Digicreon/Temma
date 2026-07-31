#!/usr/bin/php
<?php

/**
 * DAO validation script:
 * - default table name derived from the controller class name (not from the URL anymore);
 * - support of the 'source' parameter of _loadDao();
 * - SQL identifiers escaping (database, table, fields) in Dao and Criteria.
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

	public function __construct() {
		parent::__construct('mysqli', null, null, 'localhost', null, 'testbase');
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
check('create: backquoted INSERT INTO', str_starts_with($db->lastQuery(), 'INSERT INTO `user` SET '));
check('create: escaped field', str_contains($db->lastQuery(), "`na``me` = 'bob'"));
check('create: lastInsertId returned', $id === 42);
$evil->create(['a' => 1]);
check('create: escaped database and table', str_starts_with($db->lastQuery(), 'INSERT INTO `ba``se`.`us``er` SET '));
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

/* ********** RESULT ********** */
print(TµAnsi::bold($failed ? "$failed test(s) failed out of $count\n" : "All tests passed ($count)\n"));
exit($failed ? 1 : 0);
