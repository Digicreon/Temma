<?php

/**
 * Payload
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_checkpayload
 */

namespace Temma\Attributes\Checks;

/**
 * Attribute used to validate the POST payload (raw body).
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Checks\Payload as TµCheckPayload;
 *
 * // check for a raw POST payload (int greater or equal to 3)
 * #[TµCheckPayload('int; min: 3')]
 *
 * // check for a JSON payload
 * #[TµCheckPayload(json: [
 *     'type' => 'assoc',
 *     'keys' => [
 *         'int'   => 'int',
 *         'name?' => 'string',
 *     ]
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Payload extends \Temma\Attributes\Check {
	/**
	 * Constructor.
	 * @param	?string			$parameters	(optional) String for raw payload check.
	 * @param	bool			$strict		(optional) True to use strict matching. False by default.
	 * @param	null|string|array	$json		(optional) JSON contract to check the payload.
	 * @param	?string			$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		?string $parameters=null,
		bool $strict=false,
		null|string|array $json=null,
		?string $redirect=null,
		?string $redirectVar=null,
	) {
		parent::__construct(
			parameters: $parameters,
			type: 'POST',
			strict: $strict,
			json: $json,
			redirect: $redirect,
			redirectVar: $redirectVar,
		);
	}
}
