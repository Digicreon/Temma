#!/usr/bin/php
<?php

/**
 * Script de validation des DAO :
 * - nom de table par défaut dérivé du nom de la classe du contrôleur (et non plus de l'URL) ;
 * - prise en compte du paramètre 'source' de _loadDao() ;
 * - échappement des identifiants SQL (base, table, champs) dans Dao et Criteria.
 * Utilise un faux datasource SQL qui enregistre les requêtes générées au lieu de les exécuter.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** FAUX DATASOURCE SQL ********** */
class FakeSql extends \Temma\Datasources\Sql {
	/** Requêtes SQL reçues. */
	public array $queries = [];
	/** Résultat à retourner par queryOne(). */
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

/* ********** CONTRÔLEURS DE TEST ********** */
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
// contrôleur avec namespace
eval('namespace Blog;
      class Post extends \Temma\Web\Controller {
	protected $_temmaAutoDao = true;
	public function getDao() : \Temma\Dao\Dao {
		return ($this->_dao);
	}
}');

/* ********** HARNAIS ********** */
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

/* ********** NOM DE TABLE PAR DÉFAUT ********** */
print(TµAnsi::bold("Nom de table par défaut\n"));
$db = new FakeSql();
$loader = new TµLoader([
	'dataSources' => ['db' => $db],
	'response'    => new \Temma\Web\Response(),
]);
// nom de classe simple
$ctrl = new TestUser($loader);
check("classe TestUser => table 'testUser'", $ctrl->getDao()->getTableName() === 'testUser');
// le nom de table ne dépend pas de la variable de template CONTROLLER (URL)
$executor = new \Temma\Web\Controller($loader);
$executor['CONTROLLER'] = 'weird-url-name';
$ctrl = new TestUser($loader);
check("indépendant de l'URL (variable CONTROLLER ignorée)", $ctrl->getDao()->getTableName() === 'testUser');
// namespace retiré
$ctrl = new \Blog\Post($loader);
check("classe \\Blog\\Post => table 'post'", $ctrl->getDao()->getTableName() === 'post');
// suffixe de contrôleur retiré (configuration 'controllersSuffix')
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
check("classe ArticleController + suffixe => table 'article'", $ctrl->getDao()->getTableName() === 'article');
// nom de table explicite prioritaire
$ctrl = new TestExplicit($loader);
check("paramètre 'table' explicite prioritaire", $ctrl->getDao()->getTableName() === 'explicit');
// paramètre 'source' honoré
$db2 = new FakeSql();
$loaderSources = new TµLoader([
	'dataSources' => ['db' => $db, 'other' => $db2],
	'response'    => new \Temma\Web\Response(),
]);
$ctrl = new TestSource($loaderSources);
check("paramètre 'source' honoré", $ctrl->getDao()->getDataBase() === $db2);

/* ********** ÉCHAPPEMENT DES IDENTIFIANTS ********** */
print(TµAnsi::bold("Échappement des identifiants SQL\n"));
$dao = new \Temma\Dao\Dao($db, null, 'user');
$evil = new \Temma\Dao\Dao($db, null, 'us`er', 'i`d', 'ba`se');
// count
$dao->count();
check('count : table backtickée', str_contains($db->lastQuery(), 'FROM `user`'));
$evil->count();
check('count : base et table échappées', str_contains($db->lastQuery(), 'FROM `ba``se`.`us``er`'));
// injection par le nom de table neutralisée
$inject = new \Temma\Dao\Dao($db, null, 'user` WHERE 1 -- ');
$inject->count();
check('count : injection par le nom de table neutralisée', str_contains($db->lastQuery(), 'FROM `user`` WHERE 1 -- `'));
// get
$db->nextResult = ['id' => 3];
$evil->get(3);
check('get : clé primaire échappée', str_contains($db->lastQuery(), "WHERE `i``d` = '3'"));
$db->nextResult = null;
// create
$id = $dao->create(['na`me' => 'bob']);
check('create : INSERT INTO backtické', str_starts_with($db->lastQuery(), 'INSERT INTO `user` SET '));
check('create : champ échappé', str_contains($db->lastQuery(), "`na``me` = 'bob'"));
check('create : lastInsertId retourné', $id === 42);
$evil->create(['a' => 1]);
check('create : base et table échappées', str_starts_with($db->lastQuery(), 'INSERT INTO `ba``se`.`us``er` SET '));
// create en mode safe
$dao->create(['name' => 'bob'], 'counter');
check('create safe : LAST_INSERT_ID échappé', str_contains($db->lastQuery(), "`id` = LAST_INSERT_ID(`id`)"));
check('create safe : champ absent conservé (`counter` = `counter`)', str_contains($db->lastQuery(), '`counter` = `counter`'));
$dao->create(['name' => 'bob', 'n`b' => 3], ['n`b']);
check('create safe : liste de champs échappée', str_contains($db->lastQuery(), "ON DUPLICATE KEY UPDATE") && str_contains($db->lastQuery(), "`n``b` = '3'"));
$dao->create(['name' => 'bob'], 12);
check('create safe : clé primaire conservée (`id` = `id`)', str_contains($db->lastQuery(), '`id` = `id`'));
// update
$dao->update(5, ['fi`eld' => 'v']);
check('update : champ échappé', str_contains($db->lastQuery(), "`fi``eld` = 'v'"));
check('update : clé primaire échappée', str_contains($db->lastQuery(), "WHERE `id` = '5'"));
$dao->update(5, ['flag' => true, 'x' => null]);
check('update : booléen et NULL backtickés', str_contains($db->lastQuery(), '`flag` = TRUE') && str_contains($db->lastQuery(), '`x` = NULL'));
// remove
$evil->remove(7);
check('remove : identifiants échappés', str_contains($db->lastQuery(), "DELETE FROM `ba``se`.`us``er` WHERE `i``d` = '7'"));
// tri
$dao->search(null, 'na`me');
check('search : champ de tri échappé', str_contains($db->lastQuery(), 'ORDER BY `na``me`'));
$dao->search(null, '-age');
check('search : tri descendant échappé', str_contains($db->lastQuery(), 'ORDER BY `age` DESC'));
$dao->search(null, ['age' => 'desc', 'name']);
check('search : tri multiple échappé', str_contains($db->lastQuery(), 'ORDER BY `age` DESC, `name` ASC'));
$dao->search(null, true);
check('search : tri sur clé primaire échappé', str_contains($db->lastQuery(), 'ORDER BY `id` DESC'));
// critères
$dao->search(['na`me' => 'x']);
check('search : champ de critère échappé', str_contains($db->lastQuery(), "WHERE `na``me` = 'x'"));
$crit = $dao->criteria()->equal('em`ail', 'a@b.c');
$dao->search($crit);
check('criteria : champ échappé', str_contains($db->lastQuery(), "WHERE `em``ail` = 'a@b.c'"));
// liste de champs
$daoFields = new \Temma\Dao\Dao($db, null, 'user', 'id', null, ['first_name' => 'firstName', 'age']);
$daoFields->search();
check('fields : alias échappés', str_contains($db->lastQuery(), 'SELECT `first_name` AS `firstName`, `age` FROM `user`'));

/* ********** RÉSULTAT ********** */
print(TµAnsi::bold($failed ? "$failed test(s) en échec sur $count\n" : "Tous les tests ont réussi ($count)\n"));
exit($failed ? 1 : 0);
