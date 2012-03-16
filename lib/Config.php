<?php

namespace Temma;

/**
 * Objet contenant la configuration d'une application Temma.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @version	$Id: Config.php 246 2011-07-01 16:09:49Z abouchard $
 */
class Config {
	/** Indique si les sessions ne doivent pas être chargées. */
	private $_disableSessions = null;
	/** Indique si la connexion au cache doit être faite. */
	private $_disableCache = null;
	/** Nom du cookie stockant l'identifiant de session. */
	private $_sessionName = null;
	/** DSN de connexion à la base de données. */
	private $_dsn = null;
	/** Chemin jusqu'à la racine de l'application. */
	private $_appPath = null;
	/** Chemin jusqu'au répertoire de configuration. */
	private $_etcPath = null;
	/** Chemin jusqu'au répertoire de log. */
	private $_logPath = null;
	/** Chemin jusqu'au répertoire temporaire. */
	private $_tmpPath = null;
	/** Chemin jusqu'au répertoire d'includes. */
	private $_includesPath = null;
	/** Chemin jusqu'au répertoire des contrôleurs. */
	private $_controllersPath = null;
	/** Chemin jsuqu'au répertoire des vues. */
	private $_viewsPath = null;
	/** Chemin jusqu'au répertoire des templates. */
	private $_templatesPath = null;
	/** Nom du contrôleur racine. */
	private $_rootController = null;
	/** Nom du contrôleur par défaut. */
	private $_defaultController = null;
	/** Nom du contrôleur proxy. */
	private $_proxyController = null;
	/** Configuration importée automatiquement. */
	private $_autoimport = null;
	/** Extra-configurations. */
	private $_extraConfig = null;

	/**
	 * Constructeur.
	 * @param	bool	$disableSessions	Indique si les sessions ne doivent pas être chargées.
	 * @param	bool	$disableCache		Indique si la connexion au cache doit être faite.
	 * @param	string	$sessionName		Nom du cookie de session.
	 * @param	string	$dsn			DSN de conexion à la base de données.
	 * @param	string	$appPath		Chemin vers la racine de l'application.
	 * @param	string	$etcPath		Chemin vers le répertoire de configuration.
	 * @param	string	$logPath		Chemin vers le répertoire de log.
	 * @param	string	$tmpPath		Chemin vers le répertoire temporaire.
	 * @param	string	$includesPath		Chemin vers le répertoire de bibliothèques.
	 * @param	string	$controllersPath	Chemin vers le répertoire des contrôleurs.
	 * @param	string	$viewsPath		Chemin vers le répertoire des vues.
	 * @param	string	$templatesPath		Chemin vers le répertoire des templates.
	 * @param	string	$rootController		Nom du contrôleur racine.
	 * @param	string	$defaultController	Nom du contrôleur par défaut.
	 * @param	string	$proxyController	Nom du contrôleur proxy.
	 * @param	array	$autoimport		Hash de données à importer automatiquement.
	 * @param	array	$extraConfig		Hash contenant la configuration étendue.
	 */
	public function __construct($disableSessions, $disableCache, $sessionName, $dsn, $appPath, $etcPath, $logPath, $tmpPath,
				    $includesPath, $controllersPath, $viewsPath, $templatesPath, $rootController, $defaultController,
				    $proxyController, $autoimport, $extraConfig) {
		$this->_disableSessions = ($disableSessions === true) ? true : false;
		$this->_disableCache = ($disableCache === true) ? true : false;
		$this->_sessionName = $sessionName;
		$this->_dsn = $dsn;
		$this->_appPath = $appPath;
		$this->_etcPath = $etcPath;
		$this->_logPath = $logPath;
		$this->_tmpPath = $tmpPath;
		$this->_includesPath = $includesPath;
		$this->_controllersPath = $controllersPath;
		$this->_viewsPath = $viewsPath;
		$this->_templatesPath = $templatesPath;
		$this->_rootController = $rootController;
		$this->_defaultController = $defaultController;
		$this->_proxyController = $proxyController;
		$this->_autoimport = $autoimport;
		$this->_extraConfig = $extraConfig;
	}
	/**
	 * Getter. Retourne directement n'importe quelle valeur de configuration demandée.
	 * @param	string	$name	Nom de la propriété à retourner.
	 * @return	mixed	La valeur de la propriété demandée.
	 */
	public function __get($name) {
		$name = '_' . $name;
		return ($this->$name);
	}
	/**
	 * Getter pour la configuration étendue.
	 * @param	string	$name		Nom de la configuration étendue (sans le préfixe "x-").
	 * @param	string	$key		(optionnel) Clé de l'élément de configuration à retourner.
	 * @param	string	$default	(optionnel) Valeur par défaut à retourner si l'élément n'existe pas.
	 *					N'est pas utilisé si le paramètre $key est vide.
	 * @return	mixed	L'élément demandé (si précisé) ou toute la configuration étendue demandée.
	 */
	public function xtra($name, $key=null, $default=null) {
		if (is_null($key)) {
			if (isset($this->_extraConfig["x-$name"]))
				return ($this->_extraConfig["x-$name"]);
			return (null);
		}
		$result = null;
		if (isset($this->_extraConfig["x-$name"][$key]))
			$result = $this->_extraConfig["x-$name"][$key];
		return ((!is_null($default) && is_null($result)) ? $default : $result);
	}
	/**
	 * Setter pour la configuration étendue.
	 * @param	string	$name	Nom de la configuration étendue (sans le préfixe "x-").
	 * @param	string	$key	Clé de l'élément de configuration à ajouter.
	 * @param	string	$value	Valeur de l'élément.
	 */
	public function setXtra($name, $key, $value) {
		if (!isset($this->_extraConfig["x-$name"]))
			$this->_extraConfig["x-$name"] = array();
		$this->_extraConfig["x-$name"][$key] = $value;
	}
}

?>
