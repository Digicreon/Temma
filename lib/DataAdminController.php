<?php

/**
 * Contrôleur servant à parcourir une base de données de manière minimale.
 *
 * Ce contrôleur est auto-suffisant : Il ne nécessite pas de template pour afficher ses données.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2011, Fine Media
 * @package	Temma
 * @subpackage	Controllers
 * @version	$Id$
 */
class DataAdminController extends \Temma\Controller {
	/** Nom de la base de données courante. */
	private $_currentDb = null;
	/** Nom de la table courante. */
	private $_currentTable = null;
	/** Liste des bases accessibles. */
	private $_listDb = array();
	/** Liste des tables accessibles. */
	private $_listTables = array();

	/** Initialisation. */
	public function init() {
		// récupération de la liste des bases et de la base courante
		$this->_fetchDatabases();
		$db = $_REQUEST['db'];
		if (!empty($db) && in_array($db, $this->_listDb))
			$this->_currentDb = $db;
		else {
			$currentDb = $this->_db->queryOne("SELECT DATABASE() AS db");
			$this->_currentDb = $currentDb['db'];
		}
		// récupération de la liste des tables et de la table courante
		$this->_fetchTables();
		$table = $_REQUEST['table'];
		if (!empty($table) && in_array($table, $this->_listTables))
			$this->_currentTable = $table;
	}
	/** Action par défaut. */
	public function execIndex() {
		$this->_printHeader();
		print("<table border='1' style='margin: 0 auto; margin-top: 20px;'>");
		print("<tr><th>Base</th><th># tables</th></tr>");
		foreach ($this->_listDb as $db) {
			print("<tr><td><a href='/" . $this->get("CONTROLLER") . "/database?db=$db'>$db</a></td>");
			$tables = $this->_db->queryAll("SHOW TABLES IN $db");
			print("<td style='font-type: monospace; text-align: right;'>" . count($tables) . "</td></tr>");
		}
		print("</table>");
		$this->_printFooter();
		return (self::EXEC_QUIT);
	}
	/** Affichage d'une base de données. */
	public function execDatabase() {
		$this->_printHeader();
		print("<table border='1' style='margin: 0 auto; margin-top: 20px;'>");
		print("<tr><th colspan='2'>" . $this->_currentDb . "</th></tr>");
		print("<tr><th>Tables</th><th># rows</th></tr>");
		foreach ($this->_listTables as $table) {
			print("<tr><td><a href='/" . $this->get("CONTROLLER") . "/table?db=" . $this->_currentDb . "&amp;table=$table'>$table</a></td>");
			$nbr = $this->_db->queryOne("SELECT COUNT(*) AS n FROM " . $this->_currentDb . ".$table");
			print("<td style='font-type: monospace; text-align: right;'>" . $nbr['n'] . "</td></tr>");
		}
		print("</table>");
		$this->_printFooter();
		return (self::EXEC_QUIT);
	}
	/**
	 * Affichage d'une table.
	 * @param	int	$page	(optionnel) Numéro de page. 0 par défaut.
	 */
	public function execTable($page=0) {
		if (empty($this->_currentTable)) {
			$this->redirect("/" . $this->get("CONTROLLER") . "/database");
			return;
		}
		$this->_printHeader();
		// affichage du modèle de la table
		print("<table border='1'>");
		print("<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>");
		$fields = $this->_db->queryAll("DESC " . $this->_currentDb . "." . $this->_currentTable);
		foreach ($fields as $line) {
			print("<tr>");
			print("<td>" . ($line['Field'] ? $line['Field'] : "&nbsp;") . "</td>");
			print("<td>" . ($line['Type'] ? $line['Type'] : "&nbsp;") . "</td>");
			print("<td>" . ($line['Null'] ? $line['Null'] : "&nbsp;"). "</td>");
			print("<td>" . ($line['Key'] ? $line['Key'] : "&nbsp;") . "</td>");
			print("<td>" . ($line['Default'] ? $line['Default'] : "&nbsp;") . "</td>");
			print("<td>" . ($line['Extra'] ? $line['Extra'] : "&nbsp;") . "</td>");
			print("</tr>");
			if ($line['Key'] == "PRI")
				$id = $line['Field'];
		}
		print("</table>");
		// récupération des données
		$dao = new \Temma\Dao($this->_db, null, $this->_currentTable, $id, $this->_currentDb);
		$lines = $dao->search(null, null, ($page * 10), 10);
		print("<br /><br />");
		print("<table border='1'>");
		print("<tr>");
		foreach ($fields as $field)
			print("<th>" . $field['Field'] . "</th>");
		print("</tr>");
		foreach ($lines as $line) {
			print("<tr>");
			foreach ($fields as $field)
				print("<td>" . ($line[$field['Field']] ? htmlspecialchars($line[$field['Field']]) : "&nbsp;") . "</td>");
			print("</tr>");
		}
		print("<tr><td colspan='" . count($fields) . "'>");
		print("<a href='/" . $this->get("CONTROLLER") . "/table/0?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'>&lt;&lt;</a>&nbsp;&nbsp; ");
		print("<a href='/" . $this->get("CONTROLLER") . "/table/" . max(0, ($page - 1)) . "?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'>&lt;</a>&nbsp;&nbsp; ");
		print("<select name='page' onchange='document.location.href = this.value'>");
		$count = $dao->count();
		$nbrPages = floor($count / 10);
		for ($i = 0; $i < $nbrPages; $i++) {
			print("<option value='/" . $this->get("CONTROLLER") . "/table/$i?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'");
			if ($i == $page)
				print(" selected='selected'");
			print(">$i</option>");
		}
		print("</select>&nbsp;/&nbsp;$nbrPages");
		print("&nbsp;&nbsp;<a href='/" . $this->get("CONTROLLER") . "/table/" . min($nbrPages, ($page + 1)) . "?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'>&gt;</a> ");
		print("&nbsp;&nbsp;<a href='/" . $this->get("CONTROLLER") . "/table/$nbrPages?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'>&gt;&gt;</a> ");
		print("</td></tr>");
		print("</table>");
		$this->_printFooter();
		return (self::EXEC_QUIT);
	}

	/* ************ Méthodes privées ************* */
	/** Récupère la liste des bases accessibles. */
	private function _fetchDatabases() {
		$this->_listDb = array();
		$basesList = $this->_db->queryAll("SHOW DATABASES");
		foreach ($basesList as $line) {
			foreach ($line as $key => $base)
				$this->_listDb[] = $base;
		}
	}
	/** Récupère la liste des tables accessibles. */
	private function _fetchTables() {
		if (!$this->_currentDb)
			return;
		$this->_listTables = array();
		$tablesList = $this->_db->queryAll("SHOW TABLES IN " . $this->_currentDb);
		foreach ($tablesList as $line) {
			foreach ($line as $key => $table)
				$this->_listTables[] = $table;
		}
	}
	/**
	 * Affiche l'en-tête HTML de toutes les pages.
	 * @param	string	$title	Titre de la page.
	 */
	private function _printHeader($title) {
		print("<html><head><title>" . htmlspecialchars($title) . "</title></head>
			<body><div style='background-color: gray;'>
				<table><tr><td><button onclick=\"document.location.href='/" . $this->get("CONTROLLER") . "'\">Home</button></td>
				<td>
				<form id='form-bases' method='post' action='/" . $this->get("CONTROLLER") . "/database' style='display: block;'>
					<select name='db' onchange=\"document.getElementById('form-bases').submit()\">");
		foreach ($this->_listDb as $base)
			print("			<option value='$base'" . (($base == $this->_currentDb) ? " selected='selected'" : "") . ">$base</option>");
		print("			</select>
				</form></td><td>");
		if (!empty($this->_currentDb))
			print("<button onclick=\"document.location.href='/" . $this->get("CONTROLLER") . "/database?db=" . $this->_currentDb . "'\">&gt;</button></td><td>");
		if (!empty($this->_listTables)) {
			print("	<form id='form-tables' method='post' action='/" . $this->get("CONTROLLER") . "'/table>
					<input type='hidden' name='db' value='" . $this->_currentDb . "' />
					<select name='table' onchange=\"if (this.value) document.getElementById('form-tables').submit()\">
						<option value=''></option>");
			foreach ($this->_listTables as $table)
				print("		<option value='$table'" . (($table == $this->_currentTable) ? " selected='selected'" : "") . ">$table</option>");
			print("		</select>
				</form>");
		}
		print("	</td>");
		if (!empty($this->_currentTable))
			print("<td><button onclick=\"document.location.href='/" . $this->get("CONTROLLER") . "/table?db=" . $this->_currentDb . "&amp;table=" . $this->_currentTable . "'\">&gt;</button></td>");
		print("</tr></table></div>");
	}
	/** Affiche le pied de page. */
	private function _printFooter() {
		print("</body></html>");
	}
}

?>
