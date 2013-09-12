<?php

namespace Temma;

/**
 * Objet basique de gestion des contrôleurs au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2007-2012, Fine Media
 * @package	Temma
 * @version	$Id: BaseController.php 290 2012-11-13 11:33:23Z abouchard $
 */
class BaseController {
	/** Constante indiquant de passer au plugin suivant. */
	const EXEC_FORWARD = null;
	/** Constante indiquant d'arrêter le traitement des plugins. */
	const EXEC_STOP = 0;
	/** Constante indiquant d'arrêter le traitement de tous les contrôleurs, plugins compris. */
	const EXEC_HALT = 1;
	/** Constante indiquant d'arrêter le traitement de tous les contrôleurs, plugins compris, et de n'exécuter aucune vue. */
	const EXEC_QUIT = 2;
	/** Liste des sources de données. */
	protected $_dataSources = null;
	/** Objet de gestion de session. */
	protected $_session = null;
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
	 * @param	array		$dataSources	Liste de sources de données.
	 * @param	FineSession	$session	Objet de gestion de la session.
	 * @param	TemmaConfig	$config		Objet contenant la configuration de l'application.
	 * @param	TemmaRequest	$request	Objet de la requête.
	 * @param	TemmaController	$executor	(optionnel) Objet contrôleur qui a instancié l'exécution de ce contrôleur.
	 */
	public function __construct($dataSources, \FineSession $session=null, \Temma\Config $config, \Temma\Request $request=null, $executor=null) {
		$this->_dataSources = $dataSources;
		$this->_config = $config;
		$this->_request = $request;
		$this->_executorController = $executor;
		if (isset($session))
			$this->setSession($session);
		// création de DAO si nécessaire
		if (isset($executor) && isset($this->_temmaAutoDao) && !is_null($this->_temmaAutoDao) && $this->_temmaAutoDao !== false) {
			$controllerName = $executor->get('CONTROLLER');
			$controllerName = $controllerName ? $controllerName : get_class($this);
			$this->_dao = $this->loadDao($this->_temmaAutoDao);
		}
	}
	/**
	 * Définit l'objet de gestion des sessions.
	 * @param	FineSession	$session	Objet de gestion de la session.
	 * @return	\Temma\Controller	L'instance courante de l'objet.
	 */
	public function setSession(\FineSession $session) {
		$this->_session = $session;
		if (is_null($this->_executorController) && isset($session) && $this->_config->enableSessions) {
			// contrôleur de plus haut niveau : affectation de la variable contenant l'identifiant de session
			$sessionId = $session->getSessionId();
			$this->set('SESSIONID', $sessionId);
		}
		return ($this);
	}
	/**
	 * Définit l'objet de requête.
	 * @param	\Temma\Request	$request	Objet de la requête.
	 * @return	\Temma\Controller	L'instance courante de l'objet.
	 */
	public function setRequest(\Temma\Request $request) {
		$this->_request = $request;
		return ($this);
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
			'source'	=> null,
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
			$daoConf['source'] = isset($param['source']) ? $param['source'] : $daoConf['source'];
			$daoConf['cache'] = isset($param['cache']) ? $param['cache'] : $daoConf['cache'];
			$daoConf['base'] = isset($param['base']) ? $param['base'] : $daoConf['base'];
			$daoConf['table'] = isset($param['table']) ? $param['table'] : $daoConf['table'];
			$daoConf['id'] = isset($param['id']) ? $param['id'] : $daoConf['id'];
			$daoConf['fields'] = (isset($param['fields']) && is_array($param['fields'])) ? $param['fields'] : $daoConf['fields'];
		}
		// instanciation
		if (isset($daoConf['source']) && isset($this->_dataSources[$daoConf['source']]))
			$dataSource = $this->_dataSources[$daoConf['source']];
		else
			$dataSource = reset($this->_dataSources);
		$dao = new $daoConf['object']($dataSource, ($daoConf['cache'] ? $this->_cache : null), $daoConf['table'], $daoConf['id'],
					      $daoConf['base'], $daoConf['fields'], $daoConf['criteria']);
		return ($dao);
	}

	/* ****************** METHODES APPELEES PAR LE FRAMEWORK ************** */
	/**
	 * Retourne la liste des sources de données.
	 * @return	array	La liste.
	 */
	final public function getDataSources() {
		return ($this->_dataSources);
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

	/* ****************** METHODES APPELEES PAR LES OBJETS ENFANTS ************* */
	/**
	 * Méthode magique qui retourne une connexion à une source de données.
	 * @param	string	$dataSource	Nom de la source de données.
	 * @return	\FineDatasource	Objet de connexion à la source de données.
	 */
	final public function __get($dataSource) {
		if (isset($this->_dataSources[$dataSource]))
			return ($this->_dataSources[$dataSource]);
		return (null);
	}
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
	 * Retourne l'erreur HTTP configurée.
	 * @return	int	Le code d'erreur configuré (403, 404, 500, ...) ou NULL si aucune erreur n'a été configurée.
	 */
	final protected function getHttpError() {
		if (isset($this->_executorController))
			return ($this->_executorController->getHttpError());
		$this->_checkResponse();
		return ($this->_response->getHttpError());
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
	 * Modifie le préfixe du chemin de template à utiliser.
	 * @param	string	$prefix	Le préfixe de template.
	 */
	final protected function templatePrefix($prefix) {
		if (isset($this->_executorController))
			$this->_executorController->templatePrefix($prefix);
		else {
			$this->_checkResponse();
			$this->_response->setTemplatePrefix($prefix);
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
		$obj = new $controller($this->_dataSources, $this->_session, $this->_config, $this->_request, $this);
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

	/* *********************** METHODES PRIVEES ******************* */
	/** Vérifie que l'objet de réponse existe. */
	private function _checkResponse() {
		if (is_null($this->_response))
			$this->_response = new \Temma\Response();
	}
}

?>
