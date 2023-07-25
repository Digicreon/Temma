<?php

/**
 * Router
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2020-2023, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;
use \Temma\Exceptions\Framework AS TµFrameworkException;

/**
 * Plugin used extends the Temma routing feature.
 *
 * @see		https://www.temma.net/fr/documentation/routage
 */
class Router extends \Temma\Web\Plugin {
	/** Routes multi-dimension array. */
	private array $_routes = [];
	/** Execution informations. */
	private string|array $_exec;
	/** List of stacked parameters. */
	private array $_parameters;

	/**
	 * Preplugin method. Checks if the requested page is managed by the router.
	 * @return	mixed	Always EXEC_FORWARD.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not correct.
	 */
	public function preplugin() {
		TµLog::log('Temma/Web', 'INFO', "Router plugin started.");
		// read the configuration
		$this->_extractRoutes();
		// get method
		$currentMethod = $this->_loader->request->getMethod();
		// get URL chunks
		$chunks = [];
		if (($ctrl = $this->_loader->request->getController())) {
			$chunks[] = $ctrl;
			if (($action = $this->_loader->request->getAction())) {
				$chunks[] = $action;
				$chunks = array_merge($chunks, $this->_loader->request->getParams());
			}
		}
		// process, searching for the actual method or the catchall
		if (!(isset($this->_routes[$currentMethod]) && $this->_searchRoute($this->_routes[$currentMethod], $chunks)) &&
		    !(isset($this->_routes['*']) && $this->_searchRoute($this->_routes['*'], $chunks))) {
			TµLog::log('Temma/Web', 'DEBUG', "Router: no route found.");
			return (self::EXEC_FORWARD);
		}
		$returnValue = self::EXEC_FORWARD;
		// manage plugins
		if (is_array($this->_exec)) {
			$plugins = $this->_loader->config->plugins;
			// add new plugins
			if (isset($this->_exec['_pre'])) {
				// remove this plugin from the list of preplugins
				$offset = array_search('\\' . self::class, $plugins['_pre']);
				array_splice($plugins['_pre'], 0, $offset + 1);
				// add new preplugins
				if (!is_array($this->_exec['_pre']))
					$this->_exec['_pre'] = [$this->_exec['_pre']];
				$plugins['_pre'] ??= [];
				$plugins['_pre'] = array_merge($plugins['_pre'], $this->_exec['_pre']);
				$returnValue = self::EXEC_RESTART;
			}
			if (isset($this->_exec['_post'])) {
				if (!is_array($this->_exec['_post']))
					$this->_exec['_post'] = [$this->_exec['_post']];
				$plugins['_post'] ??= [];
				$plugins['_post'] = array_merge($plugins['_post'], $this->_exec['_post']);
			}
			$this->_loader->config->plugins = $plugins;
			if (!is_string($this->_exec['action'] ?? null)) {
				TµLog::log('Temma/Web', 'WARN', "Router: Bad configuration (missing 'action' key).");
				throw new TµFrameworkException("Router: Bad configuration (missing 'action' key).", TµFrameworkException::CONFIG);
			}
			$this->_exec = $this->_exec['action'];
		}
		// extract controller and action
		$res = explode('::', $this->_exec);
		if (count($res) != 2) {
			TµLog::log('Temma/Web', 'WARN', "Router: Bad configuration (missing '::' separator between object and method).");
			throw new TµFrameworkException("Router: Bad configuration (missing '::' separator between object and method).", TµFrameworkException::CONFIG);
		}
		[$controller, $method] = $res;
		$this['CONTROLLER'] = $controller;
		$this->_loader->request->setController($controller);
		if (($pos = mb_strpos($method, '(')) === false) {
			$action = $method;
			$params = [];
		} else {
			$action = mb_substr($method, 0, $pos);
			$params = mb_substr($method, $pos + 1, -1);
			$params = explode(',', $params);
			foreach ($params as &$p) {
				$p = trim($p);
				if ($p[0] == '$') {
					$p = mb_substr($p, 1);
					$p = $this->_parameters[$p] ?? null;
				} else if ($p[0] == '\'' || $p[0] == '"') {
					$p = mb_substr($p, 1, -1);
				}
			}
		}
		$this['ACTION'] = $action;
		$this->_loader->request->setAction($action);
		$this->_loader->request->setParams($params);
		// define URL
		$url = "/$controller/$action";
		if ($params)
			$url .= '/' . implode('/', $params);
		$this['URL'] = $url;
		return ($returnValue);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Search the route for the current URL.
	 * @param	array	$route		Tree of route definition.
	 * @param	array	$chunks		List of URL chunks.
	 * @param	array	$parameters	(optional) List of stacked parameters.
	 * @return	bool	True if a route was found, false otherwise.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not correct.
	 */
	private function _searchRoute(array $route, array $chunks, array $parameters=[]) : bool {
		if (!$chunks) {
			if (!($route['exec'] ?? null)) {
				return (false);
			}
			$this->_parameters = $parameters;
			$this->_exec = $route['exec'];
			return (true);
		}
		$chunk = array_shift($chunks);
		// is there a simple sub element?
		if (isset($route['sub'][$chunk])) {
			return $this->_searchRoute($route['sub'][$chunk], $chunks, $parameters);
		}
		// search a matching enum
		if (isset($route['param']['enum'])) {
			foreach ($route['param']['enum'] as $enum) {
				if (isset($enum['values'][$chunk])) {
					$params = $parameters;
					$params[$enum['name']] = $chunk;
					if ($this->_searchRoute($enum, $chunks, $params)) {
						return (true);
					}
				}
			}
		}
		// search a matching int
		if (ctype_digit($chunk) && isset($route['param']['int'])) {
			foreach ($route['param']['int'] as $int) {
				$params = $parameters;
				$params[$int['name']] = $chunk;
				if ($this->_searchRoute($int, $chunks, $params)) {
					return (true);
				}
			}
		}
		// search a matching float
		if (is_numeric($chunk) && isset($route['param']['float'])) {
			foreach ($route['param']['float'] as $float) {
				$params = $parameters;
				$params[$float['name']] = $chunk;
				if ($this->_searchRoute($float, $chunks, $params)) {
					return (true);
				}
			}
		}
		// search a matching string
		if (isset($route['param']['string'])) {
			foreach ($route['param']['string'] as $string) {
				$params = $parameters;
				$params[$string['name']] = $chunk;
				if ($this->_searchRoute($string, $chunks, $params)) {
					return (true);
				}
			}
		}
		return (false);
	}
	/**
	 * Generate the routes array from the configuration.
	 * @throws	\Temma\Exceptions\Framework	If the configuration is not correct.
	 */
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
		// loop on each configuration line
		foreach ($conf as $key => $value) {
			// extract the HTTP method
			$pos = mb_strpos($key, ':');
			$method = ($pos !== false) ? mb_substr($key, 0, $pos) : '*';
			// extract the URL and chunk it
			$url = mb_substr($key, $pos + 1);
			$urlChunks = array_filter(explode('/', trim($url, '/')));
			// add the route
			$this->_routes[$method] ??= [];
			if (!$urlChunks) {
				$this->_routes[$method]['exec'] = $value;
				continue;
			}
			$oldPtr = &$ptr;
			$ptr = &$this->_routes[$method];
			while (($chunk = array_shift($urlChunks))) {
				if ($chunk[0] == '[') {
					// it's a named parameter
					$ptr['param'] ??= [];
					// extract data
					$data = explode(':', trim($chunk, '[]'));
					if (count($data) < 2 || !in_array($data[1], ['int', 'float', 'string', 'enum'])) {
						TµLog::log('Temma/Web', 'WARN', "Router: Bad typed parameter '$chunk'.");
						throw new TµFrameworkException("Router: Bad typed parameter '$chunk'.", TµFrameworkException::CONFIG);
					}
					[$name, $type] = $data;
					$params = $data[2] ?? null;
					$values = null;
					if ($type == 'enum' && $params) {
						$values = array_map('trim', explode(',', $params));
						$values = array_fill_keys($values, true);
					}
					// store data
					$ptr['param'][$type] ??= [];
					unset($array); // break the link to the previous $array variable, which was used by address
					$array = [
						'name' => $name,
					];
					if ($params)
						$array['params'] = $params;
					if ($values)
						$array['values'] = $values;
					$ptr['param'][$type][] = &$array;
					$ptr = &$array;
				} else {
					// it's a sub chunk
					$ptr['sub'] ??= [];
					// store data
					$ptr['sub'][$chunk] ??= [];
					$ptr = &$ptr['sub'][$chunk];
				}
			}
			// add the object/method to call
			$ptr['exec'] = is_string($value) ? trim($value) : $value;
		}
	}
}

