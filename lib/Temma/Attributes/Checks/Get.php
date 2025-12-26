<?php

/**
 * Get
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_checkget
 */

namespace Temma\Attributes\Checks;

/**
 * Attribute used to validate GET parameters.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Checks\Get as TµCheckGet;
 *
 * // check for an "id" parameter (integer between 5 and 128)
 * #[TµCheckGet(['id' => 'int; min: 5; max: 128'])]
 *
 * // check for an "id" parameter (integer between 5 and 128)
 * // and a "name" parameter (optional string of at least 2 characters)
 * #[TµCheckGet([
 *     'id'    => 'int; min: 5; max: 128',
 *     'name?' => 'string; minLen: 2',
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Get extends \Temma\Attributes\Check {
	/**
	 * Constructor.
	 * @param	array	$parameters	Associative array of parameters to check.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @param	?string	$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string	$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		array $parameters,
		bool $strict=false,
		?string $redirect=null,
		?string $redirectVar=null,
	) {
		parent::__construct(
			parameters: $parameters,
			type: 'GET',
			strict: $strict,
			redirect: $redirect,
			redirectVar: $redirectVar
		);
	}
}
