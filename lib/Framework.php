<?php

namespace Temma;

require_once('finebase/FineLog.php');
require_once('finebase/FineDatasource.php');
require_once('finebase/FineSession.php');
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
 *	 // Indique qu'on souhaite utiliser une base de données.
 *	 // Optionnel : True si le paramètre "dsn" est rempli.
 *	 enableDatabase: true,
 *
 *	 // DSN de connexion à la base de données (cf. objet FineDatabase).
 *	 // Optionnel : Cette information peut être définie en variable d'environnement (TEMMA_DSN).
 *	 dsn: "mysqli://user:passwd@localhost/toto",
 *
 *	 // Nom du cookie de session.
 *	 // Optionnel : "TemmaSession" par défaut.
 *	 sessionName: "TemmaSession",
 *
 *	 // Indique qu'on souhaite utiliser de sessions.
 *	 // Optionnel : True par défaut.
 *	 enableSessions: true,
 *
 *	 // Indique qu'on souhaite utiliser une base de données non relationnelle.
 *	 // Optionnel : True si le paramètre "ndbDsn" est rempli.
 *	 enableNDB: true,
 *
 *	 // Informations de connexion au serveur de base de données non relationnelle.
 *	 // Optionnel : Cette information peut être définie en variable d'environnement (TEMMA_NDB_DSN).
 *	 ndbDsn: "redis://localhost/0",
 *
 *	 // Indique qu'on souhaite utiliser au cache.
 *	 // Optionnel : True si le paramètre "cacheDsn" est rempli.
 *	 enableCache: true,
 *
 *	 // Informations de connexion au serveur de cache.
 *	 // Optionnel : Cette information peut être définie en variable d'environnement (TEMMA_CACHE_DSN).
 *	 cacheDsn: "192.168.0.1:11211;192.168.0.2:11211",
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
 *
 *	 // Nom de la vue par défaut.
 *	 // Optionnel : TemmaSmartyView par défaut.
 *	 defaultView: "TemmaSmartyView"
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
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @see		\Temma\Controller
 * @see		\Temma\View
 */
class Framework {
	/** Nom de l'objet local pouvant contenir la configuration de l'application. */
	const CONFIG_OBJECT_FILE_NAME = '_TemmaConfig.php';
	/** Nom du fichier local de configuration de l'application. */
	const CONFIG_FILE_NAME = 'temma.json';
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
	/** Liste des connexions à des sources de données. */
	private $_dataSources = null;
	/** Objet de gestion de la session. */
	private $_session = null;
	/** Objet de requête. */
	private $_request = null;
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
	}
	/** Destructeur. */
	public function __destruct() {
	}
	/** Initialisation du framework : lecture de la configuration, connexion aux sources de données, création de la session. */
	public function init() {
		// chargement de la configuration, initialisation du système de log
		$this->_loadConfig();
		// connexions aux sources de données
		if (isset($this->_executorController))
			$this->_dataSources = $this->_executorController->getDataSources();
		else {
			$this->_dataSources = array();
			$foundDb = $foundCache = false;
			foreach ($this->_config->dataSources as $name => $dsn)
				$this->_dataSources[$name] = \FineDatasource::factory($dsn);
		}
		// récupération de la session
		if ($this->_config->enableSessions) {
			$sessionSource = (isset($this->_config->sessionSource) && isset($this->_dataSources[$this->_config->sessionSource])) ?
					 $this->_dataSources[$this->_config->sessionSource] : null;
			$this->_session = \FineSession::factory($sessionSource, $this->_config->sessionName);
		}
	}
	/**
	 * En cas de configuration automatique, cette méthode permet de définir l'objet de configuration à utiliser.
	 * @param	\Temma\Config	$config	L'objet de configuration à utiliser.
	 * @see		temma/bin/configObjectGenerator.php
	 */
	public function setConfig(\Temma\Config $config) {
		$this->_config = $config;
	}
	/**
	 * Lance l'exécution du contrôleur.
	 * @param	\Temma\Request		$request	(optionnel) Requête à utiliser pour l'exécution. Crée automatiquement une
	 *							requête à partir de l'URL courante si ce paramètre n'est pas fourni.
	 * @param	\Temma\Controller	$controller	(optionnel) Contrôleur principal à utiliser pour l'exécution. Ce paramètre
	 *							est à utiliser pour réutiliser les variables définies dans un contrôleur existant.
	 */
	public function process(\Temma\Request $request=null, \Temma\Controller $controller=null) {
		/* *************** INITIALISATIONS ********** */
		// extraction des paramètres de la requête
		$this->_request = isset($request) ? $request : new \Temma\Request();
		// récupération ou création du contrôleur exécuteur
		if (isset($controller))
			$this->_executorController = $controller;
		if (isset($this->_executorController)) {
			$this->_executorController->setSession($this->_session);
			$this->_executorController->setRequest($this->_request);
		} else
			$this->_executorController = new \Temma\Controller($this->_dataSources, $this->_session, $this->_config, $this->_request);
		// initialisation des variables
		$this->_executorController->set('URL', $this->_request->getPathInfo());
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
			// exécution du pré-plugin
			$execStatus = $this->_execPlugin($pluginName, 'preplugin');
			// si demandé, arrêt total des traitement
			if ($execStatus === \Temma\Controller::EXEC_QUIT) {
				\FineLog::log('temma', \FineLog::DEBUG, "Premature but wanted end of processing.");
				return;
			}
			// on recalcule le contrôleur et l'action (au cas où le plugin ait modifié
			// le contrôleur, l'action, le namespace par défaut, ou encore les chemins d'inclusion)
			$this->_setControllerName();
			$this->_setActionName();
			// vérification du statut retourné par le plugin
			if ($execStatus === \Temma\Controller::EXEC_STOP ||
			    $execStatus === \Temma\Controller::EXEC_HALT) {
				// arrêt des traitements (des pré-plugins)
				break;
			} else if ($execStatus === \Temma\Controller::EXEC_RESTART) {
				// reprise des traitements de tous les pré-plugins
				$prePlugins = $this->_generatePrePluginsList();
				reset($prePlugins);
			} else if ($execStatus === \Temma\Controller::EXEC_REBOOT) {
				// on recommence tous les traitements à zéro
				$this->process();
				return;
			}
		}

		/* ************** CONTROLEUR ************* */
		if ($this->_controllerReflection->getName() == 'Temma\Controller')
			throw new \Temma\Exceptions\HttpException("The requested page doesn't exists.", 404);
		if ($execStatus === \Temma\Controller::EXEC_FORWARD) {
			do {
				// on vérifie la méthode qui va être exécutée
				$this->_checkActionMethod();
				// on exécute le contrôleur
				\FineLog::log('temma', \FineLog::DEBUG, "Controller processing.");
				$execStatus = $this->_executorController->subProcess($this->_objectControllerName, $this->_actionName);
			} while ($execStatus === \Temma\Controller::EXEC_RESTART);
			// si demandé, on recommence tous les traitements à zéro
			if ($execStatus === \Temma\Controller::EXEC_REBOOT) {
				$this->process();
				return;
			}
			// si demandé, arrêt total des traitement
			if ($execStatus === \Temma\Controller::EXEC_QUIT) {
				\FineLog::log('temma', \FineLog::DEBUG, "Premature but wanted end of processing.");
				return;
			}
		}

		/* ************* POST-PLUGINS ************** */
		if ($execStatus === \Temma\Controller::EXEC_FORWARD) {
			\FineLog::log('temma', \FineLog::DEBUG, "Processing of post-process plugins.");
			// génération de la liste des plugins à exécuter
			$postPlugins = $this->_generatePostPluginsList();
			// traitement des plugins POST-process
			while (($pluginName = current($postPlugins)) !== false) {
				next($postPlugins);
				if (empty($pluginName))
					continue;
				// exécution du post-plugin
				$execStatus = $this->_execPlugin($pluginName, 'postplugin');
				// si demandé, arrêt total des traitement
				if ($execStatus === \Temma\Controller::EXEC_QUIT) {
					\FineLog::log('temma', \FineLog::DEBUG, "Premature but wanted end of processing.");
					return;
				}
				// si demandé, on recommence tous les traitements à zéro
				if ($execStatus === \Temma\Controller::EXEC_REBOOT) {
					$this->process();
					return;
				}
				// si demandé, arrêt des traitements (des post-plugins)
				if ($execStatus === \Temma\Controller::EXEC_STOP ||
				    $execStatus === \Temma\Controller::EXEC_HALT) {
					break;
				}
				// si demandé, reprise des traitements de tous les post-plugins
				if ($execStatus === \Temma\Controller::EXEC_RESTART) {
					$this->_setControllerName();
					$this->_setActionName();
					$prePlugins = $this->_generatePrePluginsList();
					reset($prePlugins);
				}
			}
		}

		/* ******************* REPONSE ****************** */
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
		$view = $this->_loadView($response);
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
		$errorPages = $this->_config->errorPages;
		if (isset($errorPages[$code]) && !empty($errorPages[$code]))
			return ($this->_config->webPath . '/' . $errorPages[$code]);
		if (isset($errorPages['default']) && !empty($errorPages['default']))
			return ($this->_config->webPath . '/' . $errorPages['default']);
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
		$etcPath = $appPath . '/' . \Temma\Config::ETC_DIR;
		// on cherche un fichier contenant le code de configuration
		$configObject = $etcPath . '/' . self::CONFIG_OBJECT_FILE_NAME;
		if (is_readable($configObject)) {
			// charge l'objet - appelle automatiquement $temma->setConfig()
			include($configObject);
			$this->_config = new \_TemmaAutoConfig($this, $appPath, $etcPath);
			$this->_executorController = $this->_config->executorController;
			return;
		}
		// lecture du fichier de configuration
		$configFile = $etcPath . '/' . self::CONFIG_FILE_NAME;
		if (!is_readable($configFile))
			throw new \Temma\Exceptions\FrameworkException("Unable to read configuration file '$configFile'.", \Temma\Exceptions\FrameworkException::CONFIG);
		$this->_config = new \Temma\Config($appPath, $etcPath);
		$this->_config->readConfigurationFile($configFile);
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
			$lastBackslashPos = strrpos($this->_controllerName, '\\');
			// vérification que la première lettre du nom du contrôleur est en minuscule (s'il n'y a pas de namespace)
			if ($lastBackslashPos === false && $this->_controllerName != lcfirst($this->_controllerName))
				throw new \Temma\Exceptions\HttpException("Bad name for controller '" . $this->_controllerName . "' (must start by a lower-case character).", 404);
			// on regarde si le contrôleur demandé est en fait un contrôleur virtuel
			$routes = $this->_config->routes;
			for ($nbrLoops = 0, $routeName = $this->_controllerName, $routed = false;
			     $nbrLoops < self::ROUTE_MAX_DEPTH && is_array($routes) && array_key_exists($routeName, $routes);
			     $nbrLoops++) {
				$realName = $routes[$routeName];
				\FineLog::log('temma', \FineLog::INFO, "Routing '$routeName' to '$realName'.");
				unset($routes[$routeName]);
				$routeName = $realName;
				$routed = true;
			}
			// on prend le contrôleur demandé
			if ($routed && substr($routeName, -strlen(self::CONTROLLERS_SUFFIX)) == self::CONTROLLERS_SUFFIX) {
				$this->_objectControllerName = $routeName;
			} else if ($lastBackslashPos !== false) {
				$this->_objectControllerName = substr($this->_controllerName, 0, $lastBackslashPos + 1) .
				                               ucfirst(substr($this->_controllerName, $lastBackslashPos + 1)) .
				                               self::CONTROLLERS_SUFFIX;
			} else {
				$this->_objectControllerName = ucfirst($this->_controllerName) . self::CONTROLLERS_SUFFIX;
			}
		} else {
			// pas de contrôleur demandé, on prend le contrôleur racine
			$this->_objectControllerName = $this->_config->rootController;
		}
		if (empty($this->_objectControllerName)) {
			\FineLog::log('temma', \FineLog::INFO, "No controller found, use the default controller.");
			// pas de contrôleur demandé, on prend le contrôleur par défaut
			$this->_objectControllerName = $this->_config->defaultController;
		} else if (!class_exists($this->_objectControllerName)) {
			$defaultNamespace = $this->_config->defaultNamespace;
			$fullControllerName = (!empty($defaultNamespace) ? "$defaultNamespace\\" : '') . $this->_objectControllerName;
			if (empty($defaultNamespace) || !class_exists($fullControllerName)) {
				\FineLog::log('temma', \FineLog::INFO, "Controller '$fullControllerName' doesn't exists.");
				// pas de contrôleur demandé, on prend le contrôleur par défaut
				$this->_objectControllerName = $this->_config->defaultController;
			} else {
				\FineLog::log('temma', \FineLog::INFO, "Controller name set to '$fullControllerName'.");
				$this->_objectControllerName = $fullControllerName;
			}
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
		$plugins = $this->_config->plugins;
		$prePlugins = isset($plugins['_pre']) ? $plugins['_pre'] : array();
		if (isset($plugins[$this->_objectControllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_objectControllerName]['_pre']);
		if (isset($plugins[$this->_objectControllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_objectControllerName][$this->_actionName]['_pre']);
		if (isset($plugins[$this->_controllerName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_controllerName]['_pre']);
		if (isset($plugins[$this->_controllerName][$this->_actionName]['_pre']))
			$prePlugins = array_merge($prePlugins, $plugins[$this->_controllerName][$this->_actionName]['_pre']);
		\FineLog::log('temma', \FineLog::DEBUG, "Pre plugins: " . print_r($prePlugins, true));
		return ($prePlugins);
	}
	/**
	 * Génère la liste des plugins "post".
	 * @return	array	Liste de noms.
	 */
	private function _generatePostPluginsList() {
		$plugins = $this->_config->plugins;
		$postPlugins = isset($plugins['_post']) ? $plugins['_post'] : array();
		if (isset($plugins[$this->_objectControllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_objectControllerName]['_post']);
		if (isset($plugins[$this->_objectControllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_objectControllerName][$this->_actionName]['_post']);
		if (isset($plugins[$this->_controllerName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_controllerName]['_post']);
		if (isset($plugins[$this->_controllerName][$this->_actionName]['_post']))
			$postPlugins = array_merge($postPlugins, $plugins[$this->_controllerName][$this->_actionName]['_post']);
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
			if (!class_exists($pluginName)) {
				$defaultNamespace = $this->_config->defaultNamespace;
				$fullPluginName = "$defaultNamespace\\" . $pluginName;
				if (empty($defaultNamespace) || !class_exists($fullPluginName)) {
					\FineLog::log('temma', \FineLog::DEBUG, "Plugin '$pluginName' doesn't exist.");
					throw new \Exception();
				}
				$pluginName = $fullPluginName;
			}
			if (!is_subclass_of($pluginName, '\Temma\Controller')) {
				\FineLog::log('temma', \FineLog::DEBUG, "Plugin '$pluginName' is not a subclass of \\Temma\\Controller.");
				throw new \Exception();
			}
			$plugin = new $pluginName($this->_dataSources, $this->_session, $this->_config, $this->_request, $this->_executorController);
			$methodName = method_exists($plugin, $methodName) ? $methodName : 'plugin';
			return ($plugin->$methodName());
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
	 * @param	\Temma\Response	$response	Paramètres de réponse retournés par le contrôleur.
	 * @return	\Temma\View	Instance de la vue demandée.
	 * @throws	\Temma\Exceptions\FrameworkException	Si aucune ne peut être chargée.
	 */
	private function _loadView(\Temma\Response $response) {
		$name = $response->getView();
		\FineLog::log('temma', \FineLog::INFO, "Loading view '$name'.");
		// si la vue n'est pas définie, on utilise la vue par défaut
		if (empty($name)) {
			\FineLog::log('temma', \FineLog::DEBUG, "Using default view.");
			$name = self::DEFAULT_VIEW;
		}
		// chargement de la vue
		if (class_exists($name) && is_subclass_of($name, '\Temma\View'))
			return (new $name($this->_dataSources, $this->_config, $response));
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
			$templatePrefix = trim($response->getTemplatePrefix(), '/');
			if (!empty($templatePrefix))
				$template = $templatePrefix . '/' . $template;
			\FineLog::log('temma', \FineLog::DEBUG, "Initializing view '" . get_class($view) . "' with template '$template'.");
			if (!$view->setTemplate($this->_config->templatesPath, $template)) {
				\FineLog::log('temma', \FineLog::ERROR, "No usable template.");
				throw new \Temma\Exceptions\FrameworkException("No usable template.", \Temma\Exceptions\FrameworkException::NO_TEMPLATE);
			}
		}
		$view->init();
	}
}

