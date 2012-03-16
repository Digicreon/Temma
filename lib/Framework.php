<?php

namespace Temma;

require_once('finebase/FineLog.php');
require_once('finebase/FineDatabase.php');
require_once('finebase/FineSession.php');
require_once('finebase/FineCache.php');
require_once('Temma/Config.php');
require_once('Temma/Request.php');
require_once('Temma/Controller.php');
require_once('Temma/Response.php');

/**
 * Objet de gestion des applications du framework Temma (TEMplate MAnager).
 *
 * Cet objet est au coeur du framework Temma. Il sert à définir une "application",
 * c'est-à-dire un ensemble de "contrôleurs", qui eux-même définissent des actions.
 *
 * Par exemple, imaginons un site web "toto.com". Sous l'URL 'www.toto.com' sont
 * disponibles 2 sous-répertoires 'forum' et 'documents'. L'arborescence 'forum'
 * contient une application PHPBB complète et autonome. Par contre, l'arborescence
 * 'documents' définit 2 contrôleurs :
 * - 'page', avec l'action 'read'
 * - 'article', avec les actions 'read' et 'modify'
 * Il devient ainsi possible d'appeler l'URL "http://www.toto.com/documents/article/read/127",
 * ce qui affiche l'article numéro 127. Pour afficher la cinquième page de cet article,
 * on pourrait aller sur l'URL "http://www.toto.com/documents/page/127/5".
 *
 * L'objet \Temma\Framework contient tout le code qui enregistre les contrôleurs
 * disponibles, qui reçoit toutes les requêtes sous l'URL "www.toto.com/documents" et
 * les interprête pour appeler la bonne méthode du bon contrôleur, puis lance le
 * moteur de template qui va afficher la "vue".
 *
 * <b>Arborescence d'application</b>
 *
 * <pre>
 * toto/
 *      log/				> fichiers de log
 *      tmp/				> fichiers temporaires
 *      etc/				> fichiers de configuration
 *		temma.ini		> configuration de l'application
 *      lib/				> objets métier
 *		Obj1BO.php
 *		Obj2BO.php
 *      controllers/			> contrôleurs
 *		Controller1.php
 *		Controller2.php
 *      views/				> vues
 *		XmlView.php
 *      templates/			> templates
 *		error.thml
 *		controller1/
 *			index.tpl
 *			action1.tpl
 *			action2.tpl
 *		controller2/
 *			index.tpl
 *			action3.tpl
 *			action4.tpl
 * </pre>
 *
 * <b>Exemple de configuration</b>
 *
 * De manière générale, il est recommandé d'utiliser les mécanismes par défaut
 * du framework Temma, pour limiter la complexité des fichiers de configuration.
 * Voici un exemple minimal mais pleinement fonctionnel :
 * <code>
 * {
 *     application: {
 *	 dsn: "mysqli://user:passwd@localhost/toto",
 *	 defaultController: "HomepageController"
 *     },
 *     loglevels: {
 *	 toto: "DEBUG"
 *     }
 * }
 * </code>
 *
 * <b>Fichier de configuration</b>
 *
 * Le répertoire 'etc' de l'application doit contenir un fichier "temma.json", qui contient
 * les informations suivantes :
 * <code>
 * {
 *     // variables de définition générale de l'application
 *     application: {
 *	 // DSN de connexion à la base de données (cf. objet FineDatabase).
 *	 // Optionnel mais recommandé : existe-t-il des applications Web sans base de données ?
 *	 dsn: "mysqli://user:passwd@localhost/toto",
 *
 *	 // Nom du cookie de session.
 *	 // Optionnel : "TemmaSession" par défaut.
 *	 sessionName: "TemmaSession",
 *
 *	 // Indique qu'on souhaite ne pas charger de sessions.
 *	 // Optionnel : À utiliser en étant certain de vouloir faire cela.
 *	 disableSessions: true,
 *
 *	 // Indique qu'on souhaite ne pas se connecter au cache.
 *	 // Optionnel : À utiliser en étant certain de vouloir faire cela.
 *	 disableCache: true,
 *
 *	 // Nom du contrôleur par défaut à utiliser pour la racine du site.
 *	 // Optionnel. S'il n'est pas défini, le defaultController est utilisé.
 *	 rootController: "TemmaController",
 *
 *	 // Nom du contrôleur par défaut à utiliser si le contrôleur demandé est inexistant.
 *	 // Optionnel mais recommandé : Utilise "TemmaController" par défaut, qui génère une erreur HTTP 404.
 *	 defaultController: "TemmaController",
 *
 *	 // Nom du contrôleur à appeler systématiquement, même si celui demandé existe.
 *	 // Optionnel.
 *	 proxyController: "MainController",
 *     },
 *     // Définition des seuils de log à utiliser en fonction des classes
 *     // de log correspondantes (cf. objet FineLog).
 *     // OBLIGATOIRE : Les classes non définies ne sont pas logguées.
 *     loglevels: {
 *	 finebase: "ERROR",
 *	 temma: "WARN",
 *	 toto: "DEBUG"
 *     },
 *     // Définition des pages d'erreurs
 *     errorPages: {
 *	 404: "path/to/page.html",
 *	 500: "path/to/page.html"
 *     },
 *     // Chemins d'inclusion à ajouter
 *     includePaths: [
 *	 "/opt/finemedia/finecommon/lib",
 *	 "/opt/finemedia/finebase/lib"
 *     ],
 *     // On indique des noms de contrôleurs "virtuels", en y associant un contrôleur
 *     // réel (ou virtuel, en cascade), qui prendra en charge les requêtes.
 *     routes: {
 *	 "cornofulgure": "GoldorakController",
 *	 "fulguropoing": "GoldorakController",
 *	 "fulg": "GoldorakController"
 *     },
 *     // Gestion des plugins
 *     plugins: {
 *	 // Plugins exécutés pour tous les contrôleurs
 *	 // - plugins appelés avant l'exécution du contrôleur
 *	 _pre: [
 *	     "CheckRequestPlugin",
 *	     "UserGrantPlugin"
 *	 ],
 *	 // - plugins appelés après l'exécution du contrôleur
 *	 _post: [ "AddCrossLinksPlugin" ],
 *	 // Définition de plugins pour le contrôleur BobController
 *	 BobControler: {
 *	     _pre: [ "SomethingPlugin" ],
 *	     _post: [ "SomethingElsePlugin" ],
 *	     // Définition de plugins spécifiques en fonction des actions du contrôleur
 *	     index: {
 *		 _pre: [ "AaaPlugin" ],
 *		 _post: [ "BbbPlugin" ]
 *	     },
 *	     setData: {
 *		 _pre: [ "CccPlugin" ]
 *	     }
 *	 }
 *     },
 *     // variables importées automatiquement
 *     autoimport: {
 *	 "googleId": "azeazeaez",
 *	 "googleAnalyticsId": "azeazeazeaze"
 *     }
 * }
 * </code>
 *
 * <b>Contrôleurs</b>
 *
 * Temma fourni un contrôleur basique (TemmaController) qui se contente de passer à la vue
 * les différentes informations qu'il reçoit :
 * - nom de l'action appelée
 * - paramètres d'action
 * - paramètres POST
 *
 * Pour qu'un contrôleur soit reconnu, il faut qu'il soit contenu dans un fichier nommé
 * suivant une norme stricte de la forme "XxxxController.php" (cf. documentation
 * de \Temma\Controller). Par exemple, l'URL "http://www.toto.com/article/..." correspondra :
 * - à l'objet nommé 'ArticleController',
 * - qui se trouvera dans le fichier "ArticleController.php".
 *
 * <b>Vues</b>
 *
 * Temma fourni 2 types de "vues" (au sens MVC du terme) :
 * - \Temma\SmartyView : Templates Smarty, pour générer des fichiers X(HT)ML.
 * - \Temma\JsonView : Sérialisation JSON, pour créer des webservices.
 *
 * La plupart du temps, vous n'aurez besoin que de ces vues, et il est recommandé
 * de les utiliser le plus souvent possible. Dans le cas où ces vues ne remplissent
 * pas le besoin, il est possible de créer d'autre type de vues avec des objets
 * dérivant de TemmaView (Cf. documentation de TemmaView).
 *
 * <b>Templates</b>
 *
 * Lorsqu'une action est exécutée, elle peut indiquer le template à utiliser. Si
 * elle ne le fait pas, \Temma\Framework va chercher un template portant le nom de
 * l'action (avec l'extension ".tpl"), dans un répertoire portant le nom du contrôleur,
 * lui-même présent dans templatesPath.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2010, Fine Media
 * @package	Temma
 * @version	$Id: Framework.php 258 2012-01-05 15:19:54Z abouchard $
 * @see		\Temma\Controller
 * @see		\Temma\View
 */
class Framework {
	/** Nom du fichier local de configuration de l'application. */
	const CONFIG_FILE_NAME = 'temma.json';
	/** Nom du cookie stockant l'identifiant de session. */
	const SESSION_NAME = 'TemmaSession';
	/** Niveau de log par défaut. */
	const LOG_LEVEL = 'WARN';
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
	/** Nom de l'action par défaut. */
	const DEFAULT_ACTION = 'index';
	/** Nom de l'action de proxy. */
	const PROXY_ACTION = 'proxy';
	/** Préfixe des noms de méthodes liées aux actions. */
	const ACTION_PREFIX = 'exec';
	/** Nom de la vue par défaut. */
	const DEFAULT_VIEW = '\Temma\Views\SmartyView';
	/** Extension par défaut des fichiers de template. */
	const TEMPLATE_EXTENSION = '.tpl';
	/** Préfix des configurations étendues. */
	const XTRA_CONFIG_PREFIX = 'x-';
	/** Nombre de récursions maximum pour la recherche de route. */
	const ROUTE_MAX_DEPTH = 4;
	/** Suffixe des noms de fichier des contrôleurs. */
	const CONTROLLERS_SUFFIX = 'Controller';
	/** Suffix des noms de fichier des plugins. */
	const PLUGINS_SUFFIX = 'Plugin';
	/** Nom de la variable de template contenant les données autoimportées. */
	const AUTOIMPORT_VARIABLE = 'conf';
	/** Objet de chronométrage du temps d'exécution. */
	private $_timer = null;
	/** Configuration de l'application. */
	private $_config = null;
	/** Objet de connexion à la base de données. */
	private $_db = null;
	/** Objet de gestion de la session. */
	private $_session = null;
	/** Objet de gestion du cache. */
	private $_cache = null;
	/** Objet de requête. */
	private $_request = null;
	/** Table de routage. */
	private $_routes = null;
	/** Configuration des plugins. */
	private $_plugins = null;
	/** Liste des fichiers à pré-charger. */
	private $_preloadFiles = null;
	/** Liste des URLs des pages d'erreur. */
	private $_errorPages = null;
	/** Objet contrôleur "neutre". */
	private $_executorController = null;
	/** Nom du contrôleur demandé initialement. */
	private $_initControllerName = null;
	/** Nom du contrôleur exécuté. */
	private $_controllerName = null;
	/** Nom de l'objet correspondant au contrôleur. */
	private $_objectControllerName = null;
	/** Nom de l'action exécutée. */
	private $_actionName = null;
	/** Nom de la méthode correspondant à l'action. */
	private $_methodActionName = null;
	/** Indique si on utilise une action proxy. */
	private $_isProxyAction = false;
	/** Objet de réflexion sur le contrôleur, pour vérifications. */
	private $_controllerReflection = null;

	/**
	 * Constructeur.
	 * Initialise l'application.
	 * @param	FineTimer	$timer	Objet de chronométrage du temps d'exécution.
	 */
	public function __construct(\FineTimer $timer) {
		// initialisations
		$this->_timer = $timer;
		$this->_controllers = array();
		$this->_views = array();
		// chargement de la configuration, initialisation du système de log
		$this->_loadConfig();
		// préchargement de fichiers
		foreach ($this->_preloadFiles as $preloadFile)
			require_once($preloadFile);
		// connexion à la base de données
		if (strlen($this->_config->dsn) > 0)
			$this->_db = \FineDatabase::factory($this->_config->dsn);
		// connexion au cache
		if (!$this->_config->disableCache)
			$this->_cache = \FineCache::singleton();
		// récupération de la session
		if (!$this->_config->disableSessions)
			$this->_session = \FineSession::singleton($this->_cache, $this->_config->sessionName);
	}
	/** Destructeur. */
	public function __destruct() {
	}
	/** Lance l'exécution du contrôleur. */
	public function process() {
		/* *************** INITIALISATIONS ********** */
		// extraction des paramètres de la requête
		$this->_request = new \Temma\Request();
		// création du contrôleur exécuteur
		$this->_executorController = new \Temma\Controller($this->_db, $this->_session, $this->_cache, $this->_config, $this->_request);
		// initialisation des variables
		$this->_executorController->set('URL', $_SERVER['REQUEST_URI']);
		$this->_executorController->set('CONTROLLER', $this->_request->getController());
		$this->_executorController->set('ACTION', $this->_request->getAction());
		// import des variables "autoimport" définies dans le fichier de configuration
		$this->_executorController->set(self::AUTOIMPORT_VARIABLE, $this->_config->autoimport);

		/* *********** NOMS CONTROLEUR/ACTION ********* */
		// calcul du nom du contrôleur
		$this->_setControllerName();
		// calcul du nom de l'action
		$this->_setActionName();

		/* *************** PRE-PLUGINS ********** */
		$execStatus = \Temma\Controller::EXEC_FORWARD;
		\FineLog::log('temma', \FineLog::DEBUG, "Processing of pre-process plugins.");
		// génération de la liste des plugins à exécuter
		$prePlugins = $this->_generatePrePluginsList();
		// traitement des plugins PRE-process
		while (($pluginName = current($prePlugins)) !== false) {
			next($prePlugins);
			if (empty($pluginName))
				continue;
			if (($execStatus = $this->_execPlugin($pluginName, 'preplugin')) !== \Temma\Controller::EXEC_FORWARD)
				break;
			// on regénère la liste des plugins si le contrôleur déclaré a été modifié par le plugin
			if ($this->_request->getController() != $this->_initControllerName) {
				$this->_setControllerName();
				$this->_setActionName();
				$prePlugins = $this->_generatePrePluginsList();
				reset($prePlugins);
			}
		}

		/* ************** CONTROLEUR ************* */
		if ($this->_controllerReflection->getName() == 'Temma\Controller')
			throw new \Temma\Exceptions\HttpException("The requested page doesn't exists.", 404);
		if ($execStatus != \Temma\Controller::EXEC_HALT && $execStatus != \Temma\Controller::EXEC_QUIT) {
			// on vérifie la méthode qui va être exécutée
			$this->_checkActionMethod();
			// on exécute le contrôleur
			\FineLog::log('temma', \FineLog::DEBUG, "Controller processing.");
			$execStatus = $this->_executorController->subProcess($this->_objectControllerName, $this->_actionName);
		}

		/* ************* POST-PLUGINS ************** */
		if ($execStatus !== \Temma\Controller::EXEC_HALT && $execStatus !== \Temma\Controller::EXEC_QUIT) {
			\FineLog::log('temma', \FineLog::DEBUG, "Processing of post-process plugins.");
			// génération de la liste des plugins à exécuter
			$postPlugins = $this->_generatePostPluginsList();
			// traitement des plugins POST-process
			while (($pluginName = current($postPlugins)) !== false) {
				next($postPlugins);
				if (empty($pluginName))
					continue;
				if (($execStatus = $this->_execPlugin($pluginName, 'postplugin')) !== \Temma\Controller::EXEC_FORWARD)
					break;
				// on regénère la liste des plugins si le contrôleur déclaré a été modifié par le plugin
				if ($this->_request->getController() != $this->_initControllerName) {
					$this->_setControllerName();
					$this->_setActionName();
					$prePlugins = $this->_generatePrePluginsList();
					reset($prePlugins);
				}
			}
		}

		/* ******************* REPONSE ****************** */
		// arrêt des traitement si demandé
		if ($execStatus == \Temma\Controller::EXEC_QUIT) {
			\FineLog::log('temma', \FineLog::DEBUG, "Premature but wanted end of processing.");
			return;
		}
		// récupération de la réponse
		$response = $this->_executorController->getResponse();
		// gestion des erreurs HTTP
		$httpError = $response->getHttpError();
		if (isset($httpError)) {
			\FineLog::log('temma', \FineLog::WARN, "HTTP error '$httpError': " . $this->_request->getController()  . "/" . $this->_request->getAction());
			throw new \Temma\Exceptions\HttpException("HTTP error.", $httpError);
		}
		// redirection si nécessaire
		$url = $response->getRedirection();
		if (!empty($url)) {
			\FineLog::log('temma', \FineLog::DEBUG, "Redirecting to '$url'.");
			if ($response->getRedirectionCode() == 301)
				header('HTTP/1.1 301 Moved Permanently');
			header("Location: $url");
			exit();
		}
		// fin du chronométrage
		$this->_timer->stop();
		$this->_executorController->set('TIMER', $this->_timer->getTime());
		// récupération et initialisation de la vue
		$view = $this->_loadView($response->getView());
		$this->_initView($view, $response);
		// envoit des headers
		\FineLog::log('temma', \FineLog::DEBUG, "Writing of response headers.");
		$view->sendHeaders();
		// envoit du corps
		\FineLog::log('temma', \FineLog::DEBUG, "Writing of response body.");
		$view->sendBody();
	}
	/**
	 * Retourne le chemin vers la page correspondant à un code d'erreur HTTP.
	 * @param	int	$code	Le code d'erreur HTTP.
	 * @return	string	Le chemin complet vers la page, ou NULL s'il n'est pas défini.
	 */
	public function getErrorPage($code) {
		if (isset($this->_errorPages[$code]) && !empty($this->_errorPages[$code]))
			return ($this->_config->appPath . '/' . self::WEB_DIR . '/' . $this->_errorPages[$code]);
		if (isset($this->_errorPages['default']) && !empty($this->_errorPages['default']))
			return ($this->_config->appPath . '/' . self::WEB_DIR . '/' . $this->_errorPages['default']);
		return (null);
	}

	/* ******************* METHODES PRIVEES ************** */
	/**
	 * Chargement du fichier de configuration.
	 * @throws	\Temma\Exceptions\FrameworkException	Si le fichier de configuration est illisible.
	 */
	private function _loadConfig() {
		// récupération du chemin vers l'application dans les variables d'environnement
		$appPath = realpath(dirname($_SERVER['SCRIPT_FILENAME']) . '/..');
		if (empty($appPath) || !is_dir($appPath))
			throw new \Temma\Exceptions\FrameworkException("Unable to find application's root path.", \Temma\Exceptions\FrameworkException::CONFIG);
		$etcPath = $appPath . '/' . self::ETC_DIR;
		// lecture du fichier de configuration
		$configFileName = self::CONFIG_FILE_NAME;
		$configFile = $etcPath . '/' . $configFileName;
		if (!is_readable($configFile))
			throw new \Temma\Exceptions\FrameworkException("Unable to read configuration file '$configFile'.", \Temma\Exceptions\FrameworkException::CONFIG);
		$ini = json_decode(file_get_contents($configFile), true);
		if (is_null($ini))
			throw new \Temma\Exceptions\FrameworkException("No config file.", \Temma\Exceptions\FrameworkException::CONFIG);

		// définition du fichier de log
		$logPath = $appPath . '/' . self::LOG_DIR;
		$logFile = self::LOG_FILE;
		\FineLog::setLogFile($logPath . '/' . $logFile);
		// vérification des seuils de log
		if (isset($ini['loglevels']) && is_array($ini['loglevels'])) {
			$loglevels = array();
			foreach ($ini['loglevels'] as $class => $level) {
				$threshold = eval("return (FineLog::$level);");
				$loglevels[$class] = $threshold;
			}
			\FineLog::setThreshold($loglevels);
		}

		// récupération des pages d'erreur
		$this->_errorPages = (isset($ini['errorPages']) && is_array($ini['errorPages'])) ? $ini['errorPages'] : array();

		// vérification du besoin de charger les session
		$disableSessions = false;
		if (isset($ini['application']['disableSessions']) && $ini['application']['disableSessions'] === true)
			$disableSessions = true;
		// vérification du besoin de se connecter au cache
		$disableCache = false;
		if (isset($ini['application']['disableCache']) && $ini['application']['disableCache'] === true)
			$disableCache = true;
		// vérification du nom d'identifiant de session
		if (!isset($ini['application']['sessionName']) || empty($ini['application']['sessionName']))
			$ini['application']['sessionName'] = self::SESSION_NAME;

		// ajout des chemins d'inclusion supplémentaire
		$pathsToInclude = array();
		// chemin des bibliothèques du projet
		$includesPath = $appPath . '/' . self::INCLUDES_DIR;
		if (is_dir($includesPath))
			$pathsToInclude[] = $includesPath;
		else
			$includesPath = null;
		// chemin des contrôleurs
		$controllersPath = $appPath . '/' . self::CONTROLLERS_DIR;
		if (is_dir($controllersPath))
			$pathsToInclude[] = $controllersPath;
		else
			$controllersPath = null;
		// chemin des vues
		$viewsPath = $appPath . '/' . self::VIEWS_DIR;
		if (is_dir($viewsPath))
			$pathsToInclude[] = $viewsPath;
		else
			$viewsPath = null;
		// chemins définis dans la configuration
		if (isset($ini['includePaths']) && is_array($ini['includePaths']))
			$pathsToInclude = array_merge($pathsToInclude, $ini['includePaths']);
		// ajout des chemins d'inclusion supplémentaires
		if (!empty($pathsToInclude))
			set_include_path(get_include_path() . PATH_SEPARATOR . implode(PATH_SEPARATOR, $pathsToInclude));

		// vérification du contrôleur par défaut
		if (!isset($ini['application']['defaultController']) || empty($ini['application']['defaultController']))
			$ini['application']['defaultController'] = self::DEFAULT_CONTROLLER;

		// vérification des routes
		if (isset($ini['routes']) && is_array($ini['routes']))
			$this->_routes = $ini['routes'];

		// vérification des déclarations de plugins
		$this->_plugins = (isset($ini['plugins']) && is_array($ini['plugins'])) ? $ini['plugins'] : array();

		// liste des fichiers à précharger
		$this->_preloadFiles = (isset($ini['preload']) && is_array($ini['preload'])) ? $ini['preload'] : array();

		// récupération de la configuration étendue
		$extraConfig = array();
		foreach ($ini as $key => $value) {
			if (substr($key, 0, strlen(self::XTRA_CONFIG_PREFIX)) == self::XTRA_CONFIG_PREFIX)
				$extraConfig[$key] = $value;
		}

		// création de l'objet de config
		$this->_config = new \Temma\Config($disableSessions,
						   $disableCache,
						   (isset($ini['application']['sessionName']) ? $ini['application']['sessionName'] : null),
						   (isset($ini['application']['dsn']) ? $ini['application']['dsn'] : null),
						   $appPath,
						   $etcPath,
						   $logPath,
						   $appPath . '/' . self::TEMP_DIR,
						   $includesPath,
						   $controllersPath,
						   $viewsPath,
						   $appPath . '/' . self::TEMPLATES_DIR,
						   (isset($ini['application']['rootController']) ? $ini['application']['rootController'] : null),
						   (isset($ini['application']['defaultController']) ? $ini['application']['defaultController'] : null),
						   (isset($ini['application']['proxyController']) ? $ini['application']['proxyController'] : null),
						   (isset($ini['autoimport']) ? $ini['autoimport'] : null),
						   $extraConfig);
	}

	/* ********************* CHARGEMENT DES CONTROLEURS/PLUGINS ********************* */
	/**
	 * Définit le nom du contrôleur qui sera chargé.
	 * @throws	\Temma\Exceptions\HttpException	Si le contrôleur n'existe pas.
	 */
	private function _setControllerName() {
		$this->_initControllerName = $this->_controllerName = $this->_request->getController();
		if (($proxyName = $this->_config->proxyController)) {
			// un contrôleur proxy a été défini
			$this->_objectControllerName = $proxyName;
		} else if (($this->_controllerName = $this->_request->getController())) {
			// vérification que la première lettre est en minuscule
			if ($this->_controllerName != lcfirst($this->_controllerName))
				throw new \Temma\Exceptions\HttpException("Bad name for controller '" . $this->_controllerName . "'.", 404);
			// on regarde si le contrôleur demandé est en fait un contrôleur virtuel
			for ($nbrLoops = 0, $routeName = $this->_controllerName, $routed = false;
			     $nbrLoops < self::ROUTE_MAX_DEPTH && is_array($this->_routes) && array_key_exists($routeName, $this->_routes);
			     $nbrLoops++) {
				$realName = $this->_routes[$routeName];
				\FineLog::log('temma', \FineLog::INFO, "Routing '$routeName' to '$realName'.");
				unset($this->_routes[$routeName]);
				$routeName = $realName;
				$routed = true;
			}
			// on prend le contrôleur demandé
			if ($routed && substr($routeName, -strlen(self::CONTROLLERS_SUFFIX)) == self::CONTROLLERS_SUFFIX)
				$this->_objectControllerName = $routeName;
			else
				$this->_objectControllerName = ucfirst($this->_controllerName) . self::CONTROLLERS_SUFFIX;
		} else {
			// pas de contrôleur demandé, on prend le contrôleur racine
			$this->_objectControllerName = $this->_config->rootController;
		}
		if (empty($this->_objectControllerName) || !class_exists($this->_objectControllerName)) {
			\FineLog::log('temma', \FineLog::INFO, "Controller '" . $this->_objectControllerName . "' doesn't exists.");
			// pas de contrôleur demandé, on prend le contrôleur par défaut
			$this->_objectControllerName = $this->_config->defaultController;
		}
		// vérification de l'orthographe du contrôleur
		$this->_controllerReflection = new \ReflectionClass($this->_objectControllerName);
		if ($this->_controllerReflection->getName() !== trim($this->_objectControllerName, '\ '))
			throw new \Temma\Exceptions\HttpException("Bad name for controller '" . $this->_controllerName . "'.", 404);
	}
	/**
	 * Génère la liste des plugins "pré".
	 * @return	array	Liste de noms.
	 */
	private function _generatePrePluginsList() {
		$prePlugins = isset($this->_plugins['_pre']) ? $this->_plugins['_pre'] : array();
		if (isset($this->_plugins[$this->_objectControllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $this->_plugins[$this->_objectControllerName]['_pre']);
		if (isset($this->_plugins[$this->_objectControllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $this->_plugins[$this->_objectControllerName][$this->_actionName]['_pre']);
		if (isset($this->_plugins[$this->_controllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $this->_plugins[$this->_controllerName]['_pre']);
		if (isset($this->_plugins[$this->_controllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $this->_plugins[$this->_controllerName][$this->_actionName]['_pre']);
		\FineLog::log('temma', \FineLog::DEBUG, "Pre plugins: " . print_r($prePlugins, true));
		return ($prePlugins);
	}
	/**
	 * Génère la liste des plugins "post".
	 * @return	array	Liste de noms.
	 */
	private function _generatePostPluginsList() {
		$postPlugins = isset($this->_plugins['_post']) ? $this->_plugins['_post'] : array();
		if (isset($this->_plugins[$this->_objectControllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $this->_plugins[$this->_objectControllerName]['_post']);
		if (isset($this->_plugins[$this->_objectControllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $this->_plugins[$this->_objectControllerName][$this->_actionName]['_post']);
		if (isset($this->_plugins[$this->_controllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $this->_plugins[$this->_controllerName]['_post']);
		if (isset($this->_plugins[$this->_controllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $this->_plugins[$this->_controllerName][$this->_actionName]['_post']);
		\FineLog::log('temma', \FineLog::DEBUG, "Post plugins: " . print_r($postPlugins, true));
		return ($postPlugins);
	}
	/**
	 * Exécute un plugin.
	 * @param	string	$pluginName	Nom de l'objet de plugin.
	 * @param	string	$methodName	Nom de la méthode à exécuter.
	 * @return	int	Le statut d'exécution du plugin.
	 * @throws	\Temma\Exceptions\HttpException	Si le plugin n'existe pas.
	 */
	private function _execPlugin($pluginName, $methodName) {
		\FineLog::log('temma', \FineLog::INFO, "Executing plugin '$pluginName'.");
		try {
			// vérification de l'existence du plugin
			if (class_exists($pluginName) && is_subclass_of($pluginName, '\Temma\Controller')) {
				$plugin = new $pluginName($this->_db, $this->_session, $this->_cache, $this->_config, $this->_request, $this->_executorController);
				$methodName = method_exists($plugin, $methodName) ? $methodName : 'plugin';
				return ($plugin->$methodName());
			}
		} catch (Exception $e) { }
		\FineLog::log('temma', \FineLog::DEBUG, "Unable to execute plugin '$pluginName'::'$methodName'.");
		throw new \Temma\Exceptions\HttpException("Unable to execute plugin '$pluginName'::'$methodName'.", 500);
	}

	/* ********************************** ACTION ********************************* */
	/**
	 * Calcule l'action à exécuter.
	 * @throws	\Temma\Exceptions\HttpException	Si l'action demandée n'est pas écrite correctement.
	 */
	private function _setActionName() {
		// récupération du nom d'action demandé
		$this->_actionName = $this->_request->getAction();
		// on regarde si le contrôleur a une action de proxy
		$methodName = self::ACTION_PREFIX . ucfirst(self::PROXY_ACTION);
		if (method_exists($this->_objectControllerName, $methodName)) {
			\FineLog::log('temma', \FineLog::INFO, "Executing proxy action.");
			$this->_isProxyAction = true;
			$this->_methodActionName = $methodName;
			return;
		}
		$this->_isProxyAction = false;
		// pas de proxy ; on regarde si une action est demandée et si elle est écrite correctement
		if (empty($this->_actionName))
			$this->_actionName = self::DEFAULT_ACTION;
		else if ($this->_actionName !== lcfirst($this->_actionName))
			throw new \Temma\Exceptions\HttpException("Bad name for action '" . $this->_actionName . "'.", 404);
		// constitution du nom de la méthode à exécuter
		$this->_methodActionName = self::ACTION_PREFIX . ucfirst($this->_actionName);
	}
	/**
	 * Vérifie si la méthode correspondant à l'action demandée existe.
	 * @throws	\Temma\Exceptions\HttpException	Si l'action demandée n'existe pas.
	 */
	private function _checkActionMethod() {
		\FineLog::log('temma', \FineLog::DEBUG, "actionName : '" . $this->_actionName . "' - methodActionName : '" . $this->_methodActionName . "'.");
		try {
			try {
				$nbrParams = $this->_request->getNbrParams();
				if (($actionReflection = $this->_controllerReflection->getMethod($this->_methodActionName)) &&
				    ($this->_isProxyAction || ($actionReflection->getNumberOfRequiredParameters() <= $nbrParams &&
							       $actionReflection->getNumberOfParameters() >= $nbrParams)) &&
				    $actionReflection->name == $this->_methodActionName) {
					\FineLog::log('temma', \FineLog::DEBUG, "Action method '" . $this->_methodActionName . "' was checked.");
					return;
				}
			} catch (\ReflectionException $re) {
				// l'action demandée n'existe pas, on cherche une action par défaut
				if ($actionReflection = $this->_controllerReflection->getMethod('__call')) {
					\FineLog::log('temma', \FineLog::DEBUG, "Action method '" . $this->_methodActionName . "' was checked through default action.");
					return;
				}
			}
		} catch (\ReflectionException $re) { }
		// mauvais appel de l'action, ou l'action par défaut n'existe pas => erreur
		\FineLog::log('temma', \FineLog::ERROR, "Can't find method '" . $this->_methodActionName . "' on controller '" . $this->_objectControllerName . "'.");
		throw new \Temma\Exceptions\HttpException("Can't find method '" . $this->_methodActionName . "' on controller '" . $this->_objectControllerName . ".", 404);
	}

	/* ******************************** VUE ************************************ */
	/**
	 * Charge une vue.
	 * @param	string	$name	Nom de la vue à charger.
	 * @return	\Temma\View	Instance de la vue demandée.
	 * @throws	\Temma\Exceptions\FrameworkException	Si aucune ne peut être chargée.
	 */
	private function _loadView($name) {
		\FineLog::log('temma', \FineLog::INFO, "Loading view '$name'.");
		// si la vue n'est pas définie, on utilise la vue par défaut
		if (empty($name)) {
			\FineLog::log('temma', \FineLog::DEBUG, "Using default view.");
			$name = self::DEFAULT_VIEW;
		}
		// chargement de la vue
		if (class_exists($name) && is_subclass_of($name, '\Temma\View'))
			return (new $name($this->_config));
		\FineLog::log('temma', \FineLog::ERROR, "Unable to instantiate view '$name'.");
		throw new \Temma\Exceptions\FrameworkException("Unable to load any view.", \Temma\Exceptions\FrameworkException::NO_VIEW);
	}
	/**
	 * Initialisation de la vue.
	 * @param	\Temma\View	$view		L'instance de la vue à initialiser.
	 * @param	\Temma\Response	$response	Paramètres de réponse retournés par le contrôleur.
	 * @throws	\Temma\Exceptions\FrameworkException	Si aucune template ne peut être utilisé.
	 */
	private function _initView(\Temma\View $view, \Temma\Response $response) {
		if ($view->useTemplates()) {
			$template = $response->getTemplate();
			if (empty($template)) {
				$controller = $this->_controllerName ? $this->_controllerName : $this->_objectControllerName;
				$action = $this->_actionName ? $this->_actionName : self::PROXY_ACTION;
				$template = $controller . '/' . $action . self::TEMPLATE_EXTENSION;
			}
			\FineLog::log('temma', \FineLog::DEBUG, "Initializing view '" . get_class($view) . "' with template '$template'.");
			$templatesPath = $this->_config->appPath . '/' . self::TEMPLATES_DIR;
			$isSet = $view->setTemplate($templatesPath, $template);
			if (!$isSet) {
				\FineLog::log('temma', \FineLog::ERROR, "No usable template.");
				throw new \Temma\Exceptions\FrameworkException("No usable template.", \Temma\Exceptions\FrameworkException::NO_TEMPLATE);
			}
		}
		$view->init($response);
	}
}

?>
