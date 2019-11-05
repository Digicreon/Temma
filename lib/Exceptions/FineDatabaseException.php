<?php

if (!class_exists('FineDatabaseException')) {

/**
 * Objet de gestion des exceptions de base de données.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007, FineMedia
 * @package	FineBase
 * @subpackage	Exception
 * @version	$Id: FineDatabaseException.php 641 2013-02-11 12:57:59Z abouchard $
 */
class FineDatabaseException extends Exception {
	/** Constante d'erreur fondamentale. */
	const FUNDAMENTAL = 0;
	/** Constante d'erreur de connexion. */
	const CONNECTION = 1;
	/** Constante d'erreur de requête. */
	const QUERY = 2;
}

} // class_exists

?>
