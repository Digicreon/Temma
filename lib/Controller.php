<?php

namespace Temma;

/**
 * Objet de gestion des contrôleurs au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2011, Fine Media
 * @package	Temma
 * @version	$Id: Controller.php 236 2011-06-29 09:03:29Z abouchard $
 */
class Controller {
	/** Constante indiquant de passer au plugin suivant. */
	const EXEC_FORWARD = null;
	/** Constante indiquant d'arrêter le traitement des plugins. */
	const EXEC_STOP = 0;
	/** Constante indiquant d'arrêter le traitement de tous les contrôleurs, plugins compris. */
	const EXEC_HALT = 1;
	/** Constante indiquant d'arrêter le traitement de tous les contrôleurs, plugins compris, et de n'exécuter aucune vue. */
	const EXEC_QUIT = 2;
	/** Connexion à la base de données. */
	protected $_db = null;
	/** Objet de gestion de session. */
	protected $_session = null;
	/** Objet de gestion du cache. */
	protected $_cache = null;
	/** Configuration de l'application Temma. */
	protected $_config = null;
	/** Objet de requête. */
	protected $_request = null;
	/** Objet contrôleur exécuteur (qui a appelé ce contrôleur). */
	private $_executorController = null;
	/** Objet de réponse. */
	private $_response = null;
	/** Objet DAO. */
	protected $_dao = null;

	/**
	 * Constructeur.
	 * @param	FineDatabase	$db		Objet de connexion à la base de données.
	 * @param	FineSession	$session	Objet de gestion de la session.
	 * @param	FineCache	$cache		Objet de gestion du cache.
	 * @param	TemmaConfig	$config		Objet contenant la configuration de l'application.
	 * @param	TemmaRequest	$request	Objet de la requête.
	 * @param	TemmaController	$executor	(optionnel) Objet contrôleur qui a instancié l'exécution de ce contrôleur.
	 */
	final public function __construct(\FineDatabase $db=null, \FineSession $session=null, \FineCache $cache=null,
					  \Temma\Config $config, \Temma\Request $request, $executor=null) {
		$this->_db = $db;
		$this->_session = $session;
		$this->_cache = $cache;
		$this->_config = $config;
		$this->_request = $request;
		$this->_executorController = $executor;
		if (is_null($executor) && !$config->disableSessions) {
			// contrôleur de plus haut niveau : affectation de la variable contenant l'identifiant de session
			$sessionId = $session->getSessionId();
			$this->set('SESSIONID', $sessionId);
		}
		// création de DAO si nécessaire
		if (isset($executor) && isset($db) && isset($this->_temmaAutoDao) && !is_null($this->_temmaAutoDao) && $this->_temmaAutoDao !== false) {
			$controllerName = $executor->get('CONTROLLER');
			$controllerName = $controllerName ? $controllerName : get_class($this);
			$this->_dao = $this->loadDao($this->_temmaAutoDao);
		}
	}
	/** Destructeur. */
	public function __destruct() {
	}
	/**
	 * Fonction d'initialisation, appelée pour chaque contrôleur
	 * avant que l'action demandée ne soit exécutée.
	 * A redéfinir dans chaque contrôleur.
	 */
	public function init() {
	}
	/**
	 * Action par défaut.
	 * A redéfinir dans chaque contrôleur.
	 */
	public function execIndex() {
		\FineLog::log('temma', \FineLog::INFO, "Dummy Controller default action.");
		$this->httpError(404);
		return (self::EXEC_HALT);
	}

	/* ****************** GESTION DES DAO ************** */
	/**
	 * Charge une DAO.
	 * @param	string|array	$param	Nom de l'objet DAO à charger, ou hash de paramétrage.
	 * @return	\Temma\Dao	L'instance de DAO chargée.
	 */
	public function loadDao($param) {
		$daoConf = array(
			'object'	=> '\Temma\Dao',
			'criteria'	=> null,
			'cache'		=> true,
			'base'		=> null,
			'table'		=> $this->_executorController->get('CONTROLLER'),
			'id'		=> 'id',
			'fields'	=> null
		);
		// récupération de la configuration de la DAO
		if (is_string($param))
			$daoConf['object'] = $param;
		else if (is_array($param)) {
			$daoConf['object'] = isset($param['object']) ? $param['object'] : $daoConf['object'];
			$daoConf['criteria'] = isset($param['criteria']) ? $param['criteria'] : $daoConf['criteria'];
			$daoConf['cache'] = isset($param['cache']) ? $param['cache'] : $daoConf['cache'];
			$daoConf['base'] = isset($param['base']) ? $param['base'] : $daoConf['base'];
			$daoConf['table'] = isset($param['table']) ? $param['table'] : $daoConf['table'];
			$daoConf['id'] = isset($param['id']) ? $param['id'] : $daoConf['id'];
			$daoConf['fields'] = (isset($param['fields']) && is_array($param['fields'])) ? $param['fields'] : $daoConf['fields'];
		}
		// instanciation
		$dao = new $daoConf['object']($this->_db, ($daoConf['cache'] ? $this->_cache : null), $daoConf['table'], $daoConf['id'],
					      $daoConf['base'], $daoConf['fields'], $daoConf['criteria']);
		return ($dao);
	}

	/* ****************** METHODES APPELEES PAR LES OBJETS ENFANTS ************* */
	/**
	 * Indique une erreur HTTP (403, 404, 500, ...).
	 * @param	int	$code	Le code d'erreur HTTP.
	 */
	final protected function httpError($code) {
		if (isset($this->_executorController))
			$this->_executorController->httpError($code);
		else {
			$this->_checkResponse();
			$this->_response->setHttpError($code);
		}
	}
	/**
	 * Indique une redirection HTTP (302).
	 * @param	string	$url	URL De la redirection.
	 */
	final protected function redirect($url) {
		if (isset($this->_executorController)) {
			$this->_executorController->redirect($url);
		} else {
			$this->_checkResponse();
			$this->_response->setRedirection($url);
		}
	}
	/**
	 * Indique une redirection HTTP (301).
	 * @param       string  $url    URL De la redirection.
	 */
	final protected function redirect301($url) {
		if (isset($this->_executorController)) {
			$this->_executorController->redirect301($url);
		} else {
			$this->_checkResponse();
			$this->_response->setRedirection($url, true);
		}
	}
	/**
	 * Modifie le nom de la vue à utiliser.
	 * @param	string	$view	Nom de la vue.
	 */
	final protected function view($view) {
		if (isset($this->_executorController)) {
			$this->_executorController->view($view);
		} else {
			$this->_checkResponse();
			$this->_response->setView($view);
		}
	}
	/**
	 * Modifie le nom du template à utiliser.
	 * @param	string	$template	Nom du template.
	 */
	final protected function template($template) {
		if (isset($this->_executorController)) {
			$this->_executorController->template($template);
		} else {
			$this->_checkResponse();
			$this->_response->setTemplate($template);
		}
	}
	/**
	 * Ajoute une donnée qui pourra être traitée par la vue.
	 * @param	string	$name	Nom de la donnée.
	 * @param	mixed	$value	Valeur de la donnée.
	 */
	final public function set($name, $value) {
		if (isset($this->_executorController)) {
			$this->_executorController->set($name, $value);
		} else {
			$this->_checkResponse();
			$this->_response->setData($name, $value);
		}
	}
	/**
	 * Retourne une donnée qui a été enregistrée préalablement.
	 * @param	string	$name		Nom de la donnée.
	 * @param	string	$default	(optionnel) Valeur par défaut si la donnée n'existe pas.
	 */
	final public function get($name, $default=null) {
		if (isset($this->_executorController)) {
			return ($this->_executorController->get($name, $default));
		} else {
			$this->_checkResponse();
			return ($this->_response->getData($name, $default));
		}
	}
	/**
	 * Exécute un sous-contrôleur.
	 * @param	string	$controller	Nom du contrôleur.
	 * @param	string	$action		(optionnel) Nom de l'action à exécuter. Utilise l'action par défaut si pas défini.
	 * @param	array	$parameters	(optionnel) Liste de paramètres à transmettre au sous-contrôleur. Par défaut, ce sont
	 *					les paramètres reçus par le contrôleur initial.
	 * @return	int	Le statut de l'exécution du sous-contrôleur.
	 * @throws	\Temma\Exceptions\FrameworkException	Si le contrôleur demandé n'existe pas ou ne possède pas l'action demandée.
	 */
	final public function subProcess($controller, $action=null, $parameters=null) {
		\FineLog::log('temma', \FineLog::DEBUG, "Subprocess of '$controller'::'$action'.");
		if (!class_exists($controller) || !is_subclass_of($controller, '\Temma\Controller')) {
			\FineLog::log('temma', \FineLog::ERROR, "Sub-controller '$controller' doesn't exists.");
			throw new \Temma\Exceptions\FrameworkException("Unable to find sub-controller '$controller'.", \Temma\Exceptions\FrameworkException::NO_CONTROLLER);
		}
		// création du sous-contrôleur
		$obj = new $controller($this->_db, $this->_session, $this->_cache, $this->_config, $this->_request, $this);
		// initialisation du sous-contrôleur
		$status = $obj->init();
		if ($status !== self::EXEC_FORWARD)
			return ($status);
		// on regarde si ce contrôleur a une action proxy
		$methodName = \Temma\Framework::ACTION_PREFIX . ucfirst(\Temma\Framework::PROXY_ACTION);
		if (method_exists($controller, $methodName)) {
			\FineLog::log('temma', \FineLog::DEBUG, "Executing proxy action '" . \Temma\Framework::PROXY_ACTION . "'.");
			$status = $obj->$methodName();
			return ($status);
		}
		// pas d'action proxy, on regarde si l'action demandée existe, ou s'il existe une action par défaut
		// si aucune action n'a été spécifiée, on prend celle par défaut
		if (empty($action))
			$action = \Temma\Framework::DEFAULT_ACTION;
		$methodName = \Temma\Framework::ACTION_PREFIX . ucfirst($action);
		\FineLog::log('temma', \FineLog::DEBUG, "Executing action '$action'.");
		$status = call_user_func_array(array($obj, $methodName), (isset($parameters) ? $parameters : $this->_request->getParams()));
		return ($status);
	}
	/**
	 * Retourne la réponse suite à l'exécution du contrôleur.
	 * Cette méthode est à destination de \Temma\framework.
	 * @return	\Temma\Response	La réponse.
	 */
	final public function getResponse() {
		if (isset($this->_executorController)) {
			return ($this->_executorController->getResponse());
		} else {
			$this->_checkResponse();
			return ($this->_response);
		}
	}

	/* *********************** METHODES PRIVEES ******************* */
	/** Vérifie que l'objet de réponse existe. */
	private function _checkResponse() {
		if (is_null($this->_response))
			$this->_response = new \Temma\Response();
	}
}

?>
