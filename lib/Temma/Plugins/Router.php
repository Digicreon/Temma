<?php

/**
 * Router
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;

/**
 * Plugin used extends the Temma routing feature.
 *
 * @see		https://www.temma.net/fr/documentation/routage
 */
class Router extends \Temma\Web\Plugin {
	/** Routes multi-dimension array. */
	private $_routes = null;

	/**
	 * Preplugin method. Checks if the requested page is managed by the router.
	 * @return	mixed	Always EXEC_FORWARD.
	 */
	public function preplugin() {
		TµLog::log('Temma/Web', 'INFO', "Router plugin started.");
		$this->_extractRoutes();
		$this->_searchRoute();
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Search the route for the current URL.
	 * @param	?string	$method	(optional) HTTP method to search for. If null, try to
	 *				use the current request's method, or the default one.
	 * @return	bool	True if a route was found, false otherwise.
	 */
	private function _searchRoute(?string $method=null) : bool {
		$currentMethod = $_SERVER['REQUEST_METHOD'];
		// if no method was given, try with the current request's method;
		// if it doesn't work, try with the catchall
		if (!$method) {
			$res = $this->_searchRoute($_SERVER['REQUEST_METHOD']);
			if ($res)
				return (true);
			$res = $this->_searchRoute('*');
			return ($res);
		}
		// a method was given
		// manage the root URL ('/')
		if (!$this['URL'] || $this['URL'] == '/') {
			if (!isset($this->_routes[$method]['exec']))
				return (false);
			$this->_defineRoute($this->_routes[$method]['exec'], null);
		}
		if (!(
		// chunk the URL
		$chunkedUrl = explode('/', trim($this['URL'], '/'));
		
	}
	/** Generate the routes array from the configuration. */
	private function _extractRoutes() : void {
		// get the router configuration
		$conf = $this->_loader->config->xtra('router');
		/*
		 * Create an array of routes.
		 * If the input is:
		 * {
		 *     "GET:/aa/bb":                  "AA::bb()",
		 *     "GET:/aa/bb/cc":               "AA::cc()",
		 *     "GET:/aa/bb/dd":               "AA::dd()",
		 *     "GET:/aa/bb/[id:string]":      "AA::bb($id)",
		 *     "GET;/aa/bb/[xx:string]/zz":   "AA::zz($xx)",
		 *     "GET;/aa/bb/[xx:enum:a,b]/zz": "AA::xx($xx)",
		 *     "GET:/aa/bb/[xx:enum:z,y]/zz": "AA::yy($xx)"
		 * }
		 * It will create an array like that!
		 * [
		 *     'GET' => [
		 *         'sub' => [
		 *             'aa' => [
		 *                 'sub' => [
		 *                     'bb' => [
		 *                         'exec' => "AA::bb()",
		 *                         'sub' => [
		 *                             'cc' => [
		 *                                 'exec' => 'AA::cc()"
		 *                             ],
		 *                             'dd' => [
		 *                                 'exec' => 'AA::dd()"
		 *                             ]
		 *                         ],
		 *                         'param' => [
		 *                             'string' => [
		 *                                 [
		 *                                     'name' => 'id'
		 *                                     'exec' => 'AA::bb($id)'
		 *                                 ],
		 *                                 [
		 *                                     'name' => 'xx',
		 *                                     'sub' => [
		 *                                         'zz' => [
		 *                                             'exec' => 'AA::zz($xx)'
		 *                                         ]
		 *                                     ]
		 *                                 ]
		 *                             ],
		 *                             'enum' => [
		 *                                 [
		 *                                     'name' => 'xx',
		 *                                     'params" => 'a,b',
		 *                                     'sub' => [
		 *                                         'zz' => [
		 *                                             'exec' => 'AA::xx($xx)'
		 *                                         ]
		 *                                     ]
		 *                                 ],
		 *                                 [
		 *                                     'name' => 'xx',
		 *                                     'params' => 'z,y',
		 *                                     'sub' => [
		 *                                         'zz' => [
		 *                                             'exec' => 'AA::yy($xx)'
		 *                                         ]
		 *                                     ]
		 *                                 ]
		 *                             ]
		 *                         ]
		 *                     ]
		 *                 ]
		 *             ]
		 *         ]
		 *     ]
		 * ]
		 */
		$this->_routes = [];
		// loop on each configuration line
		foreach ($conf as $key => $value) {
			// extract the HTTP method
			$pos = strpos($key, ':');
			$method = $pos ? mb_substr($key, 0, $pos) : '*';
			// extract the URL and chunk it
			$url = mb_substr($key, $pos);
			$url = explode('/', trim($url, '/'));
			// add the route
			$routes[$method] = $routes[$method] ?? [];
			if (empty($url)) {
				$routes[$method]['exec'] = $value;
				continue;
			}
			$ptr = &$this->_routes[$method];
			$nbrChunks = count($url);
			$currentChunk = 0;
			foreach ($url as $chunk) {
				$currentChunk++;
				if ($chunk[0] == '[') {
					// it's a named parameter
					$ptr['param'] = $ptr['param'] ?? [];
					// extract data
					$data = explode(':', trim($chunk, '[]'));
					if (count($data) < 2 || !in_array($data[1], ['int', 'float', 'string', 'enum'])) {
						TµLog::log('Temma/Web', 'WARN', "Router: Bad typed parameter '$chunk'.");
						continue;
					}
					[$name, $type] = $data;
					$params = $data[2] ?? null;
					// store data
					$ptr['param'][$type] = $ptr['param'][$type] ?? [];
					$array = [
						'name '=> $name,
					];
					if ($params)
						$array['params'] = $params;
					$ptr['param'][$type][] = $array;
					$ptr = &$array;
				} else {
					// it's a sub chunk
					$ptr['sub'] = $ptr['sub'] ?? [];
					// store data
					$ptr['sub'][$chunk] = $ptr['sub'][$chunk] ?? [];
					$ptr['sub'][$chunk] = [];
					$ptr = &$ptr['sub'][$chunk];
				}
				if ($currentChunk == $nbrChunks)
					$ptr['exec'] = $value;
			}
		}
	}
}

