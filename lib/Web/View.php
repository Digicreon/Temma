<?php

namespace Temma;

/**
 * Objet de gestion des vues au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
abstract class View {
	/** Liste de connexion à des sources de données. */
	protected $_dataSources = null;
	/** Configuration de l'application. */
	protected $_config = null;
	/** Objet de réponse. */
	protected $_response = null;
	/** Nom de la clé de configuration pour les headers. */
	protected $_cacheKey = null;

	/**
	 * Constructeur.
	 * @param	array		$dataSources	Liste de connexions à des sources de données.
	 * @param	\Temma\Config	$config		Objet contenant la configuration du projet.
	 * @param	\Temma\Response	$response	Objet de réponse.
	 */
	public function __construct($dataSources, \Temma\Config $config, \Temma\Response $response=null) {
		$this->_dataSources = $dataSources;
		$this->_config = $config;
		$this->_response = $response;
	}
	/** Destructeur. */
	public function __destruct() {
	}
	/**
	 * Indique si cette vue utilise des templates ou non.
	 * Les vues qui n'ont pas besoin de template n'ont pas besoin de redéfinir cette méthode.
	 * @return	bool	True si cette vue utilise des templates.
	 */
	public function useTemplates() {
		return (false);
	}
	/**
	 * Fonction d'affectation de template.
	 * Les vues qui n'ont pas besoin de template n'ont pas besoin de redéfinir cette méthode.
	 * @param	string|array	$path		Chemin(s) de recherche des templates.
	 * @param	string		$template	Nom du template à utiliser.
	 * @return	bool		True si tout s'est bien passé.
	 */
	public function setTemplate($path, $template) {
		return (true);
	}
	/** Fonction d'initialisation. */
	public function init() {
	}
	/**
	 * Ecrit les headers HTTP sur la sortie standard si nécessaire.
	 * Par défaut, envoie un header HTML avec désactivation du cache.
	 * @param	array	$headers	(optionnel) Tableau de headers à envoyer par défaut.
	 */
	public function sendHeaders($headers=null) {
		$httpCode = $this->_response->getHttpCode();
		if ($httpCode != 200)
			http_response_code($httpCode);
		if (is_null($headers)) {
			$headers = array(
				'Content-Type'	=> 'text/html; charset=UTF-8',
				'Cache-Control'	=> 'no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0',
				'Expires'	=> 'Mon, 26 Jul 1997 05:00:00 GMT',
				'Pragma'	=> 'no-cache'
			);
		}
		$headersDefault = $this->_config->xtra('headers', 'default');
		if (is_array($headersDefault)) {
			$headers = array_merge($headers, $headersDefault);
		}
		if (is_array($headers)) {
			foreach ($headers as $headerName => $headerValue) {
				header("$headerName: $headerValue");
			}
		}
	}
	/** Ecrit le corps du document sur la sortie standard. */
	abstract public function sendBody();
}

