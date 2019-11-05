<?php

namespace Temma;

/**
 * Objet de gestion des requêtes HTTP dans le framework Temma.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
class Request {
	/** Information de pathInfo. */
	private $_pathInfo = null;
	/** Nom du contrôleur demandé. */
	private $_controller = null;
	/** Nom de l'action demandée. */
	private $_action = null;
	/** Paramètres de l'action. */
	private $_params = null;
	/** Chemin depuis la racine du site. */
	private $_sitePath = null;
	/** Données JSON reçues. */
	private $_json = null;
	/** Données XML reçues. */
	private $_xml = null;

	/**
	 * Constructeur.
	 * @param	string	$setUri	(optionnel) Chemin à utiliser pour déterminer l'exécution demandée,
	 *				sans passer par l'analyse de l'URL courante.
	 */
	public function __construct($setUri=null) {
		\FineLog::log('temma', \FineLog::DEBUG, "Request creation.");
		if (isset($setUri)) {
			// utilisation du chemin fournit en paramètre
			$requestUri = '/' . trim($setUri, '/') . '/';
		} else {
			// extraction du chemin par rapport à la racine du site
			if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
				$requestUri = $_SERVER['REQUEST_URI'];
				if (($offset = strpos($requestUri, '?')) !== false)
					$requestUri = substr($requestUri, 0, $offset);
			} else if (isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])) {
				// récupération du chemin d'exécution du script
				$rootPath = $_SERVER['SCRIPT_FILENAME'];
				if (substr($rootPath, -10) == '/index.php')
					$rootPath = substr($rootPath, 0, -10);
				// récupération du chemin (éventuellement en retirant le chemin d'exécution du script ou les paramètres GET)
				$requestUri = $_SERVER['PATH_INFO'];
				$rootPathLen = strlen($rootPath);
				if (substr($requestUri, 0, $rootPathLen) === $rootPath)
					$requestUri = substr($requestUri, $rootPathLen);
			} else
				throw new \Temma\Exceptions\FrameworkException('No PATH_INFO nor REQUEST_URI environment variable.', \Temma\Exceptions\FrameworkExceptions::CONFIG);
			// spécial référencement : si l'URL demandée se terminait par un slash, on fait une redirection
			// hint: PATH_INFO n'est pas rempli quand on accède à la racine du projet Temma, qu'il soit à la racine du site ou dans un sous répertoire
			if (isset($_SERVER['PATH_INFO']) && !empty($PATH_INFO) && substr($requestUri, -1) == '/') {
				$url = substr($requestUri, 0, -1) . ((isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) ? ('?' . $_SERVER['QUERY_STRING']) : '');
				\FineLog::log('temma', \FineLog::DEBUG, "Redirecting to '$url'.");
				header('HTTP/1.1 301 Moved Permanently');
				header("Location: $url");
				exit();
			}
		}
		\FineLog::log('temma', \FineLog::INFO, "URL : '$requestUri'.");
		$this->_pathInfo = $requestUri;
		// extraction des composantes de l'URL
		$chunkedUri = explode('/', $requestUri);
		array_shift($chunkedUri);
		$this->_controller = array_shift($chunkedUri);
		$this->_action = array_shift($chunkedUri);
		$this->_params = array();
		foreach ($chunkedUri as $chunk)
			if (strlen(trim($chunk)) > 0)
				$this->_params[] = $chunk;
		// extraction du chemin depuis la racine du site
		$this->_sitePath = dirname($_SERVER['SCRIPT_NAME']);
	}

	/* ***************** GETTERS *************** */
	/**
	 * Décode des données JSON reçues en POST.
	 * @param	string	$key	Nom de la donnée à retourner. Si ce paramètre n'est pas fourni,
	 *				l'ensemble des données JSON est retourné.
	 * @return      mixed   La ou les donnée(s) JSON décodée(s).
	 */
	public function getJson($key=null) {
		if (is_null($this->_json) && isset($GLOBALS['HTTP_RAW_POST_DATA']))
			$this->_json = json_decode(urldecode($GLOBALS['HTTP_RAW_POST_DATA']));
		if (!empty($key))
			return ($this->_json->$key);
		return ($this->_json);
	}
	/**
	 * Décode les données XML reçues en POST.
	 * @param	string	$key	Nom du noeud XML à retourner. Si ce paramètre n'est pas fourni,
	 *				l'ensemble des données XML est retourné.
	 * @return	mixed	La ou les données( ) XML décodée(s).
	 */
	public function getXml($key=null) {
		if (is_null($this->_xml) && isset($GLOBALS['HTTP_RAW_POST_DATA']))
			$this->_xml = new SimpleXMLElement(urldecode($GLOBALS['HTTP_RAW_POST_DATA']));
		if (!empty($key))
			return ($this->_xml->$key);
		return ($this->_xml);
	}
	/**
	 * Retourne le pathInfo.
	 * @return 	string	Le pathInfo.
	 */
	public function getPathInfo() {
		return ($this->_pathInfo);
	}
	/**
	 * Retourne le nom du contrôleur demandé.
	 * @return	string	Le nom du contrôleur.
	 */
	public function getController() {
		return ($this->_controller);
	}
	/**
	 * Retourne le nom de l'action demandée.
	 * @return	string	Le nom de l'action.
	 */
	public function getAction() {
		return ($this->_action);
	}
	/**
	 * Retourne le nombre de paramètres.
	 * @return	int	Le nombre de paramètres.
	 */
	public function getNbrParams() {
		return (is_array($this->_params) ? count($this->_params) : 0);
	}
	/**
	 * Retourne les paramètres de l'action.
	 * @return	array	La liste des paramètres.
	 */
	public function getParams() {
		return ($this->_params);
	}
	/**
	 * Retourne un paramètre de l'action.
	 * @param	int	$index		Index du paramètre dans la liste de paramètres.
	 * @param	mixed	$default	(optionel) Valeur par défaut si le paramètre demandé n'existe pas.
	 * @return	string	Le paramètre demandé.
	 */
	public function getParam($index, $default=null) {
		if (isset($this->_params[$index]))
			return ($this->_params[$index]);
		return ($default);
	}
	/**
	 * Retourne le chemin depuis la racine du site.
	 * @return	string	Le chemin depuis la racine.
	 */
	public function getSitePath() {
		return ($this->_sitePath);
	}

	/* ***************** SETTERS *************** */
	/**
	 * Positionne le contenu qui aurait dû être reçu sur l'entrée standard.
	 * @param	string	$content	Le contenu.
	 */
	public function setInputData($content) {
		$GLOBALS['HTTP_RAW_POST_DATA'] = $content;
	}
	/**
	 * Positionne le nom du contrôleur à utiliser.
	 * @param	string	$name	Le nom du contrôleur.
	 */
	public function setController($name) {
		$this->_controller = $name;
	}
	/**
	 * Positionne le nom de l'action à exécuter.
	 * @param	string	$name	Le nom de l'action.
	 */
	public function setAction($name) {
		$this->_action = $name;
	}
	/**
	 * Positionne tous les paramètres reçus sur l'URL.
	 * @param	array	$data	Tableau de chaînes de caractères.
	 */
	public function setParams($data) {
		if (is_array($data))
			$this->_params = $data;
	}
	/**
	 * Positionne un paramètre reçu sur l'URL.
	 * @param	int	$index	Index du paramètre à modifier.
	 * @param	string	$value	Valeur à positionner.
	 */
	public function setParam($index, $value) {
		$this->_params[$index] = $value;
	}
}

