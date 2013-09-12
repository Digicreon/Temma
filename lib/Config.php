<?php

namespace Temma;

/**
 * Objet contenant la configuration d'une application Temma.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @version	$Id: Config.php 285 2012-09-27 13:11:22Z abouchard $
 */
class Config {
	/** Niveau de log par défaut. */
	const LOG_LEVEL = 'WARN';
	/** Nom du cookie stockant l'identifiant de session. */
	const SESSION_NAME = 'TemmaSession';
	/** Nom du répertoire contenant les fichiers de configuration. */
	const ETC_DIR = 'etc';
	/** Nom par défaut du répertoire contenant les fichiers de log. */
	const LOG_DIR = 'log';
	/** Nom du fichier de log par défaut. */
	const LOG_FILE = 'temma.log';
	/** Nom par défaut du répertoire contenant les fichiers à inclure. */
	const INCLUDES_DIR = 'lib';
	/** Nom par défaut du répertoire contenant les contrôleurs. */
	const CONTROLLERS_DIR = 'controllers';
	/** Nom par défaut du répertoire contenant les vues. */
	const VIEWS_DIR = 'views';
	/** Nom par défaut du répertoire contenant les templates. */
	const TEMPLATES_DIR = 'templates';
	/** Nom par défaut du répertoire temporaire. */
	const TEMP_DIR = 'tmp';
	/** Nom par défaut du répertoire web. */
	const WEB_DIR = 'www';
	/** Nom du contrôleur par défaut. */
	const DEFAULT_CONTROLLER = '\Temma\Controller';
	/** Préfix des configurations étendues. */
	const XTRA_CONFIG_PREFIX = 'x-';
	/** L'objet contrôleur généré par la configuration, à utiliser dans Temma comme "executor controller". */
	protected $_executorController = null;
	/** Liste des pages d'erreur. */
	protected $_errorPages = null;
	/** Liste des sources de données. */
	protected $_dataSources = null;
	/** Liste des routes. */
	protected $_routes = null;
	/** Liste des plugins. */
	protected $_plugins = null;
	/** Indique si les sessions doivent être chargées. */
	protected $_enableSessions = null;
	/** Nom du cookie stockant l'identifiant de session. */
	protected $_sessionName = null;
	/** Nom de la source de données contenant les sessions. */
	protected $_sessionSource = null;
	/** Chemin jusqu'à la racine de l'application. */
	protected $_appPath = null;
	/** Chemin jusqu'au répertoire de configuration. */
	protected $_etcPath = null;
	/** Chemin jusqu'au répertoire de log. */
	protected $_logPath = null;
	/** Chemin jusqu'au répertoire temporaire. */
	protected $_tmpPath = null;
	/** Chemin jusqu'au répertoire d'includes. */
	protected $_includesPath = null;
	/** Chemin jusqu'au répertoire des contrôleurs. */
	protected $_controllersPath = null;
	/** Chemin jsuqu'au répertoire des vues. */
	protected $_viewsPath = null;
	/** Chemin jusqu'au répertoire des templates. */
	protected $_templatesPath = null;
	/** Chemin jusqu'au répertoire d'index web. */
	protected $_webPath = null;
	/** Nom du contrôleur racine. */
	protected $_rootController = null;
	/** Nom du contrôleur par défaut. */
	protected $_defaultController = null;
	/** Nom du contrôleur proxy. */
	protected $_proxyController = null;
	/** Configuration importée automatiquement. */
	protected $_autoimport = null;
	/** Extra-configurations. */
	protected $_extraConfig = null;

	/**
	 * Constructeur.
	 * @param	string	$appPath		Chemin vers la racine de l'application.
	 * @param	string	$etcPath		Chemin vers le répertoire de configuration.
	 */
	public function __construct($appPath, $etcPath) {
		$this->_appPath = $appPath;
		$this->_etcPath = $etcPath;
	}
	/**
	 * Lecture du fichier de configuration Temma.json.
	 * @param	string	$path	Chemin vers le fichier de configuration.
	 * @throws	\Temma\Exceptions\FrameworkException	Si le fichier de configuration n'est pas valide.
	 */
	public function readConfigurationFile($path) {
		// lecture du fichier de configuration
		$ini = json_decode(file_get_contents($path), true);
		if (is_null($ini))
			throw new \Temma\Exceptions\FrameworkException("Unable to read configuration file '$path'.", \Temma\Exceptions\FrameworkException::CONFIG);
		// définition du fichier de log
		$logPath = $this->_appPath . '/' . self::LOG_DIR;
		\FineLog::setLogFile($logPath . '/' . self::LOG_FILE);
		// vérification des seuils de log
		if (isset($ini['loglevels']) && is_array($ini['loglevels'])) {
			$loglevels = array();
			foreach ($ini['loglevels'] as $class => $level)
				$loglevels[$class] = $level;
		} else
			$loglevels = self::LOG_LEVEL;
		\FineLog::setThreshold($loglevels);

		// récupération des pages d'erreur
		$this->_errorPages = (isset($ini['errorPages']) && is_array($ini['errorPages'])) ? $ini['errorPages'] : array();

		// récupération des sources de données
		if (isset($ini['application']['dataSources']) && is_array($ini['application']['dataSources']))
			$this->_dataSources = $ini['application']['dataSources'];
		else
			$this->_dataSources = array();

		// ajout des chemins d'inclusion supplémentaire
		$pathsToInclude = array();
		// chemin des bibliothèques du projet
		$includesPath = $this->_appPath . '/' . self::INCLUDES_DIR;
		if (is_dir($includesPath))
			$pathsToInclude[] = $includesPath;
		else
			$includesPath = null;
		// chemin des contrôleurs
		$controllersPath = $this->_appPath . '/' . self::CONTROLLERS_DIR;
		if (is_dir($controllersPath))
			$pathsToInclude[] = $controllersPath;
		else
			$controllersPath = null;
		// chemin des vues
		$viewsPath = $this->_appPath . '/' . self::VIEWS_DIR;
		if (is_dir($viewsPath))
			$pathsToInclude[] = $viewsPath;
		else
			$viewsPath = null;
		// chemins définis dans la configuration
		if (isset($ini['includePaths']) && is_array($ini['includePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['includePaths']);
		// ajout des chemins d'inclusion supplémentaires
		if (!empty($pathsToInclude))
			\FineAutoload::addIncludePath($pathsToInclude);

		// vérification du contrôleur par défaut
		if (!isset($ini['application']['defaultController']) || empty($ini['application']['defaultController']))
			$ini['application']['defaultController'] = self::DEFAULT_CONTROLLER;

		// vérification des routes
		if (isset($ini['routes']) && is_array($ini['routes']))
			$this->_routes = $ini['routes'];

		// vérification des déclarations de plugins
		$this->_plugins = (isset($ini['plugins']) && is_array($ini['plugins'])) ? $ini['plugins'] : array();

		// récupération de la configuration étendue
		$this->_extraConfig = array();
		foreach ($ini as $key => $value) {
			if (substr($key, 0, strlen(self::XTRA_CONFIG_PREFIX)) === self::XTRA_CONFIG_PREFIX)
				$this->_extraConfig[$key] = $value;
		}

		// vérification du besoin de charger les sessions
		$this->_enableSessions = true;
		if (isset($ini['application']['enableSessions']) && $ini['application']['enableSessions'] === false)
			$this->_enableSessions = false;
		// nom d'identifiant de session
		$this->_sessionName = (isset($ini['application']['sessionName']) && !empty($ini['application']['sessionName'])) ? $ini['application']['sessionName'] : self::SESSION_NAME;
		// source de données contenant les sessions
		$this->_sessionSource = (isset($ini['application']['sessionSource']) && !empty($ini['application']['sessionSource'])) ? $ini['application']['sessionSource'] : null;

		// définitions
		$this->_logPath = $logPath;
		$this->_tmpPath = $this->_appPath . '/' . self::TEMP_DIR;
		$this->_includesPath = $includesPath;
		$this->_controllersPath = $controllersPath;
		$this->_viewsPath = $viewsPath;
		$this->_templatesPath = $this->_appPath . '/' . self::TEMPLATES_DIR;
		$this->_webPath = $this->_appPath . '/' . self::WEB_DIR;
		$this->_rootController = isset($ini['application']['rootController']) ? $ini['application']['rootController'] : null;
		$this->_defaultController = isset($ini['application']['defaultController']) ? $ini['application']['defaultController'] : null;
		$this->_proxyController = isset($ini['application']['proxyController']) ? $ini['application']['proxyController'] : null;
		$this->_autoimport = isset($ini['autoimport']) ? $ini['autoimport'] : null;
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
	 * Indique si la variable demandée est définie.
	 * @param	string	$name	Nom de la propriété à vérifier.
	 * @return	bool	Indique si la variable est définie.
	 */
	public function __isset($name) {
		$name = '_' . $name;
		return (isset($this->$name));
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
