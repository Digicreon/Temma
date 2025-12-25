<?php

/**
 * Referer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 * @link	https://www.temma.net/documentation/helper-attr_referer
 */

namespace Temma\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define access authorizations on a controller or an action, depending on the REFERER header.
 *
 * Examples:
 * - Authorize requests coming from the same site only, for all actions of the controller:
 * use \Temma\Attributes\Referer as TµReferer;
 * #[TµReferer]
 * class SomeController extends \Temma\Web\Controller {
 *     // ...
 * }
 *
 * - Authorize requests with a referer only:
 * #[TµReferer]
 * - Authorize requests coming from the same domain:
 * #[TµReferer(true)]
 * - Authorize requests coming from the 'fubar.com' domain:
 * #[TµReferer('fubar.com')]
 * - Authorize requests coming from the 'fubar.com' and 'www.fubar.com' domains:
 * #[TµReferer(['fubar.com', 'www.fubar.com'])]
 * - Authorize requests coming from any domain ending with '.fubar.com':
 * #[TµReferer(domainSuffix: '.fubar.com')]
 * - Authorize requests coming from any domain ending with '.fubar.com' or '.foobar.com':
 * #[TµReferer(domainSuffix: ['.fubar.com', '.foobar.com'])]
 * - Authorize requests coming from any domain matching the specified regular expression:
 * #[TµReferer(domainRegex: '/^test\d?.fubar.(com|net)$/')]
 * - Authorize requests coming from the domain stored in the 'okDomain' template variable:
 * #[TµReferer(domainVar: 'okDomain')]
 * - Authorize requests coming from the domain defined in the 'refererDomain' key of
 *   the 'x-security' extended configuration:
 * #[TµReferer(domainConfig: true)]
 *
 * - Authorize requests coming from an http or https website:
 * #[TµReferer(https: null)]
 * - Authorize requests coming from an http website only:
 * #[TµReferer(https: false)]
 * - Authorize requests coming from an https website only:
 * #[TµReferer(https: true)]
 * - Authorize requests coming from a website with the same http status than the local website:
 * #[TµReferer(https: 'same')]
 *
 * - Authorize requests coming from a '/fu/bar.html' page:
 * #[TµReferer(path: '/fu/bar/html')]
 * - Authorize requests coming from a '/fu.html' or '/bar.html' page:
 * #[TµReferer(path: ['/fu.html', '/bar.html'])]
 * - Authorize requests coming from any page which path starts with '/fu/':
 * #[TµReferer(pathPrefix: '/fu/')]
 * - Authorize requests coming from any page which path starts with '/fu/' or '/bar/':
 * #[TµReferer(pathPrefix: ['/fu/', '/bar/'])]
 * - Autorize requests coming from any page which path ends with '/api.xml':
 * #[TµReferer(pathSuffix: '/api.xml')]
 * - Authorize requests coming from any page which path ends with '/api.xml' or '/api.json':
 * #[TµReferer(pathSuffix: ['/api.xml', '/api.json'])]
 * - Authorize requests coming from any page which path matches the given regular expression:
 * #[TµReferer(pathRegex: '/^\/.*testApi.*\.xml$/')]
 * - Authorize requests coming from a page which path is stored in the 'okPath' template variable:
 * #[TµReferer(pathVar: 'okPath')]
 * - Authorize requests coming from a page which path is stored in the 'refererPath' key of
 *   the 'x-security' extended configuration:
 * #[TµReferer(pathConfig: true)]
 *
 * - Authorize requests coming from 'https://www.fubar.com/some/page.html':
 * #[TµReferer(url: 'https://www.fubar.com/some/page.html')]
 * - Authorize requests coming from 'https://fu.com/bar' or 'https://bar.com/fu':
 * #[TµReferer(url: ['https://fu.com/bar', 'https://bar.com/fu'])]
 * - Authorize requests coming from an URL matching the given regular expression:
 * #[TµRequest(urlRegex: '/^.*$/')]
 * - Authorize requests coming from the URL stored in the 'okURL' template variable:
 * #[TµReferer(urlVar: 'okURL')]
 * - Authorize requests coming from the URL stored in the 'refererUrl' key of the
 *   'x-security' extended configuration:
 * #[TµReferer(urlConfig: true)]
 *
 * - Redirect if there is no referer:
 * #[TµReferer(redirect: '/login')]
 * - Redirect using the URL defined in the 'redirRef' template variable:
 * #[TµReferer(redirectVar: 'redirRef')]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Referer extends \Temma\Web\Attribute {
	/**
	 * Constructor.
	 * @param	null|bool|string|array	$domain		(optional) Authorized domain or list of authorized domains.
	 * @param	null|string|array	$domainSuffix	(optional) Authorized domain suffix or list of suffixes.
	 * @param	?string			$domainRegex	(optional) Authorized domain regular expression.
	 * @param	?string			$domainVar	(optional) Name of the template variable which contains the authorized domain.
	 * @param	bool			$domainConfig	(optional) True to use the 'refererDomain' key of the 'x-security' extended configuration.
	 * @param	null|bool|string	$https		(optional) SSL configuration.
	 * @param	null|string|array	$path		(optional) Authorized path.
	 * @param	null|string|array	$pathPrefix	(optional) Authorized path prefix or list of prefixes.
	 * @param	null|string|array	$pathSuffix	(optional) Authorized path suffix or list of suffixes.
	 * @param	?string			$pathRegex	(optional) Authorized path regular expression.
	 * @param	?string			$pathVar	(optional) Name of the template variable which contains the authorized path.
	 * @param	bool			$pathConfig	(optional) True to use the 'refererPath' key of the 'x-security' extended configuration.
	 * @param	null|bool|string|array	$url		(optional) Authorized URL.
	 * @param	?string			$urlRegex	(optional) Authorized URL regular expression.
	 * @param	?string			$urlVar		(optional) Name of the template variable which contains the authorized URL.
	 * @param	bool			$urlConfig	(optional) True to use the 'refererUrl' key of the 'x-security' extended configuration.
	 * @param	?string			$redirect	(optional) Redirection URL used if the referer is not authorized.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 */
	public function __construct(
		protected null|bool|string|array $domain=null,
		protected null|string|array $domainSuffix=null,
		protected ?string $domainRegex=null,
		protected ?string $domainVar=null,
		protected  bool $domainConfig=false,
		protected null|bool|string $https=null,
		protected null|string|array $path=null,
		protected null|string|array $pathPrefix=null,
		protected  null|string|array $pathSuffix=null,
		protected ?string $pathRegex=null,
		protected ?string$pathVar=null,
		protected bool $pathConfig=false,
		protected null|bool|string|array $url=null,
		protected ?string $urlRegex=null,
		protected ?string $urlVar=null,
		protected bool $urlConfig=false,
		protected ?string $redirect=null,
		protected ?string $redirectVar=null,
	) {
	}
	/**
	 * Processing of the attribute.
	 * @param	\Reflector	$context	Context of the element on which the attribute is applied
	 *						(ReflectionClass, ReflectionMethod or ReflectionFunction).
	 * @throws	\Temma\Exceptions\Application	If the referer is not authorized.
	 * @throws	\Temma\Exceptions\FlowHalt	If the user is not authorized and a redirect URL has been given.
	 */
	public function apply(\Reflector $context) : void {
		try {
			// check referer
			if (!($_SERVER['HTTP_REFERER'] ?? false) ||
			    !($ref = parse_url($_SERVER['HTTP_REFERER']))) {
				TµLog::log('Temma/Web', 'WARN', "No HTTP referer.");
				throw new TµApplicationException("No HTTP referer.", TµApplicationException::UNAUTHORIZED);
			}
			// check HTTPS
			if ($this->https === true && $ref['scheme'] != 'https') {
				TµLog::log('Temma/Web', 'WARN', "Not HTTPS scheme.");
				throw new TµApplicationException("Hot HTTPS scheme.", TµApplicationException::UNAUTHORIZED);
			}
			if ($this->https === false && $ref['scheme'] != 'http') {
				TµLog::log('Temma/Web', 'WARN', "Not HTTP scheme.");
				throw new TµApplicationException("Hot HTTP scheme.", TµApplicationException::UNAUTHORIZED);
			}
			if ($this->https === 'same') {
				$secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? true : false;
				if (($ref['scheme'] == 'https' && !$secure) ||
				    ($ref['scheme'] == 'http' && $secure)) {
					TµLog::log('Temma/Web', 'WARN', "Referer and local schemes (HTTP/HTTPS) are not the same.");
					throw new TµApplicationException("Referer and local schemes (HTTP/HTTPS) are not the same.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check domain
			$checkDomains = [];
			if ($this->domain === true)
				$checkDomains[] = $_SERVER['SERVER_NAME'];
			if (is_string($this->domain))
				$checkDomains[] = $this->domain;
			else if (is_array($this->domain))
				$checkDomains = $this->domain;
			if ($this->domainVar) {
				if (is_string($this[$this->domainVar]))
					$checkDomains[] = $this[$this->domainVar];
				else if (is_array($this[$this->domainVar]))
					$checkDomains = array_merge($checkDomains, $this[$this->domainVar]);
			}
			if ($this->domainConfig) {
				$conf = $this->_config->xtra('security', 'refererDomain');
			if (is_string($conf))
					$checkDomains[] = $conf;
				else if (is_array($conf))
					$checkDomains = array_merge($checkDomains, $conf);
			}
			if ($checkDomains) {
				$found = false;
				foreach ($checkDomains as $domain) {
					if ($ref['host'] == $domain) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching domain.");
					throw new TµApplicationException("No matching domain.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check domain suffix
			if ($this->domainSuffix) {
				$checkDomains = is_array($this->domainSuffix) ? $this->domainSuffix : [$this->domainSuffix];
				$found = false;
				foreach ($checkDomains as $domain) {
					if (str_ends_with($ref['host'], $domain)) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching domain suffix.");
					throw new TµApplicationException("No matching domain suffix.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check domain regex
			if ($this->domainRegex && preg_match($this->domainRegex, $ref['host']) === false) {
				TµLog::log('Temma/Web', 'WARN', "Domain doesn't match regex.");
				throw new TµApplicationException("Domain doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
			// check URL
			$checkUrl = [];
			if (is_string($this->url))
				$checkUrl[] = $this->url;
			else if (is_array($this->url))
				$checkUrl = $this->url;
			if ($this->urlVar) {
				if (is_string($this[$this->urlVar]))
					$checkUrl[] = $this[$this->urlVar];
				else if (is_array($this[$this->urlVar]))
					$checkUrl = array_merge($checkUrl, $this[$this->urlVar]);
			}
			if ($this->urlConfig) {
				$conf = $this->_config->xtra('security', 'refererUrl');
				if (is_string($conf))
					$checkUrl[] = $conf;
				else if (is_array($conf))
					$checkUrl = array_merge($checkUrl, $conf);
			}
			if ($checkUrl) {
				$found = false;
				foreach ($checkUrl as $url) {
					if ($_SERVER['HTTP_REFERER'] == $url) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching URL.");
					throw new TµApplicationException("No matching URL.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check URL regex
			if ($this->urlRegex && preg_match($this->urlRegex, $_SERVER['HTTP_REFERER']) === false) {
				TµLog::log('Temma/Web', 'WARN', "Referer URL doesn't match regex.");
				throw new TµApplicationException("Referer URL doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
			// check path
			$checkPath = [];
			if (is_string($this->path))
				$checkPath[] = $this->path;
			else if (is_array($this->path))
				$checkPath = $this->path;
			if ($this->pathVar) {
				if (is_string($this[$this->pathVar]))
					$checkPath[] = $this[$this->pathVar];
				else if (is_array($this[$this->pathVar]))
					$checkPath = array_merge($checkPath, $this[$this->pathVar]);
			}
			if ($this->pathConfig) {
				$conf = $this->_config->xtra('security', 'refererPath');
				if (is_string($conf))
					$checkPath[] = $conf;
				else if (is_array($conf))
					$checkPath = array_merge($checkPath, $conf);
			}
			if ($checkPath) {
				$found = false;
				foreach ($checkPath as $path) {
					if ($ref['path'] == $path) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching Path.");
					throw new TµApplicationException("No matching Path.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check path prefix
			if ($this->pathPrefix) {
				$checkPath = is_array($this->pathPrefix) ? $this->pathPrefix : [$this->pathPrefix];
				$found = false;
				foreach ($checkPath as $path) {
					if (str_starts_with($ref['path'], $path)) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching path prefix.");
					throw new TµApplicationException("No matching path prefix.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check path suffix
			if ($this->pathSuffix) {
				$checkPath = is_array($this->pathSuffix) ? $this->pathSuffix : [$this->pathSuffix];
				$found = false;
				foreach ($checkPath as $path) {
					if (str_ends_with($ref['path'], $path)) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching path suffix.");
					throw new TµApplicationException("No matching path suffix.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check path regex
			if ($this->pathRegex && preg_match($this->pathRegex, $ref['path']) === false) {
				TµLog::log('Temma/Web', 'WARN', "Path doesn't match regex.");
				throw new TµApplicationException("Path doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
		} catch (TµApplicationException $e) {
			// manage redirection URL
			$url = $this->redirect ?:                                     // direct URL
			       $this[$this->redirectVar] ?:                           // template variable
			       $this->_config->xtra('security', 'refererRedirect') ?: // specific configuration
			       $this->_config->xtra('security', 'redirect');          // general configuration
			if ($url) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
				$this->_redirect($url);
				throw new \Temma\Exceptions\FlowHalt();
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}

