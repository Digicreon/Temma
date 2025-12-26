<?php

/**
 * Post
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2025, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_checkpost
 */

namespace Temma\Attributes\Checks;

/**
 * Attribute used to validate POST parameters.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Checks\Post as TµCheckPost;
 *
 * // check for a "name" parameter (string of at least 2 characters)
 * #[TµCheckPost(['name' => 'string; minLen: 2'])]
 *
 * // check for a "name" parameter (string of at least 2 characters),
 * // a "mail" parameter (email), and a "balance" parameter (optional float)
 * #[TµCheckPost([
 *     'name'     => 'string; minLen: 2',
 *     'mail'     => 'email',
 *     'balance?' => 'float'
 * ])]
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Post extends \Temma\Attributes\Check {
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
			type: 'POST',
			strict: $strict,
			redirect: $redirect,
			redirectVar: $redirectVar,
		);
	}
}
