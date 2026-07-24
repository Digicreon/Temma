<?php

/**
 * Loadable
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2019-2020, Amaury Bouchard
 */

namespace Temma\Base;

/**
 * Interface for objects that could be automatically loaded by Temma's dependency injection component (\Temma\Base\Loader).
 */
interface Loadable {
	/**
	 * Constructor.
	 * @param	\Temma\Base\Loader	$loader	The dependency injection object.
	 */
	public function __construct(\Temma\Base\Loader $loader);
}
