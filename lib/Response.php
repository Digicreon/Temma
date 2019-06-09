<?php

namespace Temma;

/**
 * Objet de gestion des réponses à l'exécution des contrôleurs dans le framework Temma.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
class Response {
	/** En-têtes HTTP. */
	private $_headers = null;
	/** Adresse de redirection. */
	private $_redirect = null;
	/** Code de redirection. */
	private $_redirectCode = 302;
	/** Code d'erreur HTTP. */
	private $_httpError = null;
	/** Code de retour HTTP. */
	private $_httpCode = 200;
	/** Nom de la vue à utiliser pour traiter la réponse. */
	private $_view = null;
	/** Préfixe à ajouter au début du chemin de template. */
	private $_templatePrefix = null;
	/** Nom du template à utiliser pour traiter la réponse. */
	private $_template = null;
	/** Données qui seront interprétées par la vue à travers le template. */
	private $_data = null;

	/**
	 * Constructeur.
	 * @param	string	$view		(optionnel) Nom de la vue à utiliser pour traiter la réponse.
	 * @param	string	$template	(optionnel) Nom du template à utiliser pour traiter la réponse.
	 */
	public function __construct($view=null, $template=null) {
		\FineLog::log('temma', \FineLog::DEBUG, "Response creation.");
		$this->_view = $view;
		$this->_template = $template;
		$this->_data = array();
		$this->_headers = array();
	}
	/**
	 * Affecte une redirection.
	 * @param	string	$url		Adresse de redirection.
	 * @param	bool	$code301	Indique s'il faut utiliser une redirection 301 (faux par défaut).
	 */
	public function setRedirection($url, $code301=false) {
		$this->_redirect = $url;
		if ($code301)
			$this->_redirectCode = 301;
	}
	/**
	 * Affecte un code d'erreur HTTP.
	 * @param	int	$code	Le code d'erreur (403, 404, 500, ...).
	 */
	public function setHttpError($code) {
		$this->_httpError = $code;
	}
	/**
	 * Affecte un code de retour HTTP.
	 * @param	int	$code	Le code de retour (403, 404, 500, ...).
	 */
	public function setHttpCode($code) {
		$this->_httpCode = $code;
	}
	/**
	 * Modifie le nom de la vue.
	 * @param	string	$view	Nom de la vue.
	 */
	public function setView($view) {
		$this->_view = $view;
	}
	/**
	 * Mpodifie le préfixe de template.
	 * @param	string	$prefix	Le préfixe de template.
	 */
	public function setTemplatePrefix($prefix) {
		$this->_templatePrefix = $prefix;
	}
	/**
	 * Modifie le nom du template.
	 * @param	string	$template	Nom du template.
	 */
	public function setTemplate($template) {
		$this->_template = $template;
	}
	/**
	 * Ajoute une donnée.
	 * @param	string	$name	Nom de la donnée.
	 * @param	mixed	$value	Valeur de la donnée.
	 */
	public function setData($name, $value) {
		$this->_data[$name] = $value;
	}

	/* ***************** GETTERS *************** */
	/**
	 * Retourne l'URL de redirection.
	 * @return	string	L'URL de redirection.
	 */
	public function getRedirection() {
		return ($this->_redirect);
	}
	/**
	 * Retourne le code de redirection 302 ou 301
	 * @return	int	Code de redirection
	 */
	public function getRedirectionCode() {
                return ($this->_redirectCode);
        }
	/**
	 * Retourne le code d'erreur HTTP s'il est défini, sinon retourne NULL.
	 * @return	int	Le code d'erreur HTTP (403, 404, 500, ...) ou NULL.
	 */
	public function getHttpError() {
		return ($this->_httpError);
	}
	/**
	 * Retourne le code de retour HTTP.
	 * @return	int	Le code de retour HTTP (403, 404, 500, ...).
	 */
	public function getHttpCode() {
		return ($this->_httpCode);
	}
	/**
	 * Retourne le nom de la vue.
	 * @return	string	Le nom de la vue.
	 */
	public function getView() {
		return ($this->_view);
	}
	/**
	 * Retourne le préfixe de template.
	 * @return	string	Le préfixe de template.
	 */
	public function getTemplatePrefix() {
		return ($this->_templatePrefix);
	}
	/**
	 * Retourne le nom du template.
	 * @return	string	Le nom du template.
	 */
	public function getTemplate() {
		return ($this->_template);
	}
	/**
	 * Retourne les données de template.
	 * @param	string	$key		(optionnel) La clé de la valeur à retourner dans l'ensemble des données.
	 *					Retourne l'ensemble du hash de données si ce paramètre n'est pas fourni.
	 * @param	string	$default	(optionnel) Valeur par défaut à retourner si la donnée demandée n'existe pas.
	 * @return	mixed	La donnée demandée, ou un hash contenant l'ensemble des données.
	 */
	public function getData($key=null, $default=null) {
		if (!empty($key)) {
			if (array_key_exists($key, $this->_data))
				return ($this->_data[$key]);
			return ($default);
		}
		return ($this->_data);
	}
}

