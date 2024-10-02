<?php

/**
 * Debug
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2024, Amaury Bouchard
 */

namespace Temma\Plugins;

use \Temma\Base\Log as TµLog;

/**
 * Plugin used to set debug toolbar.
 */
class Debug extends \Temma\Web\Plugin {
	/** List of log messages. */
	static protected array $_logs = [];
	/** Number of log messages per log level. */
	static protected array $_logLevelCounts = [];
	/** Number of log messages per log class. */
	static protected array $_logClasses = [];

        /**
	 * Preplugin method.
	 * @return	mixed	Always EXEC_FORWARD.
	 */
        public function preplugin() {
		if ($this->_request->isAjax())
			return;
		// store logs
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
		// add postplugin
		$plugins = $this->_config->plugins;
		$plugins['_post'] ??= [];
		$plugins['_post'][] = "\\" . self::class;
		$this->_config->plugins = $plugins;
		// create timer
		$timer = new \Temma\Utils\Timer();
		$timer->start();
		$this['tµ__timer'] = $timer;
        }
	/**
	 * Postplugin method.
	 * @return	mixed	Always EXEC_FORWARD.
	 */
	public function postplugin() {
		$timer = $this['tµ__timer'];
		$time = $timer->stop()->getTime();
		$html = $this->_generateHtml($time);
		$this->_response->setAppendStream($html);
	}

	/* ********** PRIVATE METHODS ********** */
	/**
	 * Generates the toolbar HTML.
	 * @param	float	$time	Execution duration.
	 * @return	string	The HTML stream.
	 */
	public function _generateHtml(float $time) : string {
		$colorBlack = '#334';
		$colorDark = '#0069D9';
		$colorMedium = '#107EF4';
		$colorLight = '#DCE9F7';
		$colorWhite = '#F8F8FF';
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
				function tµShow(target) {
					document.querySelectorAll(target).forEach(function(element) {
						element.style.display = "block";
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
					}
				}
				function tµIconToggle() {
					tµHide("._tµ-panel");
					tµToggle("#tµ-toolbar");
					tµRemoveClass("._tµ-btn", "tµ-tab-active");
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
				.tµ-panel table {
					width: 100%;
					border-collapse: separate;
					border-spacing: 3px;
				}
				.tµ-panel td {
			        	background-color: $colorWhite;
				}
				.tµ-panel pre {
					margin-bottom: 0;
				}
				.tµ-panel button {
					background-color: $colorMedium;
					margin: 5px;
					/*padding: 8px;*/
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
			</style>
		JAVASCRIPT;
		// logs
		$html .= <<<LOG_PANEL
			<div id="tµ-toolbar-logs" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
				<h1 style="margin-bottom: 20px;">Logs</h1>
				<div style="margin-bottom: 15px;">
		LOG_PANEL;
		$html .= "<button id='tµ-log-btn-all' class='tµ-log-btn tµ-active'
		           onclick=\"tµRemoveClass('.tµ-log-btn', 'tµ-active'); tµAddClass('#tµ-log-btn-all', 'tµ-active'); tµShow('._tµ-log');\">All (" . count(self::$_logs) . ")</button>";
		foreach (TµLog::LEVELS as $level => $value) {
			if (!isset(self::$_logLevelCounts[$level]))
				continue;
			$count = self::$_logLevelCounts[$level];
			$url = \Temma\Utils\Text::urlize($level);
			$html .= "<button id='tµ-log-btn-$url' class='tµ-log-btn'
			           onclick=\"tµRemoveClass('.tµ-log-btn', 'tµ-active'); tµAddClass('#tµ-log-btn-$url', 'tµ-active'); tµHide('._tµ-log'); tµShow('._tµ-log-$url')\">" . htmlspecialchars($level) . " ($count)</button>";
		}
		$html .= "</div><pre>\n";
		foreach (self::$_logs as $log) {
			$html .= "<div class='_tµ-log _tµ-log-" . \Temma\Utils\Text::urlize($log['level']) . "' style='background-color: $colorWhite; margin-bottom: 2px;'>";
			$color = in_array($log['level'], ['CRIT', 'ERROR']) ? 'red' :
			         ($log['level'] == 'WARN' ? 'orange' :
			          (in_array($log['level'], ['NOTE', 'INFO']) ? 'green' : 'gray'));
			$html .= $log['date'] . " <span style='padding: 2px 2px 1px 2px; color: #fff; background-color: $color;'>" . htmlspecialchars(TµLog::LABELS[$log['level']] ?? $log['level']) . "</span> ";
			if ($log['class'])
				$html .= "<span style='background-color: gray; color: #fff; padding: 2px 2px 1px 2px;'>" . htmlspecialchars($log['class']) . "</span> ";
			$html .= "<span style='color: $color;'>" . $log['message'] . "</span></div>";
		}
		$html .= "</pre></div>";
		// session variables
		$html .= <<<SESSION_PANEL
			<div id="tµ-toolbar-session" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
				<h1>Session</h1>
				<table>
		SESSION_PANEL;
		$sessionVars = $this->_session->getAll();
		foreach ($sessionVars as $key => $value) {
			$html .= "<tr><td><pre>" . htmlspecialchars($key) . "</pre></td>" .
			         "<td style='width: 100%;'><pre>" . htmlspecialchars(var_export($value, true)) . "</pre></td></tr>\n";
		}
		$html .= "</table></div>";
		// variables
		$html .= "<div id='tµ-toolbar-variables' class='tµ-panel _tµ-toolbar _tµ-panel' style='display: none;'>";
		if ($_GET) {
			$html .= "<h1>GET variables</h1>
			          <table>";
			foreach ($_GET as $key => $value) {
				$html .= "<tr valign='top'><td><pre>$" . htmlspecialchars($key) . "</pre></td>" .
					 "<td><pre>" . htmlspecialchars(var_export($value, true)) . "</pre></td></tr>\n";
			}
			$html .= "</table>";
		}
		if ($_COOKIE) {
			$html .= "<h1 style='margin-top: 15px;'>Cookies variables</h1>
			          <table>";
			foreach ($_COOKIE as $key => $value) {
				$html .= "<tr valign='top'><td><pre>$" . htmlspecialchars($key) . "</pre></td>" .
					 "<td><pre>" . htmlspecialchars(var_export($value, true)) . "</pre></td></tr>\n";
			}
			$html .= "</table>";
		}
		$tplVars = $this->_response->getData();
		if ($tplVars) {
			$html .= "<h1 style='margin-top: 15px;'>Template variables</h1>
				<table>";
			foreach ($tplVars as $key => $value) {
				if (str_starts_with($key, 'tµ__'))
					continue;
				$html .= "<tr valign='top'><td><pre>$" . htmlspecialchars($key) . "</pre></td>" .
				         "<td><pre>" . htmlspecialchars(var_export($value, true)) . "</pre></td></tr>\n";
			}
			$html .= "</table>";
		}
		$html .= "</div>";
		// constants
		$html .= <<<CONSTANTS_PANEL
			<div id="tµ-toolbar-constants" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
				<h1><tt>\$_SERVER</tt></h1>
				<table>
		CONSTANTS_PANEL;
		$constants = $_SERVER;
		ksort($constants);
		foreach ($constants as $key => $value) {
			$html .= "<tr><td><pre>" . htmlspecialchars($key) . "</pre></td>" .
			         "<td><pre>" . nl2br(htmlspecialchars($value)) . "</pre></td></tr>\n";
		}
		$html .= "</table>
		          <h1 style='margin-top: 15px;'>Environment</h1>
		          <table>";
		$constants = getenv();
		ksort($constants);
		foreach ($constants as $key => $value) {
			$html .= "<tr><td><pre>" . htmlspecialchars($key) . "</pre></td>" .
			         "<td><pre>" . nl2br(htmlspecialchars($value)) . "</pre></td></tr>\n";
		}
		$html .= "</table></div>";
		// config
		$configData = [
			'Application path'        => htmlspecialchars($this->_config->appPath),
			'Include path'            => htmlspecialchars(var_export($this->_config->pathsToInclude, true)),
			'Data sources'            => htmlspecialchars(var_export($this->_config->dataSources, true)),
			'Sessions enabled'        => $this->_config->enableSessions ? 'yes' : 'no',
			'Session name'            => htmlspecialchars($this->_config->sessionName),
			'Session source'          => htmlspecialchars($this->_config->sessionSource),
			'Root controller'         => htmlspecialchars($this->_config->rootController),
			'Default controller'      => htmlspecialchars($this->_config->defaultController),
			'Proxy controller'        => htmlspecialchars($this->_config->proxyController),
			'Default namespace'       => htmlspecialchars($this->_config->defaultNamespace),
			'Default view'            => htmlspecialchars($this->_config->defaultView),
			'Loader'                  => htmlspecialchars($this->_config->loader),
			'Log manager'             => htmlspecialchars($this->_config->logManager),
			'Log levels'              => htmlspecialchars(var_export($this->_config->logLevels, true)),
			'Buffering log levels'    => htmlspecialchars(var_export($this->_config->bufferingLogLevels, true)),
			'Routes'                  => htmlspecialchars(var_export($this->_config->routes, true)),
			'Plugins'                 => htmlspecialchars(var_export($this->_config->plugins, true)),
			'Auto-imported variables' => htmlspecialchars(var_export($this->_config->autoimport, true)),
		];
		foreach ($this->_config->extraConfig as $xtra => $content)
			$configData[$xtra] = htmlspecialchars(var_export($content, true));
		$html .= <<<CONFIG_PANEL
			<div id="tµ-toolbar-config" class="tµ-panel _tµ-toolbar _tµ-panel" style="display: none;">
				<h1>Configuration</h1>
				<table>
		CONFIG_PANEL;
		foreach ($configData as $key => $val) {
			$html .= "<tr><td>$key</td>
			              <td><pre>$val</pre></td></tr>";
		}
		$html .= <<<CONFIG_PANEL
				</table>
			</div>
		CONFIG_PANEL;
		// toolbar
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
		if ($sessionVars) {
			$html .= <<<BAR
				<button id="tµ-btn-session" class="_tµ-btn" onclick="tµTogglePanel('session')">
					Session
				</button>
			BAR;
		}
		if ($_GET || $tplVars) {
			$html .= <<<BAR
				<button id="tµ-btn-variables" class="_tµ-btn" onclick="tµTogglePanel('variables')">
					Variables
				</button>
			BAR;
		}
		$html .= <<<BAR
				<button id="tµ-btn-constants" class="_tµ-btn" onclick="tµTogglePanel('constants')">
					Constants
				</button>
				<button id="tµ-btn-config" class="_tµ-btn" onclick="tµTogglePanel('config')">
					Config
				</button>
				<span style="float: right; margin: 5px 8px 0 0; color: #ddd;">
		BAR;
		if ($time < 1)
			$html .= sprintf("%d ms", ($time * 1000));
		else
			$html .= sprintf("%.02f s", $time);
		$memory = memory_get_peak_usage(true);
		$html .= " <span style='color: #999;'>|</span> ";
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
			</div>
		BAR;
		// icon
		$html .= <<<'ICON'
			<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAMAAAC7IEhfAAAAbFBMVEU0mNs0ltk0mNs0mNs0mNoAAAA0mNv///+Vv+e0z+xnquD3+/3z+PxAnN3L3/NkqeA3mdy91u+ZwuiEt+VsreJJn947mtz6/P7v9fzo8fni7fiixel4suNRod4/nNz4+/6uzOyRveZ9s+NZpN/WKhK5AAAABnRSTlPyKu2vrwBiI6u4AAAAtklEQVQ4y+3VyQ7CIBSFYRC4F2m10Hl0fP93NCUhpomWm6hd+a9YfAlhc2CKCwaRmOCK8R0Q2nEmgZRkjAa9I/UjaPWy+h084bLqYwjZXHnGNPPZ9cccEkwhRIKFcyX4tHPTCtwjavAdEc0fbgFzKnT+cO+iMCks1LrFKETs+hab+NXNbPtb+RLafNABjvllGC1YY+olDAVYwLNVeKVC/V04GVNtsj3E6EMqaFDQx15xGf8+JFcPmt4rL0aOUNEAAAAASUVORK5CYII="
			style="position: fixed; width: 26px; height: 26px; left: 8px; bottom: 8px; cursor: pointer; z-index: 1000000;"
			title="Close" onclick="tµIconToggle()" />
		ICON;
		return ($html);
	}
}

