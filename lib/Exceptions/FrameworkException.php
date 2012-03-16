<?php

namespace Temma\Exceptions;

/**
 * Objet de gestion des exceptions de Temma.
 *
 * @author      Amaury Bouchard <amaury.bouchard@finemedia.fr>
 * @copyright   © 2007-2011, Fine Media
 * @package     Temma
 * @subpackage  Exceptions
 * @version     $Id: FrameworkException.php 200 2011-04-19 15:26:39Z abouchard $
 */
class FrameworkException extends \Exception {
	/** Erreur de configuration. */
	const CONFIG = 0;
	/** Erreur de chargement de contrôleur. */
	const NO_CONTROLLER = 1;
	/** Erreur : action non disponible. */
	const NO_ACTION = 2;
	/** Erreur : vue non disponible. */
	const NO_VIEW = 3;
	/** Erreur : template non disponible. */
	const NO_TEMPLATE = 4;
}

?>
