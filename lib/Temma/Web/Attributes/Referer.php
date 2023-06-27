<?php

/**
 * Referer
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2023, Amaury Bouchard
 */

namespace Temma\Web\Attributes;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Application as TµApplicationException;

/**
 * Attribute used to define access authorizations on a controller or an action, depending on the REFERER header.
 *
 * Examples:
 * - Authorize requests coming from the same site only, for all actions of the controller:
 * use \Temma\Web\Attributes\Refere as TµReferer;
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
 * #[TµReferer(domains: ['fubar.com', 'www.fubar.com'])]
 * - Authorize requests coming from any domain ending with '.fubar.com':
 * #[TµReferer(domainSuffix: '.fubar.com')]
 * - Authorize requests coming from any domain ending with '.fubar.com' or '.foobar.com':
 * #[TµReferer(domainSuffixes: ['.fubar.com', '.foobar.com'])]
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
 * #[TµReferer(uri: '/fu/bar/html')]
 * - Authorize requests coming from a '/fu.html' or '/bar.html' page:
 * #[TµReferer(uri: ['/fu.html', '/bar.html'])]
 * - Authorize requests coming from any page which URI starts with '/fu/':
 * #[TµReferer(uriPrefix: '/fu/')]
 * - Authorize requests coming from any page which URI starts with '/fu/' or '/bar/':
 * #[TµReferer(uriPrefixes: ['/fu/', '/bar/'])]
 * - Autorize requests coming from any page which URI ends with '/api.xml':
 * #[TµReferer(uriSuffix: '/api.xml')]
 * - Authorize requests coming from any page which URI ends with '/api.xml' or '/api.json':
 * #[TµReferer(uriSuffixes: ['/api.xml', '/api.json'])]
 * - Authorize requests coming from any page which URI matches the given regular expression:
 * #[TµReferer(uriRegex: '/^\/.*testApi.*\.xml$/')]
 * - Authorize requests coming from a page which URI is stored in the 'okURI' template variable:
 * #[TµReferer(uriVar: 'okURI')]
 * - Authorize requests coming from a page which URI is stored in the 'refererUri' key of
 *   the 'x-security' extended configuration:
 * #[TµReferer(uriConfig: true)]
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
 * - Redirect using the 'refererRedirect' key in the 'x-security' extended configuration:
 * #[TµReferer(redirectConfig: true)]
 *
 * @see	\Temma\Web\Controller
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Referer extends \Temma\Web\Attributes\Attribute {
	/**
	 * Constructor.
	 * @param	null|bool|string|array	$domain		(optional) Authorized domain.
	 * @param	null|string|array	$domains	(optional) Authorized domains.
	 * @param	null|string|array	$domainSuffix	(optional) Authorized domain suffix.
	 * @param	null|string|array	$domainSuffixes	(optional) Authorized domain suffixes.
	 * @param	?string			$domainRegex	(optional) Authorized domain regular expression.
	 * @param	?string			$domainVar	(optional) Name of the template variable which contains the authorized domain.
	 * @param	bool			$domainConfig	(optional) True to use the 'refererDomain' key of the 'x-security' extended configuration.
	 * @param	null|bool|string	$https		(optional) SSL configuration.
	 * @param	null|string|array	$uri		(optional) Authorized URI.
	 * @param	null|string|array	$uriPrefix	(optional) Authorized URI prefix.
	 * @param	null|string|array	$uriPrefixes	(optional) Authorized URI prefixes.
	 * @param	null|string|array	$uriSuffix	(optional) Authorized URI suffix.
	 * @param	null|string|array	$uriSuffixes	(optional) Authorized URI suffixes.
	 * @param	?string			$uriRegex	(optional) Authorized URI regular expression.
	 * @param	?string			$uriVar		(optional) Name of the template variable which contains the authorized URI.
	 * @param	bool			$uriConfig	(optional) True to use the 'refererUri' key of the 'x-security' extended configuration.
	 * @param	null|bool|string|array	$url		(optional) Authorized URL.
	 * @param	?string			$urlRegex	(optional) Authorized URL regular expression.
	 * @param	?string			$urlVar		(optional) Name of the template variable which contains the authorized URL.
	 * @param	bool			$urlConfig	(optional) True to use the 'refererUrl' key of the 'x-security' extended configuration.
	 * @param	?string			$redirect	(optional) Redirection URL used if the referer is not authorized.
	 * @param	?string			$redirectVar	(optional) Name of the template variable which contains the redirection URL.
	 * @param	bool			$redirectConfig	(optional) True to use the 'refererRedirect' key in the 'x-security' extended configuration.
	 * @throws	\Temma\Exceptions\Application	If the referer is not authorized.
	 * @throws	\Temma\Exceptions\FlowHalt	If the user is not authorized and a redirect URL has been given.
	 */
	public function __construct(null|bool|string|array $domain=null, null|string|array $domains=null,
	                            null|string|array $domainSuffix=null, null|string|array $domainSuffixes=null,
	                            ?string $domainRegex=null, ?string $domainVar=null, bool $domainConfig=false,
	                            null|bool|string $https=null, null|string|array $uri=null,
	                            null|string|array $uriPrefix=null, null|string|array $uriPrefixes=null,
	                            null|string|array $uriSuffix=null, null|string|array $uriSuffixes=null,
	                            ?string $uriRegex=null, ?string$uriVar=null, bool $uriConfig=false,
	                            null|bool|string|array $url=null, ?string $urlRegex=null,
	                            ?string $urlVar=null, bool $urlConfig=false,
	                            ?string $redirect=null, ?string $redirectVar=null, bool $redirectConfig=false) {
		try {
			// check referer
			if (!($_SERVER['HTTP_REFERER'] ?? false) ||
			    !($ref = parse_url($_SERVER['HTTP_REFERER']))) {
				TµLog::log('Temma/Web', 'WARN', "No HTTP referer.");
				throw new TµApplicationException("No HTTP referer.", TµApplicationException::UNAUTHORIZED);
			}
			// check HTTPS
			if ($https === true && $ref['scheme'] != 'https') {
				TµLog::log('Temma/Web', 'WARN', "Not HTTPS scheme.");
				throw new TµApplicationException("Hot HTTPS scheme.", TµApplicationException::UNAUTHORIZED);
			}
			if ($https === false && $ref['scheme'] != 'http') {
				TµLog::log('Temma/Web', 'WARN', "Not HTTP scheme.");
				throw new TµApplicationException("Hot HTTP scheme.", TµApplicationException::UNAUTHORIZED);
			}
			if ($https === 'same') {
				$secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') ? true : false;
				if (($ref['scheme'] == 'https' && !$secure) ||
				    ($ref['scheme'] == 'http' && $secure)) {
					TµLog::log('Temma/Web', 'WARN', "Referer and local schemes (HTTP/HTTPS) are not the same.");
					throw new TµApplicationException("Referer and local schemes (HTTP/HTTPS) are not the same.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check domain
			$checkDomains = [];
			if ($domain === true)
				$checkDomains[] = $_SERVER['SERVER_NAME'];
			if (is_string($domain))
				$checkDomains[] = $domain;
			else if (is_array($domain))
				$checkDomains = $domain;
			if (is_string($domains))
				$checkDomains[] = $domains;
			else if (is_array($domains))
				$checkDomains = array_merge($checkDomains, $domains);
			if ($domainVar) {
				if (is_string($this[$domainVar]))
					$checkDomains[] = $this[$domainVar];
				else if (is_array($this[$domainVar]))
					$checkDomains = array_merge($checkDomains, $this[$domainVar]);
			}
			if ($domainConfig) {
				$conf = $this->_getConfig()->xtra('security', 'refererDomain');
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
			$checkDomains = [];
			if (is_string($domainSuffix))
				$checkDomains[] = $domainSuffix;
			else if (is_array($domainSuffix))
				$checkDomains = $domainSuffix;
			if (is_string($domainSuffixes))
				$checkDomains[] = $domainSuffixes;
			else if (is_array($domainSuffixes))
				$checkDomains = array_merge($checkDomains, $domainSuffixes);
			if ($checkDomains) {
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
			if ($domainRegex && preg_match($domainRegex, $ref['host']) === false) {
				TµLog::log('Temma/Web', 'WARN', "Domain doesn't match regex.");
				throw new TµApplicationException("Domain doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
			// check URL
			$checkUrl = [];
			if (is_string($url))
				$checkUrl[] = $url;
			else if (is_array($url))
				$checkUrl = $url;
			if ($urlVar) {
				if (is_string($this[$urlVar]))
					$checkUrl[] = $this[$urlVar];
				else if (is_array($this[$urlVar]))
					$checkUrl = array_merge($checkUrl, $this[$urlVar]);
			}
			if ($urlConfig) {
				$conf = $this->_getConfig()->xtra('security', 'refererUrl');
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
			if ($urlRegex && preg_match($urlRegex, $_SERVER['HTTP_REFERER']) === false) {
				TµLog::log('Temma/Web', 'WARN', "Referer URL doesn't match regex.");
				throw new TµApplicationException("Referer URL doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
			// check URI
			$checkUri = [];
			if (is_string($uri))
				$checkUri[] = $uri;
			else if (is_array($uri))
				$checkUri = $uri;
			if ($uriVar) {
				if (is_string($this[$uriVar]))
					$checkUri[] = $this[$uriVar];
				else if (is_array($this[$uriVar]))
					$checkUri = array_merge($checkUri, $this[$uriVar]);
			}
			if ($uriConfig) {
				$conf = $this->_getConfig()->xtra('security', 'refererUri');
				if (is_string($conf))
					$checkUri[] = $conf;
				else if (is_array($conf))
					$checkUri = array_merge($checkUri, $conf);
			}
			if ($checkUri) {
				$found = false;
				foreach ($checkUri as $uri) {
					if ($ref['path'] == $uri) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching URI.");
					throw new TµApplicationException("No matching URI.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check URI prefix
			$checkUri = [];
			if (is_string($uriPrefix))
				$checkUri[] = $uriPrefix;
			else if (is_array($uriPrefix))
				$checkUri = $uriPrefix;
			if (is_string($uriPrefixes))
				$checkUri[] = $uriPrefixes;
			else if (is_array($uriPrefixes))
				$checkUri = array_merge($checkUri, $uriPrefixes);
			if ($checkUri) {
				$found = false;
				foreach ($checkUri as $uri) {
					if (str_starts_with($ref['path'], $uri)) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching URI prefix.");
					throw new TµApplicationException("No matching URI prefix.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check URI suffix
			$checkUri = [];
			if (is_string($uriSuffix))
				$checkUri[] = $uriSuffix;
			else if (is_array($uriSuffix))
				$checkUri = $uriSuffix;
			if (is_string($uriSuffixes))
				$checkUri[] = $uriSuffixes;
			else if (is_array($uriSuffixes))
				$checkUri = array_merge($checkUri, $uriSuffixes);
			if ($checkUri) {
				$found = false;
				foreach ($checkUri as $uri) {
					if (str_ends_with($ref['path'], $uri)) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					TµLog::log('Temma/Web', 'WARN', "No matching URI suffix.");
					throw new TµApplicationException("No matching URI suffix.", TµApplicationException::UNAUTHORIZED);
				}
			}
			// check URI regex
			if ($uriRegex && preg_match($uriRegex, $ref['path']) === false) {
				TµLog::log('Temma/Web', 'WARN', "URI doesn't match regex.");
				throw new TµApplicationException("URI doesn't match regex.", TµApplicationException::UNAUTHORIZED);
			}
		} catch (TµApplicationException $e) {
			// manage redirection URL
			if ($redirect) {
				TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$redirect'.");
				$this->_redirect($redirect);
				throw new \Temma\Exceptions\FlowHalt();
			}
			if ($redirectVar) {
				$url = $this[$redirectVar];
				if ($url) {
					TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
					$this->_redirect($url);
					throw new \Temma\Exceptions\FlowHalt();
				}
			}
			if ($redirectConfig) {
				$url = $this->_getConfig()->xtra('security', 'refererRedirect');
				if ($url) {
					TµLog::log('Temma/Web', 'DEBUG', "Redirecting to '$url'.");
					$this->_redirect($url);
					throw new \Temma\Exceptions\FlowHalt();
				}
			}
			// no redirection: throw the exception
			throw $e;
		}
	}
}

