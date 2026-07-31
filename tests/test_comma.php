#!/usr/bin/php
<?php

/**
 * Comma (command-line manager) validation script:
 * - command designation forms: "Obj action", "Obj:action", "Obj::action", "Obj/action";
 * - positional parameters, URL-like ("Obj/action/val1/val2") or as separate arguments;
 * - named parameters ("--param=value", "--flag") and combination with positional ones;
 * - the '--' end-of-options marker for values starting with a dash;
 * - automatic fallback on the \Temma\Cli namespace for framework's built-in commands.
 * Builds a sandbox application and runs the real bin/comma script as a subprocess.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** SANDBOX APPLICATION ********** */
$appPath = sys_get_temp_dir() . '/temma-comma-test-' . getmypid();
foreach (['bin', 'cli', 'cli/Temma/Cli', 'etc', 'log', 'tmp'] as $dir)
	mkdir("$appPath/$dir", 0755, true);
register_shutdown_function(function() use ($appPath) {
	exec('rm -rf ' . escapeshellarg($appPath));
});
// real comma script (copied, because it resolves the project root from its own path)
copy(__DIR__ . '/../bin/comma', "$appPath/bin/comma");
// framework library
symlink(realpath(__DIR__ . '/../lib'), "$appPath/lib");
// configuration
file_put_contents("$appPath/etc/temma.php", "<?php return ['application' => ['dataSources' => []], 'loglevels' => 'CRIT'];");
// CLI test controller
file_put_contents("$appPath/cli/TestMath.php", <<<'EOT'
<?php
class TestMath extends \Temma\Web\Controller {
	public function __invoke() {
		print("root\n");
	}
	public function add(string $a='0', string $b='0', string $c='0') {
		print(((int)$a + (int)$b + (int)$c) . "\n");
	}
	public function show(string $text='') {
		print("[$text]\n");
	}
	public function flag(bool $verbose=false) {
		print($verbose ? "on\n" : "off\n");
	}
}
EOT);
// application controller taking precedence over the \Temma\Cli fallback
file_put_contents("$appPath/cli/Hello.php", <<<'EOT'
<?php
class Hello extends \Temma\Web\Controller {
	public function world() {
		print("app\n");
	}
}
EOT);
// fake framework built-in commands (autoloaded from the 'cli' directory of the sandbox)
file_put_contents("$appPath/cli/Temma/Cli/Hello.php", <<<'EOT'
<?php
namespace Temma\Cli;
class Hello extends \Temma\Web\Controller {
	public function world() {
		print("framework\n");
	}
}
EOT);
file_put_contents("$appPath/cli/Temma/Cli/Greet.php", <<<'EOT'
<?php
namespace Temma\Cli;
class Greet extends \Temma\Web\Controller {
	public function hi(string $name='nobody') {
		print("hi $name\n");
	}
}
EOT);
// fake framework built-in command in a sub-namespace
mkdir("$appPath/cli/Temma/Cli/Sub", 0755, true);
file_put_contents("$appPath/cli/Temma/Cli/Sub/Task.php", <<<'EOT'
<?php
namespace Temma\Cli\Sub;
class Task extends \Temma\Web\Controller {
	public function run() {
		print("subtask\n");
	}
}
EOT);
// namespaced application controller
mkdir("$appPath/cli/App", 0755, true);
file_put_contents("$appPath/cli/App/Deep.php", <<<'EOT'
<?php
namespace App;
class Deep extends \Temma\Web\Controller {
	public function run(string $x='') {
		print("deep:$x\n");
	}
}
EOT);

/* ********** TEST HARNESS ********** */
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
// run a comma command in the sandbox, return [stdout, exit code]
function comma(string $args) : array {
	global $appPath;
	exec('cd ' . escapeshellarg($appPath) . " && php bin/comma $args 2>/dev/null", $output, $code);
	return ([implode("\n", $output), $code]);
}

/* ********** COMMAND DESIGNATION FORMS ********** */
print(TµAnsi::bold("Command designation forms\n"));
[$out, $code] = comma('TestMath add --a=3 --b=4');
check("historical form 'Obj action --params'", $out === '7' && $code === 0);
[$out, ] = comma('TestMath:add --a=3 --b=4');
check("colon form 'Obj:action'", $out === '7');
[$out, ] = comma('TestMath::add --a=3 --b=4');
check("double-colon form 'Obj::action'", $out === '7');
[$out, ] = comma('TestMath/add --a=3 --b=4');
check("slash form 'Obj/action'", $out === '7');
[$out, ] = comma('TestMath');
check("root action 'Obj' alone", $out === 'root');
[$out, ] = comma("'\\App\\Deep/run' --x=ok");
check("namespaced object '\\App\\Deep/run'", $out === 'deep:ok');

/* ********** POSITIONAL PARAMETERS ********** */
print(TµAnsi::bold("Positional parameters\n"));
[$out, ] = comma('TestMath/add/3/4');
check("URL-like 'Obj/action/val1/val2'", $out === '7');
[$out, ] = comma('TestMath/add/3/4/-5');
check("URL-like with negative value", $out === '2');
[$out, ] = comma('TestMath:add/3/4');
check("mixed separators 'Obj:action/val1/val2'", $out === '7');
[$out, ] = comma('TestMath add 3 4');
check("space-separated values 'Obj action val1 val2'", $out === '7');
[$out, ] = comma('TestMath/add 3 4');
check("slash form with space-separated values", $out === '7');
[$out, ] = comma('TestMath/add/3 4');
check("URL-like and space-separated values combined", $out === '7');
[$out, ] = comma("TestMath show /var/www");
check("space-separated value containing slashes", $out === '[/var/www]');

/* ********** NAMED AND COMBINED PARAMETERS ********** */
print(TµAnsi::bold("Named and combined parameters\n"));
[$out, ] = comma('TestMath/add/3 --b=4');
check("positional combined with named", $out === '7');
[$out, ] = comma('TestMath/flag --verbose');
check("boolean flag parameter", $out === 'on');
[$out, ] = comma("TestMath/show --text='a b'");
check("quoted named value", $out === '[a b]');

/* ********** THE '--' MARKER ********** */
print(TµAnsi::bold("The '--' end-of-options marker\n"));
[$out, ] = comma('TestMath/add 3 4 -- -5');
check("negative value after '--'", $out === '2');
[$out, ] = comma('TestMath/add --b=3 -- -5');
check("'--' marker after a named parameter", $out === '-2');
[$out, ] = comma('TestMath/show -- --raw');
check("value looking like an option after '--'", $out === '[--raw]');

/* ********** ERRORS ********** */
print(TµAnsi::bold("Errors\n"));
[$out, $code] = comma('TestMath/add -5');
check("single-dash argument rejected", $code === 2);
[$out, $code] = comma('TestMath --a=3');
check("no parameter allowed for the root action", $code === 1);
[$out, $code] = comma('NoSuchObject/action');
check("unknown object", $code === 3);

/* ********** \Temma\Cli FALLBACK ********** */
print(TµAnsi::bold("Fallback on the \\Temma\\Cli namespace\n"));
[$out, ] = comma('Greet/hi/Bob');
check("fallback 'Greet/hi' => \\Temma\\Cli\\Greet", $out === 'hi Bob');
[$out, ] = comma('Hello/world');
check("application controller takes precedence", $out === 'app');
[$out, ] = comma("'\\Temma\\Cli\\Hello/world'");
check("explicit framework namespace still works", $out === 'framework');
[$out, ] = comma("'Sub\\Task/run'");
check("fallback works for sub-namespaces", $out === 'subtask');
[$out, ] = comma("'App\\Deep/run' --x=ok");
check("relative application namespace takes precedence", $out === 'deep:ok');

/* ********** HELP ********** */
print(TµAnsi::bold("Help\n"));
[$out, $code] = comma('help TestMath/add');
check("'help Obj/action' accepted", $code === 0 && str_contains($out, 'add') && !str_contains($out, 'Action: show'));
[$out, $code] = comma('help Greet');
check("'help' uses the \\Temma\\Cli fallback", $code === 0 && str_contains($out, 'hi'));

/* ********** RESULT ********** */
print(TµAnsi::bold($failed ? "$failed test(s) failed out of $count\n" : "All tests passed ($count)\n"));
exit($failed ? 1 : 0);
