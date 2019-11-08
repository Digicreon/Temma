<?php

if (!class_exists('FineApplicationException')) {

/**
 * Objet de gestion des exceptions applicatives.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2010, FineMedia
 * @package	FineBase
 * @subpackage	Exception
 * @version	$Id: FineApplicationException.php 641 2013-02-11 12:57:59Z abouchard $
 */
class FineApplicationException extends Exception {
	/** Constante d'erreur inconnue. */
	const UNKNOWN = -1;
	/** Constante d'erreur d'appel à une API. */
	const API = 0;
	/** Constante d'erreur système. */
	const SYSTEM = 1;
	/** Constante d'erreur d'authentification. */
	const AUTHENTICATION = 2;
	/** Constante d'erreur d'autorisation. */
	const UNAUTHORIZED = 3;
	/** Constante d'erreur de dépendances. */
	const DEPENDENCY = 4;
}

} // class_exists

?>
