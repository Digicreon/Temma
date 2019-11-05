<?php

namespace Temma\Exceptions;

/**
 * Objet de gestion des exceptions de Temma lors d'une erreur dans les DAO.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @package	Temma
 * @subpackage	Exceptions
 */
class DaoException extends \Exception {
	/** Critère de recherche incorrect. */
	const CRITERIA = 0;
	/** Mauvais champ. */
	const FIELD = 1;
	/** Mauvaise valeur. */
	const VALUE = 2;
}

