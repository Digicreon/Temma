<?php

namespace Temma\Exceptions;

/**
 * Objet de gestion des exceptions de Temma lors d'une erreur dans les DAO.
 *
 * @author	Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright	© 2010-2011, FineMedia
 * @package	Temma
 * @subpackage	Exceptions
 * @version	$Id$
 */
class DaoException extends \Exception {
	/** Critère de recherche incorrect. */
	const CRITERIA = 0;
	/** Mauvais champ. */
	const FIELD = 1;
	/** Mauvaise valeur. */
	const VALUE = 2;
}

?>
