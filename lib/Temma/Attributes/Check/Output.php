<?php

/**
 * Output
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_check_get
 */

namespace Temma\Attributes\Check;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate output data.
 *
 * This attribute can be used on a controller class (applied to all methods) or on a specific action.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check\Output as TµCheckOutput;
 *
 * // check for an "id" template variable (integer between 5 and 128, strictly validated)
 * #[TµCheckOutput(['=id' => 'int; min: 5; max: 128'])]
 *
 * // check for a "name" variable (string), a "mail" variable (email), and an optional "balance" variable (float)
 * #[TµCheckOutput([
 *     'name'     => 'string',
 *     'mail'     => 'email',
 *     'balance?' => 'float'
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Get extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	string|array	$contract	Name of the configured contract, or name of the validation object, or
	 *						associative array of parameters to check.
	 */
	public function __construct(protected string|array $contract) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 */
	public function apply(\Reflector $context) : void {
		$this->_response->setValidationContract($this->contract);
	}
}
