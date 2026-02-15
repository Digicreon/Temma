<?php

/**
 * DataFilter
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-datafilter
 */

namespace Temma\Utils;

/**
 * Object used to cleanup data using a contract declaration.
 * @deprecated	Use \Temma\Utils\Validation\DataFilter instead.
 * @see		\Temma\Utils\Validation\DataFilter
 */
#[\Deprecated(reason: "Use \\Temma\\Utils\\Validation\\DataFilter instead", since: "2.16.0")]
class DataFilter extends \Temma\Utils\Validation\DataFilter {
}

