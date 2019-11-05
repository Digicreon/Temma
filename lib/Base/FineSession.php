<?php

require_once("finebase/FineLog.php");
require_once("finebase/FineCache.php");

/**
 * Objet de gestion des session.
 *
 * Cet objet est à appeler au plus tôt dans l'exécution d'une application Web.
 * Il crée un identifiant de session qui est déposé sur le poste client par
 * cookie. Si un identifiant existait déjà en cookie, la session est récupérée
 * depuis cet identifiant, et un nouvel identifiant de session est créé et
 * déposé en cookie.
 *
 * Il est possible de stocker un identifiant d'utilisateur, qui restera associé
 * à la session. Il est aussi possible de stocker des variables de session, qui
 * sont des chaînes de caractère.
 *
 * Exemple d'utilisation en 3 scripts. Le premier script crée une session dès
 * la première visite d'un utilisateur :
 * <code>
 * // création de l'objet de session
 * $session = FineSession::factory($db);
 * // stockage d'une variable de session
 * $session->set("trululu", "pouet");
 * </code>
 * Le deuxième script utilise les informations stockées, puis efface toutes les
 * données de session :
 * <code>
 * $session = FineSession::factory($db);
 * // récupération de la variable de session
 * $trululu = $session->get("trululu");
 * // efface toutes les données de session
 * $session->clean();
 * </code>
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2010, FineMedia
 * @package	FineBase
 * @version	$Id: FineSession.php 629 2012-06-26 11:39:42Z abouchard $
 */
class FineSession {
	/** Constante : Durée de session courte (1 journée). */
	const SHORT_DURATION = 86400;
	/** Constante : Durée de session moyenne (1 mois). */
	const MEDIUM_DURATION = 2592000;
	/** Constante : Durée de session longue (1 an). */
	const LONG_DURATION = 31536000;
	/** Objet de gestion du cache. */
	private $_cache = null;
	/** Nom du cookie de session.*/
	private $_cookieName = null;
	/** Durée de la session */
	private $_duration = null;
	/** Tableau des données de session. */
	private $_data = null;
	/** Identifiant de session. */
	private $_sessionId = null;

	/* ************************** CONSTRUCTION ******************** */
	/**
	 * Crée un objet FineSession.
	 * @param	FineDatasource	$cache		(optionnel) Instance de connexion au cache.
	 * @param	string		$cookieName	(optionnel) Nom du cookie de session. "FINE_SESSION" par défaut.
	 * @param	int		$duration	(optionnel) Durée de la session. Un an par défaut.
	 * @param	int		$renewDelay	(optionnel) Délai avant recréation de l'identifiant de session. 20 mn par défaut.
	 * @return	FineSession	L'instance.
	 */
	static public function factory(FineDatasource $cache=null, $cookieName='FINE_SESSION', $duration=31536000, $renewDelay=1200) {
		return (new FineSession($cache, $cookieName, $duration, $renewDelay));
	}
	/**
	 * Constructeur.
	 * @param	FineDatasource	$cache		Instance de connexion au cache.
	 * @param	string		$cookieName	Nom du cookie de session. "FINE_SESSION" par défaut.
	 * @param	int		$duration	Durée de la session.
	 * @param	int		$renewDelay	Délai avant recréation de l'identifiant de session.
	 */
	private function __construct(FineDatasource $cache=null, $cookieName=null, $duration=null, $renewDelay=null) {
		FineLog::log('finebase', FineLog::DEBUG, "Session object creation.");
		// récupération du cache
		if (isset($cache) && $cache->isEnabled())
			$this->_cache = clone $cache;
		// si le cache n'est pas actif, on utilise les sessions PHP standard
		if (!isset($this->_cache)) {
			session_start();
			return;
		}
		$this->_data = array();
		$this->_cookieName = $cookieName;
		// recherche de l'identifiant de session dans les cookies
		$oldSessionId = isset($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : null;
		$this->_sessionId = $oldSessionId;
		// constitution du nouvel identifiant de session
		$newSessionId = hash('md5', time() . mt_rand(0, 0xffff) . mt_rand(0, 0xffff) . mt_rand(0, 0xffff) . mt_rand(0, 0xffff));
		// recherche des données de session
		if (!empty($oldSessionId)) {
			// récupération des données en cache
			$data = $this->_cache->get("sess:$oldSessionId");
			if (isset($data['_magic']) && $data['_magic'] == 'Ax')
				$this->_data = isset($data['data']) ? $data['data'] : null;
			else {
				unset($_COOKIE[$cookieName]);
				$newSessionId = $this->_sessionId;
			}
		}
		// calcul de la date d'expiration de session
		if (!isset($this->_data))
			$duration = self::SHORT_DURATION;
		$this->_duration = $duration;
		$timestamp = time() + $duration;
		$expiration = date('Y-m-d H-i-s', $timestamp);
		// enregistrement de l'identifiant de session en cookie
		if (!preg_match("/[^.]+\.[^.]+$/", $_SERVER['HTTP_HOST'], $matches))
			$host = $_SERVER['HTTP_HOST'];
		else
			$host = $matches[0];
		if (!isset($_COOKIE[$cookieName]) || empty($_COOKIE[$cookieName]) || $this->_sessionId != $oldSessionId && !headers_sent()) {
			FineLog::log('finebase', FineLog::DEBUG, "Send cookie '$cookieName' - '$newSessionId' - '$timestamp' - '.$host'");
			// envoi du cookie
			if (PHP_VERSION_ID < 70300) {
				// PHP < 7.3 : utilisation d'un hack permettant d'envoyer l'attribut 'samesite'
				setcookie($cookieName, $newSessionId, $timestamp, '/; samesite=Lax', ".$host", false);
			} else {
				// PHP >= 7.3 : utilisation d'un tableau associatif
				setcookie($cookieName, $newSessionId, [
					'expires'	=> $timestamp,
					'path'		=> '/',
					'domain'	=> ".$host",
					'secure'	=> false,
					'httponly'	=> false,
					'samesite'	=> 'Lax'
				]);
			}
		}
	}

	/* *********************** GESTION ******************************** */
	/** Efface toutes les variables de la session courante. */
	public function clean() {
		FineLog::log('finebase', FineLog::DEBUG, "Cleaning session.");
		if (!isset($this->_cache)) {
			// session PHP standard
			foreach ($_SESSION as $key => $val)
				unset($_SESSION[$key]);
			return;
		}
		// reset du tableau interne
		unset($this->_data);
		$this->_data = array();
		// effacement des données en cache
		$this->_cache->set("sess:" . $this->_sessionId, null);
	}
	/** Efface la session courante. */
	public function remove() {
		FineLog::log('finebase', FineLog::DEBUG, "Removing current session.");
		if (!isset($this->_cache)) {
			// session PHP standard
			foreach ($_SESSION as $key => $val)
				unset($_SESSION[$key]);
			return;
		}
		// efface la session
		$this->_cache->set('sess:' . $this->_sessionId, null);
		// reset des variables internes
		unset($this->_data);
		$this->_data = null;
	}
	/**
	 * Retourne l'identifiant de la session courante.
	 * @return	string	L'identifiant de session.
	 */
	public function getSessionId() {
		if (!isset($this->_cache))
			return (session_id());
		return ($this->_sessionId);
	}

	/* ****************** DONNEES DE SESSION ****************** */
	/**
	 * Enregistre une donnée de session.
	 * La donnée est sérialisée grâce à la fonction serialize() de PHP,
	 * qui accepte les types bool, int, float, string, array, object, null.
	 * Voir la documentation de serialize/unserialize pour plus d'informations.
	 * @param	string	$key	Nom de la donnée.
	 * @param	mixed	$value	(optionnel) Valeur de la donnée. La donnée est effacée si cette valeur vaut null ou si elle n'est pas fournie.
	 * @link	http://php.net/manual/function.serialize.php
	 */
	public function set($key, $value=null) {
		FineLog::log('finebase', FineLog::DEBUG, "Setting value for key '$key'.");
		if (!isset($this->_cache)) {
			if (is_null($value))
				unset($_SESSION[$key]);
			else
				$_SESSION[$key] = $value;
			return;
		}
		// mise-à-jour du tableau interne
		if (is_null($value))
			unset($this->_data[$key]);
		else
			$this->_data[$key] = $value;
		// synchronisation des données
		$cacheData = array(
			'_magic'	=> 'Ax',
			'data'		=> $this->_data
		);
		if (is_a($this->_cache, '\FineCache'))
			$this->_cache->set('sess:' . $this->_sessionId, $cacheData, $this->_duration);
		else if (is_a($this->_cache, '\FineNDB'))
			$this->_cache->set('sess:' . $this->_sessionId, $cacheData, false, $this->_duration);
	}
	/**
	 * Enregistre une donnée de tableau associatif en session.
	 * Le tableau est créé s'il n'existe pas.
	 * @param	string	$key		Nom de la variable de session.
	 * @param	string	$arrayKey	Nom de la clé du tableau associatif.
	 * @param	mixed	$value		(optionnel) Valeur de la donnée. La donnée est effacée si cette valeur vaut null ou si elle n'est pas fournie.
	 */
	public function setArray($key, $arrayKey, $value=null) {
		FineLog::log('finebase', FineLog::DEBUG, "Setting value for array '$key\[$arrayKey\]'.");
		if (!isset($this->_cache)) {
			if (is_null($value)) {
				if (isset($_SESSION[$key][$arrayKey]))
					unset($_SESSION[$key][$arrayKey]);
			} else {
				if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key]))
					$_SESSION[$key] = [];
				$_SESSION[$key][$arrayKey] = $value;
			}
			return;
		}
		// mise-à-jour du tableau interne
		if (is_null($value)) {
			if (isset($this->_data[$key][$arrayKey]))
				unset($this->_data[$key][$arrayKey]);
		} else {
			if (!isset($this->_data[$key]) || !is_array($this->_data[$key]))
				$this->_data[$key] = [];
			$this->_data[$key][$arrayKey] = $value;
		}
		// synchronisation des données
		$cacheData = array(
			'_magic'	=> 'Ax',
			'data'		=> $this->_data
		);
		$this->_cache->set('sess:' . $this->_sessionId, $cacheData, $this->_duration);
	}
	/**
	 * Récupère une donnée de session.
	 * La donnée est désérialisée grâce à la fonction unserialize() de PHP.
	 * @param	string	$key		Nom de la donnée.
	 * @param	mixed	$default	(optionnel) Valeur par défaut, si la donnée n'existe pas en session.
	 * @return	mixed	Valeur de la donnée de session.
	 * @link	http://php.net/manual/function.unserialize.php
	 */
	public function get($key, $default=null) {
		FineLog::log('finebase', FineLog::DEBUG, "Returning value for key '$key'.");
		if (!isset($this->_cache)) {
			if (!isset($_SESSION[$key]) && isset($default))
				return ($default);
			if (isset($_SESSION[$key]))
				return ($_SESSION[$key]);
			return (null);
		}
		if (!isset($this->_data[$key]) && isset($default))
			return ($default);
		if (isset($this->_data[$key]))
			return ($this->_data[$key]);
		return (null);
	}
	/**
	 *  Retourne toutes les variables de la session
	 *  @return array Données de la session. 
	 */
	public function getAll() {
		if (!isset($this->_data))
			return (null);
		return ($this->_data); 
	}
}

?>
