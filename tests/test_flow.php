#!/usr/bin/php
<?php

/**
 * Script de validation du flux d'exécution (EXEC_STOP vs EXEC_HALT).
 * Construit une application factice dont les plugins et contrôleurs tracent leur exécution
 * dans la variable de template 'trace', puis vérifie la trace produite par chaque scénario :
 * - EXEC_STOP n'arrête que la phase courante (pré-plugins, contrôleur ou post-plugins) ;
 * - EXEC_HALT court-circuite tout jusqu'à la vue.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** APPLICATION FACTICE ********** */
$appPath = sys_get_temp_dir() . '/temma-flow-test-' . getmypid();
foreach (['controllers', 'etc', 'log', 'tmp', 'templates'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});

// plugins traceurs et plugins de statut
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
	// pré-plugin qui retourne un statut selon le contrôleur demandé
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
	// post-plugin qui retourne un statut selon le contrôleur demandé
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
	// contrôleurs de scénario : __wakeup trace 'w', l'action trace 'C', __sleep trace 's'
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

// configuration : plugins globaux traceurs + plugins de statut
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

/* ********** MICRO-FRAMEWORK DE TEST ********** */
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
// exécute une requête et retourne la trace produite
function getTrace(string $url) : ?string {
	global $appPath;
	$test = new \Temma\Web\Test($appPath, "$appPath/etc/temma.php");
	$data = $test->execData($url);
	return (is_array($data) ? ($data['trace'] ?? null) : null);
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Flux d'exécution : EXEC_STOP vs EXEC_HALT\n"));
// pré-plugins A, [statut], B ; contrôleur w, C, s ; post-plugins P, [statut], Q
check("contrôle : chaîne complète (ABwCsPQ)",
      getTrace('/normal/run') === 'ABwCsPQ');
check("EXEC_STOP en pré-plugin : arrête les pré-plugins, exécute contrôleur et post-plugins (AwCsPQ)",
      getTrace('/prestop/run') === 'AwCsPQ');
check("EXEC_HALT en pré-plugin : va directement à la vue (A)",
      getTrace('/prehalt/run') === 'A');
check("EXEC_STOP dans __wakeup() : saute action et __sleep(), exécute les post-plugins (ABwPQ)",
      getTrace('/wakestop/run') === 'ABwPQ');
check("EXEC_STOP dans l'action : saute __sleep(), exécute les post-plugins (ABwCPQ)",
      getTrace('/actstop/run') === 'ABwCPQ');
check("exception FlowStop dans l'action : même comportement (ABwCPQ)",
      getTrace('/actstopex/run') === 'ABwCPQ');
check("EXEC_HALT dans l'action : saute __sleep() et les post-plugins (ABwC)",
      getTrace('/acthalt/run') === 'ABwC');
check("EXEC_STOP dans __sleep() : exécute les post-plugins (ABwCsPQ)",
      getTrace('/sleepstop/run') === 'ABwCsPQ');
check("EXEC_STOP en post-plugin : arrête les post-plugins (ABwCsP)",
      getTrace('/poststop/run') === 'ABwCsP');
check("EXEC_HALT en post-plugin : arrête les post-plugins (ABwCsP)",
      getTrace('/posthalt/run') === 'ABwCsP');

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
