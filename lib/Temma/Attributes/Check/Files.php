<?php

/**
 * Files
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_check_files
 */

namespace Temma\Attributes\Check;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;
use \Temma\Exceptions\FlowHalt as TµFlowHalt;

/**
 * Attribute used to validate uploaded files.
 *
 * This attribute can be used on a controller class (applied to all methods) or on a specific action.
 *
 * Examples:
 * ```php
 * use \Temma\Attributes\Check\Files as TµCheckFiles;
 *
 * // check for a "avatar" file (image)
 * #[TµCheckFiles(['avatar' => 'binary; mime: image'])]
 *
 * // check for "doc" files (array of PDF)
 * #[TµCheckFiles(['doc' => 'binary; mime: application/pdf'])]
 * ```
 *
 * // definition of a redirection URL if validation fails
 * #[TµCheckFiles(['avatar' => 'binary; mime: image'], redirect: '/error')]
 *
 * // definition of a redirection URL (from a template variable) if validation fails
 * #[TµCheckFiles(['avatar' => 'binary; mime: image'], redirectVar: 'redirectUrl')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Files extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	array	$contract	Associative array of files to check.
	 * @param	bool	$strict		(optional) True to use strict matching. False by default.
	 * @param	?string	$redirect	(optional) Redirection URL used if the check fails.
	 * @param	?string	$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @param	?string	$flashVar	(optional) Name of the session flash variable which will contain the invalid GET variable in case of redirection.
	 */
	public function __construct(
		protected array $contract,
		protected bool $strict=false,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
		protected ?string $flashVar=null,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If the files are not valid.
	 * @throws	\Temma\Exceptions\FlowHalt	If the files are not valid and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		try {
			$this->_request->validateFiles($this->contract, $this->strict);
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                              // direct URL
			       $this[$this->redirectVar] ?:                    // template variable
			       $this->_config->xtra('security', 'redirect');   // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				if ($this->flashVar)
					$this->getSession()['__' . $this->flashVar] = $_FILES;
				$this->_redirect($url);
				throw new TµFlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}
