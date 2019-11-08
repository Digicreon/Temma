<?php

if (!class_exists('FineIOException')) {

/**
 * Objet de gestion des exceptions IO.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2007-2009, FineMedia
 * @package	FineBase
 * @subpackage	Exception
 * @version	$Id: FineIOException.php 641 2013-02-11 12:57:59Z abouchard $
 */
class FineIOException extends Exception {
	/** Constante d'erreur fondamentale. */
	const FUNDAMENTAL = 0;
	/** Constante d'erreur de fichier introuvable. */
	const NOT_FOUND = 1;
	/** Constante d'erreur de lecture. */
	const UNREADABLE = 2;
	/** Constante d'erreur d'écriture. */
	const UNWRITABLE = 3;
	/** Constante d'erreur de fichier mal formé. */
	const BAD_FORMAT = 4;
	/** Constante d'erreur de fichier impossible à locker. */
	const UNLOCKABLE = 5;
}

} // class_exists

?>
