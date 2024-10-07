<?php

/**
 * Debug
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

namespace Temma\Plugins;

declare(ticks=1);

use \Temma\Base\Log as TµLog;
use \Temma\Utils\Text as TµText;

/**
 * Plugin used to set debug toolbar.
 */
class Debug extends \Temma\Web\Plugin {
	/** Constant: color close to black. */
	const COLOR_BLACK = '#334';
	/** Constant : dark color. */
	const COLOR_DARK = '#0069D9';
	/** Constant : medium color. */
	const COLOR_MEDIUM = '#107EF4';
	/** Constant : light color. */
	const COLOR_LIGHT = '#DCE9F7';
	/** Constant : color close to white. */
	const COLOR_WHITE = '#F8F8FF';
	/** Constant : light contrast color. */
	const COLOR_CONTRAST_LIGHT = '#F7E9DC';
	/** Constant : medium contrast color. */
	const COLOR_CONTRAST_MEDIUM = '#FFDBC6';//'#FBCDAD';
	/** List of log messages. */
	static protected array $_logs = [];
	/** Number of log messages per log level. */
	static protected array $_logLevelCounts = [];
	/** Number of log messages per log class. */
	static protected array $_logClasses = [];
	/** Telemetry: call stack. */
	static protected array $_teleStack = [];
	/** Telemetry: call tree. */
	static protected array $_teleTree = [];
	/** Show debug bar. */
	static protected bool $_showBar = true;
	/** Show logs. */
	static protected bool $_showLogs = true;
	/** Show variables. */
	static protected bool $_showVariables = true;
	/** Show constants. */
	static protected bool $_showConstants = true;
	/** Show config. */
	static protected bool $_showConfig = true;
	/** Show telemetry. */
	static protected bool $_showTelemetry = true;

        /**
	 * Preplugin method.
	 * @return	mixed	Always EXEC_FORWARD.
	 */
        public function preplugin() {
		if ($this->_request->isAjax())
			return;
		// get configuration
		self::$_showBar = $this->_config->xtra('debug', 'showBar', true);
		self::$_showLogs = $this->_config->xtra('debug', 'showLogs', true);
		self::$_showVariables = $this->_config->xtra('debug', 'showVariables', true);
		self::$_showConstants = $this->_config->xtra('debug', 'showConstants', true);
		self::$_showConfig = $this->_config->xtra('debug', 'showConfig', true);
		self::$_showTelemetry = $this->_config->xtra('debug', 'showTelemetry', true);
		// store logs
		if (self::$_showLogs) {
			TµLog::addCallback(function($id, $message, $priority, $class) {
				if ($priority) {
					self::$_logLevelCounts[$priority] ??= 0;
					self::$_logLevelCounts[$priority]++;
				}
				if ($class) {
					self::$_logClasses[$class] ??= 0;
					self::$_logClasses[$class]++;
				}
				self::$_logs[] = [
					'date'    => date('c'),
					'message' => $message,
					'level'   => $priority,
					'class'   => $class,
				];
			});
		}
		// add postplugin
		$plugins = $this->_config->plugins;
		$plugins['_post'] ??= [];
		$plugins['_post'][] = "\\" . self::class;
		$this->_config->plugins = $plugins;
		// create timer
		$timer = new \Temma\Utils\Timer();
		$timer->start();
		$this['tµ__timer'] = $timer;
		// initialize telemetry
		if (self::$_showTelemetry) {
			register_tick_function([$this, '_telemetry']);
		}
        }
	/**
	 * Postplugin method.
	 * @return	mixed	Always EXEC_FORWARD.
	 */
	public function postplugin() {
		$timer = $this['tµ__timer'];
		$time = $timer->stop()->getTime();
		if (self::$_showBar) {
			$html = $this->_generateHtml($time);
			$this->_response->addAppendStream($html);
			unset($this['tµ__timer']);
		}
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Close all remaining telemetry timers.
	 * @param	?array	$list	(optional) For recursivity.
	 */
	protected function _closeTelemetry(?array &$list=null) : void {
		$endTime = microtime(true);
		if (is_null($list))
			$list = &self::$_teleTree;
		foreach ($list as &$item) {
			if (!is_null($item->duration))
				continue;
			$item->duration = $endTime - $item->start;
			$item->start = null;
			$this->_closeTelemetry($item->sub);
		}
	}
	/** Manage telemetry. */
	protected function _telemetry() : void {
		// get backtrace
		$trace = debug_backtrace(/*DEBUG_BACKTRACE_IGNORE_ARGS*/);
		$trace = array_reverse($trace);
		// cleanup the backtrace
		$cleanTrace = [];
		foreach ($trace as $t) {
			$class = $t['class'] ?? null;
			if (($class == self::class/* && $t['function'] == '_telemetry'*/) ||
			    $class == 'Temma\Base\Log' ||
			    (is_a($class, 'ArrayAccess', true) && in_array($t['function'], ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset'])))
				continue;
			$func = $t['function'];
			if (str_contains($func, '\\'))
				$func = "\\$func";
			$obj = '';
			if (isset($t['class']))
				$obj = '\\' . $t['class'];
			$cleanTrace[] = [
				'f' => $func,
				'c' => $obj,
				't' => $t['type'] ?? '',
				'a' => $t['args'] ?? [],
			];
		}
		// loop on stack items
		$i = 0;
		for (; $i < count(self::$_teleStack); $i++) {
			// if the backtrace is smaller than the telemetry stack,
			// remove the extra items in the telemetry stack
			if (!isset($cleanTrace[$i])) {
				while (count(self::$_teleStack) > $i) {
					$item = array_pop(self::$_teleStack);
					$item->duration = microtime(true) - $item->start;
				}
				return;
			}
			// if the same call is found at the same level on each stack, continue to loop
			if ($cleanTrace[$i]['f'] == self::$_teleStack[$i]->f &&
			    $cleanTrace[$i]['c'] == self::$_teleStack[$i]->c &&
			    $cleanTrace[$i]['t'] == self::$_teleStack[$i]->t) {
				continue;
			}
			// the backtrace and the telemetry stack differ from here,
			// remove all subsequent items in the stack
			while (count(self::$_teleStack) > $i) {
				$item = array_pop(self::$_teleStack);
				$item->duration = microtime(true) - $item->start;
			}
		}
		$i = count(self::$_teleStack);
		// add new items in the telemetry stack
		for (; $i < count($cleanTrace); $i++) {
			$traceItem = $cleanTrace[$i];
			$item = new \StdClass();
			$item->f = $traceItem['f'];
			$item->c = $traceItem['c'];
			$item->t = $traceItem['t'];
			$item->start = microtime(true);
			$item->duration = null;
			$item->sub = [];
			$item->a = $traceItem['a'];
			if (!self::$_teleStack)
				self::$_teleTree[] = $item;
			else {
				$parent = self::$_teleStack[$i - 1] ?? null;
				if ($parent)
					$parent->sub[] = $item;
			}
			self::$_teleStack[] = $item;
		}
	}
	/**
	 * Generates the toolbar HTML.
	 * @param	float	$time	Execution duration.
	 * @return	string	The HTML stream.
	 */
	protected function _generateHtml(float $time) : string {
		$colorBlack = self::COLOR_BLACK;
		$colorDark = self::COLOR_DARK;
		$colorMedium = self::COLOR_MEDIUM;
		$colorLight = self::COLOR_LIGHT;
		$colorWhite = self::COLOR_WHITE;
		$colorContrastLight = self::COLOR_CONTRAST_LIGHT;
		$colorContrastMedium = self::COLOR_CONTRAST_MEDIUM;
		$sessionVars = $tplVars = null;
		$html = <<<JAVASCRIPT
			<script>
				function tµIsVisible(target) {
					var element = document.getElementById(target);
					if (!element || element.style.display == "none")
						return false;
					return true;
				}
				function tµHide(target) {
					document.querySelectorAll(target).forEach(function(element) {
						element.style.display = "none";
					});
				}
				function tµShow(target, displayType) {
					document.querySelectorAll(target).forEach(function(element) {
						element.style.display = displayType ? displayType : "block";
					});
				}
				function tµToggle(target, type) {
					type = !type ? "block" : type;
					var elements = document.querySelectorAll(target);
					elements.forEach(function(element) {
						if (element.style.display == "none")
							element.style.display = type;
						else
							element.style.display = "none";
					});
				}
				function tµHasClass(target, className) {
					var element = document.getElementById(className);
					if (!element || !element.classList.contains(className))
						return false;
					return true;
				}
				function tµAddClass(target, className) {
					document.querySelectorAll(target).forEach(function(element) {
						element.classList.add(className);
					});
				}
				function tµRemoveClass(target, className) {
					document.querySelectorAll(target).forEach(function(element) {
						element.classList.remove(className);
					});
				}
				function tµToogleClass(target, className) {
					document.querySelectorAll(target).forEach(function(element) {
						element.classList.toggle(className);
					});
				}
				function tµTogglePanel(panelType) {
					var panelId = "tµ-toolbar-" + panelType;
					var tabBtnId = "tµ-btn-" + panelType;
					var shown = tµIsVisible(panelId);
					tµHide("._tµ-panel");
					tµRemoveClass("._tµ-btn", "tµ-tab-active");
					if (shown) {
						tµRemoveClass("#" + tabBtnId, "tµ-tab-active");
					} else {
						tµShow("#" + panelId);
						tµAddClass("#" + tabBtnId, "tµ-tab-active");
						document.getElementById(panelId).scrollTop = 0;
					}
				}
				function tµIconToggle() {
					tµHide("._tµ-panel");
					tµToggle("#tµ-toolbar");
					tµRemoveClass("._tµ-btn", "tµ-tab-active");
				}
				var tµLogUsedLevel = "";
				var tµLogUsedClass = "";
				function tµLogSetLevel(level) {
					tµHide("._tµ-log");
					var selector = level ? ("._tµ-log-" + level) : "._tµ-log";
					if (tµLogUsedClass)
						selector += "._tµ-log-class-" + tµLogUsedClass;
					tµShow(selector, "table-row");
					tµLogUsedLevel = level;
				}
				function tµLogSetClass(logClass) {
					tµHide("._tµ-log");
					var selector = logClass ? ("._tµ-log-class-" + logClass) : "._tµ-log";
					if (tµLogUsedLevel)
						selector += "._tµ-log-" + tµLogUsedLevel;
					tµShow(selector, "table-row");
					tµLogUsedClass = logClass;
				}
			</script>
			<style>
				body {
					padding-bottom: 150px;
				}
				.tµ-panel {
					position: fixed;
					width: 100%; height: calc(100% - 42px);
					top: 0;
					left; 0;
					padding: 5px;
					background-color: $colorLight;
					color: $colorBlack;
					font-family: Arial, sans-serif;
					font-size: 14px;
					z-index: 99999;
					overflow: auto;
				}
				.tµ-panel h1 {
					font-family: sans-serif;
				}
				.tµ-panel pre.tµ-wrap {
					white-space: pre-wrap;
				}
				.tµ-panel table.data {
					width: calc(100% - 30px);
					border-collapse: separate;
					border-spacing: 2px;
					margin-left: 30px;
				}
				.tµ-panel table.data td {
					padding: 2px 8px 0 8px;
				}
				.tµ-panel table.data td[style='background-color: $colorWhite;']:hover,
				.tµ-panel table.data td[style='background-color: $colorWhite; padding-right: 0;']:hover {
					background-color: $colorContrastLight !important;
				}
				.tµ-panel table.data td[style='background-color: $colorLight;']:hover,
				.tµ-panel table.data td[style='background-color: $colorLight; padding-right: 0;']:hover {
					background-color: $colorContrastMedium !important;
				}
				.tµ-panel > table {
					width: 100%;
					border-collapse: separate;
					border-spacing: 3px;
				}
				.tµ-panel > table > tbody > tr > td {
					padding: 2px 8px 0 8px;
			        	background-color: $colorWhite;
				}
				.tµ-panel > table > tbody > tr:hover > td {
					background-color: $colorContrastLight;
				}
				.tµ-panel pre {
					margin-bottom: 0;
				}
				.tµ-panel button {
					background-color: $colorMedium;
					margin: 5px;
					padding: 1px 8px;
					border: 1px solid $colorLight;
				}
				.tµ-panel button:hover {
					background-color: $colorDark;
					color: $colorWhite;
				}
				.tµ-panel button:active {
					background-color: $colorLight;
					color: $colorBlack;
					border: 1px solid $colorDark;
				}
				.tµ-panel button.tµ-active {
					background-color: $colorDark;
					color: $colorWhite;
				}
				#tµ-toolbar {
					position: fixed;
					width: 100%;
					height: 42px;
					bottom: 0;
					background: linear-gradient($colorDark, #034C9A);
					color: #000;
					font-family: Arial, sans-serif;
					font-size: 13px;
					padding: 3px 0 0 40px;
					z-index: 999999;
				}
				#tµ-toolbar h1 {
					font-family: sans-serif;
				}
				#tµ-toolbar button {
					background-color: $colorMedium;
					margin: 4px;/*4px 4px 0 4px;*/
					padding: 0 6px 0 6px;
					border: 1px solid $colorMedium;
				}
				#tµ-toolbar button:hover {
					background-color: $colorDark;
					color: $colorWhite;
				}
				#tµ-toolbar button:active {
					background-color: $colorLight;
					color: $colorBlack;
					border: 1px solid $colorMedium;
				}
				#tµ-toolbar button.tµ-tab-active {
					background-color: $colorDark;
					color: $colorWhite;
				}
				#tµ-toolbar button.tµ-tab-active:active {
					background-color: $colorLight;
					color: $colorBlack;
					border: 1px solid $colorMedium;
				}
				#tµ-toolbar-logs select {
					margin: 5px;
				}
			</style>
		JAVASCRIPT;
		// logs
		if (self::$_showLogs) {
			$html .= <<<LOG_PANEL
				<div id="tµ-toolbar-logs" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
					<h1>Logs</h1>
					<table>
			LOG_PANEL;
			if (self::$_logLevelCounts || self::$_logClasses) {
				$html .= "<tr>
						<td style='background-color: transparent;'></td>";
				if (self::$_logLevelCounts) {
					$html .= "<td style='background-color: transparent; padding: 0;'>
							<select onchange='tµLogSetLevel(this.value)' style='margin: 0 0 0px 0;'>
								<option value=''>All</option>";
					foreach (TµLog::LEVELS as $level => $value) {
						if (!isset(self::$_logLevelCounts[$level]))
							continue;
						$html .= "<option value='" . TµText::urlize($level) . "'>" . htmlspecialchars($level) . " (" . self::$_logLevelCounts[$level] . ")</option>";
					}
					$html .= "	</select>
						</td>";
				}
				if (self::$_logClasses) {
					$html .= "<td style='background-color: transparent; padding: 0;'>
							<select onchange='tµLogSetClass(this.value)' style='margin: 0 0 0px 0;'>
								<option value=''>All</option>";
					ksort(self::$_logClasses);
					foreach (self::$_logClasses as $class => $value) {
						$html .= "<option value='" . TµText::urlize($class) . "'>" . htmlspecialchars($class) . " ($value)</option>";
					}
					$html .= "	</select>
						</td>";
				}
				$html .= "<td style='width: 100%; background-color: transparent;'></td>";
			}
			foreach (self::$_logs as $log) {
				$cssClass = ($log['level'] ? '_tµ-log-' . TµText::urlize($log['level']) : '') . ' ' .
					    ($log['class'] ? '_tµ-log-class-' . TµText::urlize($log['class']) : '');
				$color = in_array($log['level'], ['CRIT', 'ERROR']) ? 'red' :
					 ($log['level'] == 'WARN' ? 'orange' :
					  (in_array($log['level'], ['NOTE', 'INFO']) ? '#383' : '#666'));
				$html .= "<tr valign='top' class='_tµ-log _tµ-log-" . TµText::urlize($log['level']) . " _tµ-log-class-" . TµText::urlize($log['class']) . "'>
						<td><pre>" . $log['date'] . "</pre></td>";
				if (self::$_logLevelCounts)
					$html .= "<td style='text-align: center;'><pre><span style='padding: 2px 2px 1px 2px; color: #fff; background-color: $color;'>" . htmlspecialchars($log['level']) . "</span></pre></td>";
				$classBg = '#555';
				$classFg = '#fff';
				if ($log['class'] == 'Temma/Base')
					$classBg = $colorMedium;
				else if ($log['class'] == 'Temma/Web')
					$classBg = $colorDark;
				else if (str_starts_with($log['class'], 'Temma/')) {
					$classBg = $colorLight;
					$classFg = $colorDark;
				}
				if (self::$_logClasses)
					$html .= "<td style='text-align: center;'><pre><span style='padding: 2px 2px 1px 2px; background-color: $classBg; color: $classFg;'>" . htmlspecialchars($log['class']) . "</span></pre></td>";
				$html .= "	<td style='color: $color;'><pre class='tµ-wrap'style='color: $color;'>" . htmlspecialchars($log['message']) . "</pre></td>
					  </tr>";
			}
			$html .= "</table>
				  </div>";
		}
		// variables
		if (self::$_showVariables) {
			$html .= "<div id='tµ-toolbar-variables' class='tµ-panel _tµ-toolbar _tµ-panel' style='display: none;'>";
			$sessionVars = $this->_session->getAll();
			if ($sessionVars) {
				ksort($sessionVars);
				$html .= "<h1>Session variables</h1>
					  <table>";
				foreach ($sessionVars as $key => $value) {
					$html .= "<tr valign='top'>
							<td style='width: 1%;'><pre>" . htmlspecialchars($key) . "</pre></td>
							<td>" . self::dump($value) . "</td>
						  </tr>\n";
				}
				$html .= "</table>\n";
			}
			if ($_GET) {
				$html .= "<h1>GET variables</h1>
					  <table>";
				$getVars = $_GET;
				ksort($getVars);
				foreach ($getVars as $key => $value) {
					$html .= "<tr valign='top'>
							<td style='width: 1%;'><pre>$" . htmlspecialchars($key) . "</pre></td>
							<td>" . self::dump($value) . "</td>
						  </tr>\n";
				}
				unset($getVars);
				$html .= "</table>";
			}
			if ($_COOKIE) {
				$html .= "<h1 style='margin-top: 15px;'>Cookies variables</h1>
					  <table>";
				$cookies = $_COOKIE;
				ksort($cookies);
				foreach ($cookies as $key => $value) {
					$html .= "<tr valign='top'>
							<td style='width: 1%;'><pre>$" . htmlspecialchars($key) . "</pre></td>
							<td>" . self::dump($value) . "</td>
						  </tr>\n";
				}
				unset($cookies);
				$html .= "</table>";
			}
			$tplVars = $this->_response->getData();
			if ($tplVars) {
				$html .= "<h1 style='margin-top: 15px;'>Template variables</h1>
					<table>";
				ksort($tplVars);
				foreach ($tplVars as $key => $value) {
					if (str_starts_with($key, 'tµ__'))
						continue;
					$txt = '$' . htmlspecialchars($key);
					if (in_array($key, ['ACTION', 'CONTROLLER', 'URL', 'SESSIONID', 'AJAX', 'conf']))
						$txt = "<strong>$txt</strong>";
					$html .= "<tr valign='top'>
							<td style='width: 1%;'><pre>$txt</pre></td>
							<td>" . self::dump($value) . "</td>
						  </tr>\n";
				}
				$html .= "</table>";
			}
			$html .= "</div>";
		}
		// constants
		if (self::$_showConstants) {
			$html .= <<<CONSTANTS_PANEL
				<div id="tµ-toolbar-constants" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
					<h1><tt>\$_SERVER</tt></h1>
					<table>
			CONSTANTS_PANEL;
			$constants = $_SERVER;
			ksort($constants);
			foreach ($constants as $key => $value) {
				$html .= "<tr valign='top'>
						<td style='width: 1%;'><pre>" . htmlspecialchars($key) . "</pre></td>
						<td>" . self::dump($value) . "</td>
					  </tr>\n";
			}
			$html .= "</table>
				  <h1 style='margin-top: 15px;'>Environment</h1>
				  <table>";
			$constants = getenv();
			ksort($constants);
			foreach ($constants as $key => $value) {
				$html .= "<tr valign='top'>
						<td style='width: 1%;'><pre>" . htmlspecialchars($key) . "</pre></td>
						<td>" . self::dump($value) . "</td>
					  </tr>\n";
			}
			unset($constants);
			$html .= "</table></div>";
		}
		// config
		if (self::$_showConfig) {
			$configData = [
				'Application path'        => $this->_config->appPath,
				'Include path'            => $this->_config->pathsToInclude,
				'Data sources'            => $this->_config->dataSources,
				'Sessions enabled'        => $this->_config->enableSessions ? 'yes' : 'no',
				'Session name'            => $this->_config->sessionName,
				'Session source'          => $this->_config->sessionSource,
				'Root controller'         => $this->_config->rootController,
				'Default controller'      => $this->_config->defaultController,
				'Proxy controller'        => $this->_config->proxyController,
				'Default namespace'       => $this->_config->defaultNamespace,
				'Default view'            => $this->_config->defaultView,
				'Loader'                  => $this->_config->loader,
				'Log manager'             => $this->_config->logManager,
				'Log levels'              => $this->_config->logLevels,
				'Buffering log levels'    => $this->_config->bufferingLogLevels,
				'Routes'                  => $this->_config->routes,
				'Plugins'                 => $this->_config->plugins,
				'Auto-imported variables' => $this->_config->autoimport,
			];
			$xtraConf = $this->_config->extraConfig;
			ksort($xtraConf);
			$configData = array_merge($configData, $xtraConf);
			unset($xtraConf);
			$html .= <<<CONFIG_PANEL
				<div id="tµ-toolbar-config" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
					<h1>Configuration</h1>
					<table>
			CONFIG_PANEL;
			foreach ($configData as $key => $val) {
				$html .= "<tr valign='top'>
						<td style='width: 1%;'><pre>$key</pre></td>
						<td>" . self::dump($val) . "</td>
					  </tr>";
			}
			$html .= <<<CONFIG_PANEL
					</table>
				</div>
			CONFIG_PANEL;
		}
		// telemetry
		if (self::$_showTelemetry) {
			$this->_closeTelemetry();
			$totalTime = 0;
			foreach (self::$_teleTree as $item)
				$totalTime += $item->duration;
			$html .= <<<TELEMETRY_PANEL
				<div id="tµ-toolbar-telemetry" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
					<h1>Telemetry</h1>
			TELEMETRY_PANEL;
			//$html .= '<pre>' . print_r(self::$_teleTree, true) . '</pre>';
			$html .= self::dumpTelemetry(self::$_teleTree, $totalTime);
			$html .= "</div>\n";
		}
		// toolbar
		if (self::$_showBar) {
			$html .= <<<BAR
				<div id="tµ-toolbar" class="_tµ-toolbar">
			BAR;
			if (self::$_logs) {
				$html .= <<<BAR
					<button id="tµ-btn-logs" class="_tµ-btn" onclick="tµTogglePanel('logs')">
						Logs
					</button>
				BAR;
			}
			if (self::$_showVariables && ($sessionVars || $_COOKIE || $_GET || $tplVars)) {
				$html .= <<<BAR
					<button id="tµ-btn-variables" class="_tµ-btn" onclick="tµTogglePanel('variables')">
						Variables
					</button>
				BAR;
			}
			unset($sessionVars);
			unset($tplVars);
			if (self::$_showConstants) {
				$html .= <<<BAR
					<button id="tµ-btn-constants" class="_tµ-btn" onclick="tµTogglePanel('constants')">
						Constants
					</button>
				BAR;
			}
			if (self::$_showConfig) {
				$html .= <<<BAR
					<button id="tµ-btn-config" class="_tµ-btn" onclick="tµTogglePanel('config')">
						Config
					</button>
				BAR;
			}
			if (self::$_showTelemetry) {
				$html .= <<<BAR
					<button id="tµ-btn-telemetry" class="_tµ-btn" onclick="tµTogglePanel('telemetry')">
						Telemetry
					</button>
				BAR;
			}
			$html .= <<< BAR
					<span style="float: right; margin: 5px 8px 0 0; color: #ddd;">
						<span title="Execution time before template">
			BAR;
			if ($time < 1)
				$html .= sprintf("%d ms", ($time * 1000));
			else
				$html .= sprintf("%.02f s", $time);
			$html .= <<<'BAR'
				</span>
				<span style='color: #999;'>|</span>
				<span title="Peak memory usage">
			BAR;
			$memory = memory_get_peak_usage(true);
			if ($memory > (1024 * 1024 * 1024 * 1024))
				$html .= sprintf("%.02f TB", ($memory / (1024 * 1024 * 1024 * 1024)));
			else if ($memory > (1024 * 1024 * 1024))
				$html .= sprintf("%.02f GB", ($memory / (1024 * 1024 * 1024)));
			else if ($memory > (1024 * 1024))
				$html .= sprintf("%.02f MB", ($memory / (1024 * 1024)));
			else if ($memory > 1024)
				$html .= sprintf("%.02f KB", ($memory / 1024));
			else
				$html .= sprintf("%d bytes", $memory);
			$html .= <<<'BAR'
						</span>
					</span>
				</div>
			BAR;
		}
		// icon
		$html .= <<<'ICON'
			<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAMAAAC7IEhfAAAAbFBMVEU0mNs0ltk0mNs0mNs0mNoAAAA0mNv///+Vv+e0z+xnquD3+/3z+PxAnN3L3/NkqeA3mdy91u+ZwuiEt+VsreJJn947mtz6/P7v9fzo8fni7fiixel4suNRod4/nNz4+/6uzOyRveZ9s+NZpN/WKhK5AAAABnRSTlPyKu2vrwBiI6u4AAAAtklEQVQ4y+3VyQ7CIBSFYRC4F2m10Hl0fP93NCUhpomWm6hd+a9YfAlhc2CKCwaRmOCK8R0Q2nEmgZRkjAa9I/UjaPWy+h084bLqYwjZXHnGNPPZ9cccEkwhRIKFcyX4tHPTCtwjavAdEc0fbgFzKnT+cO+iMCks1LrFKETs+hab+NXNbPtb+RLafNABjvllGC1YY+olDAVYwLNVeKVC/V04GVNtsj3E6EMqaFDQx15xGf8+JFcPmt4rL0aOUNEAAAAASUVORK5CYII="
			style="position: fixed; width: 26px; height: 26px; left: 8px; bottom: 8px; cursor: pointer; z-index: 1000000;"
			title="Close" onclick="tµIconToggle()" />
		ICON;
		return ($html);
	}
	/**
	 * Returns an HTML stream for a list of telemetry items.
	 * @param	array	$items		The list of items.
	 * @param	float	$totalDuration	The total execution duration.
	 * @param	bool	$light	(optional) True for light background. Defaults to true.
	 * @return	string	The HTML stream.
	 */
	private static function dumpTelemetry(array $items, float $totalDuration, bool $light=true) : string {
		if (!$items)
			return ('');
		$res = "<table class='data' style='margin-left: 5px; width: calc(100% - 5px);'>\n";
		$bgColor = $light ? self::COLOR_WHITE : self::COLOR_LIGHT;
		foreach ($items as $item) {
			$name = "<span style='color: brown;'>" . htmlspecialchars($item->f) . "(<span style='color: gray;'>";
			$list = [];
			foreach ($item->a as $arg) {
				if (is_scalar($arg))
					$s = (string)$arg;
				else if (is_array($arg))
					$s = "Array [</span><span style='color: blue;'>" . count($arg) . "</span><span style='color: gray;'>]";
				else if (is_object($arg))
					$s = "Object [</span><span style='color: blue;'>" . htmlspecialchars(get_class($arg)) . "</span><span style='color: gray;'>]";
				else if (is_resource($arg))
					$s = 'Resource [' . htmlspecialchars(get_resource_type($arg)) . "]: " . get_resource_id($arg);
				else
					$s = "Unknwon type";
				$s = (mb_strlen($s) > 30) ? (mb_substr($s, 0, 29) . '…') : $s;
				$list[] = htmlspecialchars($s);
			}
			$name .= implode("</span><strong>,</strong> <span style='color: gray;'>", $list);
			$name .= "</span>)</span>";
			if ($item->c)
				$name = "<span style='color: purple;'>" . htmlspecialchars($item->c) . "</span><strong>" . htmlspecialchars($item->t) . "</strong>$name";
			$duration = (int)($item->duration * 1000);
			$percent = sprintf('%.02f', abs($item->duration * 100 / $totalDuration));
			$res .= "<tr valign='top'>
					<td style='background-color: $bgColor; padding-right: 0;'>
						<pre><span style='color: #484;'>[$duration&nbsp;ms&nbsp;/ <strong>$percent%</strong>]</span> $name</pre>\n";
			$res .= self::dumpTelemetry($item->sub, $totalDuration, !$light);
			$res .= "	</td>
				 </tr>\n";
		}
		$res .= "</table>\n";
		return ($res);
	}
	/**
	 * Returns an HTML stream for a given variable.
	 * @param	mixed	$data	The variable.
	 * @param	array	$known	(optional) List of known objects and arrays, to prevent circular references.
	 * @param	bool	$light	(optional) True for light background. Defaults to false.
	 * @return	string	The HTML stream.
	 */
	private static function dump(mixed $data, array &$known=[], bool $light=false) : string {
		if (is_null($data))
			return ("<pre>null</pre>\n");
		if (is_bool($data))
			return ("<pre>" . ($data ? 'true' : 'false') . "</pre>\n");
		if (is_scalar($data))
			return ("<pre class='tµ-wrap'>" . htmlspecialchars($data) . "</pre>\n");
		if (is_array($data)) {
			$arrayId = md5(serialize($data));
			if (isset($known[$arrayId]))
				return ("<em>RECURSION</em>\n");
			$known[$arrayId] = true;
			$res = "<pre>array (" . count($data) . "):</pre>
				<table class='data'>\n";
			$bgColor = $light ? self::COLOR_WHITE : self::COLOR_LIGHT;
			foreach ($data as $key => $value) {
				$res .= "<tr valign='top'>
						<td style='background-color: $bgColor; width: 1%;'><pre>" . htmlspecialchars($key) . "</pre></td>
						<td style='background-color: $bgColor;'>";
				$res .= self::dump($value, $known, !$light);
				$res .= "</td></tr>\n";
			}
			$res .= "</table>\n";
			return ($res);
		}
		if (is_object($data)) {
			$objectId = spl_object_hash($data);
			if (isset($known[$objectId]))
				return ("<em>RECURSION</em>\n");
			$known[$objectId] = true;
			$res = '<pre>Object [' . htmlspecialchars(get_class($data)) . "]:</pre>\n<table class='data'>\n";
			foreach (get_object_vars($data) as $key => $value) {
				$bgColor = $light ? self::COLOR_WHITE : self::COLOR_LIGHT;
				$res .= "<tr valign='top'>
						<td style='background-color: $bgColor; max-width: 33%;'><pre>" . htmlspecialchars($key) . "</pre></td>
						<td style='background-color: $bgColor;'>";
				$res .= self::dump($value, $known, !$light);
				$res .= "</td></tr>\n";
			}
			$res .= "</table\n";
			return ($res);
		}
		if (is_resource($data))
			return ('Resource [' . get_resource_type($data) . "]: " . get_resource_id($data) . "\n");
		return ("<em>Unknown type</em>\n");
	}
}

