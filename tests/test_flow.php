#!/usr/bin/php
<?php

/**
 * Validation script for the execution flow (EXEC_STOP vs EXEC_HALT).
 * Builds a dummy application whose plugins and controllers trace their execution
 * in the 'trace' template variable, then checks the trace produced by each scenario:
 * - EXEC_STOP only stops the current phase (pre-plugins, controller or post-plugins);
 * - EXEC_HALT short-circuits everything up to the view.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** DUMMY APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-flow-test-' . getmypid();
foreach (['controllers', 'etc', 'log', 'tmp', 'templates'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});

// tracer plugins and status plugins
$classes = [
	'MarkA' => <<<'EOT'
		class MarkA extends \Temma\Web\Plugin {
			public function plugin() {
				$this['trace'] = ($this['trace'] ?? '') . 'A';
			}
		}
		EOT,
	'MarkB' => <<<'EOT'
		class MarkB extends \Temma\Web\Plugin {
			public function plugin() {
				$this['trace'] = ($this['trace'] ?? '') . 'B';
			}
		}
		EOT,
	'MarkPost1' => <<<'EOT'
		class MarkPost1 extends \Temma\Web\Plugin {
			public function plugin() {
				$this['trace'] = ($this['trace'] ?? '') . 'P';
			}
		}
		EOT,
	'MarkPost2' => <<<'EOT'
		class MarkPost2 extends \Temma\Web\Plugin {
			public function plugin() {
				$this['trace'] = ($this['trace'] ?? '') . 'Q';
			}
		}
		EOT,
	// pre-plugin that returns a status depending on the requested controller
	'PreStatusPlugin' => <<<'EOT'
		class PreStatusPlugin extends \Temma\Web\Plugin {
			public function preplugin() {
				return match (strtolower($this['CONTROLLER'] ?? '')) {
					'prestop' => self::EXEC_STOP,
					'prehalt' => self::EXEC_HALT,
					default   => null,
				};
			}
		}
		EOT,
	// post-plugin that returns a status depending on the requested controller
	'PostStatusPlugin' => <<<'EOT'
		class PostStatusPlugin extends \Temma\Web\Plugin {
			public function postplugin() {
				return match (strtolower($this['CONTROLLER'] ?? '')) {
					'poststop' => self::EXEC_STOP,
					'posthalt' => self::EXEC_HALT,
					default    => null,
				};
			}
		}
		EOT,
	// scenario controllers: __wakeup traces 'w', the action traces 'C', __sleep traces 's'
	'Normal' => <<<'EOT'
		class Normal extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Prestop' => <<<'EOT'
		class Prestop extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Prehalt' => <<<'EOT'
		class Prehalt extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Wakestop' => <<<'EOT'
		class Wakestop extends \Temma\Web\Controller {
			public function __wakeup() {
				$this['trace'] = ($this['trace'] ?? '') . 'w';
				return (self::EXEC_STOP);
			}
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Actstop' => <<<'EOT'
		class Actstop extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() {
				$this['trace'] = ($this['trace'] ?? '') . 'C';
				return (self::EXEC_STOP);
			}
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Actstopex' => <<<'EOT'
		class Actstopex extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() {
				$this['trace'] = ($this['trace'] ?? '') . 'C';
				throw new \Temma\Exceptions\FlowStop();
			}
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Acthalt' => <<<'EOT'
		class Acthalt extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() {
				$this['trace'] = ($this['trace'] ?? '') . 'C';
				return (self::EXEC_HALT);
			}
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Sleepstop' => <<<'EOT'
		class Sleepstop extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() {
				$this['trace'] = ($this['trace'] ?? '') . 's';
				return (self::EXEC_STOP);
			}
		}
		EOT,
	'Poststop' => <<<'EOT'
		class Poststop extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
	'Posthalt' => <<<'EOT'
		class Posthalt extends \Temma\Web\Controller {
			public function __wakeup() { $this['trace'] = ($this['trace'] ?? '') . 'w'; }
			public function run() { $this['trace'] = ($this['trace'] ?? '') . 'C'; }
			public function __sleep() { $this['trace'] = ($this['trace'] ?? '') . 's'; }
		}
		EOT,
];
foreach ($classes as $name => $code)
	file_put_contents("$appPath/controllers/$name.php", "<?php\n\n$code\n");

// configuration: global tracer plugins + status plugins
file_put_contents("$appPath/etc/temma.php", <<<'EOT'
	<?php

	return [
		'application' => [
			'enableSessions' => false,
		],
		'loglevels' => 'CRIT',
		'plugins' => [
			'_pre'  => ['MarkA', 'PreStatusPlugin', 'MarkB'],
			'_post' => ['MarkPost1', 'PostStatusPlugin', 'MarkPost2'],
		],
	];
	EOT);

/* ********** TEST MICRO-FRAMEWORK ********** */
$count = 0;
$failed = 0;
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// executes a request and returns the produced trace
function getTrace(string $url) : ?string {
	global $appPath;
	$test = new \Temma\Web\Test($appPath, "$appPath/etc/temma.php");
	$data = $test->execData($url);
	return (is_array($data) ? ($data['trace'] ?? null) : null);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Execution flow: EXEC_STOP vs EXEC_HALT\n"));
// pre-plugins A, [status], B; controller w, C, s; post-plugins P, [status], Q
check("control: full chain (ABwCsPQ)",
      getTrace('/normal/run') === 'ABwCsPQ');
check("EXEC_STOP in pre-plugin: stops the pre-plugins, executes controller and post-plugins (AwCsPQ)",
      getTrace('/prestop/run') === 'AwCsPQ');
check("EXEC_HALT in pre-plugin: goes straight to the view (A)",
      getTrace('/prehalt/run') === 'A');
check("EXEC_STOP in __wakeup(): skips action and __sleep(), executes the post-plugins (ABwPQ)",
      getTrace('/wakestop/run') === 'ABwPQ');
check("EXEC_STOP in the action: skips __sleep(), executes the post-plugins (ABwCPQ)",
      getTrace('/actstop/run') === 'ABwCPQ');
check("FlowStop exception in the action: same behavior (ABwCPQ)",
      getTrace('/actstopex/run') === 'ABwCPQ');
check("EXEC_HALT in the action: skips __sleep() and the post-plugins (ABwC)",
      getTrace('/acthalt/run') === 'ABwC');
check("EXEC_STOP in __sleep(): executes the post-plugins (ABwCsPQ)",
      getTrace('/sleepstop/run') === 'ABwCsPQ');
check("EXEC_STOP in post-plugin: stops the post-plugins (ABwCsP)",
      getTrace('/poststop/run') === 'ABwCsP');
check("EXEC_HALT in post-plugin: stops the post-plugins (ABwCsP)",
      getTrace('/posthalt/run') === 'ABwCsP');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
