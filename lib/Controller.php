<?php

namespace Temma;

/**
 * Objet de gestion des contrôleurs au sein d'applications MVC.
 *
 * @auhor	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 */
class Controller extends \Temma\BaseController {
	/**
	 * Constructeur.
	 * @param	array		$dataSources	Liste de sources de données.
	 * @param	FineSession	$session	Objet de gestion de la session.
	 * @param	TemmaConfig	$config		Objet contenant la configuration de l'application.
	 * @param	TemmaRequest	$request	Objet de la requête.
	 * @param	TemmaController	$executor	(optionnel) Objet contrôleur qui a instancié l'exécution de ce contrôleur.
	 */
	final public function __construct($dataSources, \FineSession $session=null, \Temma\Config $config, \Temma\Request $request=null, $executor=null) {
		parent::__construct($dataSources, $session, $config, $request, $executor);
	}
}

