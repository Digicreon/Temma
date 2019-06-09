<?php

namespace Temma\Exceptions;

/**
 * Objet de gestion des exceptions de Temma.
 *
 * @author      Amaury Bouchard <amaury@amaury.net>
 * @package     Temma
 * @subpackage  Exceptions
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

