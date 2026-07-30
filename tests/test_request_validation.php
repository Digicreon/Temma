#!/usr/bin/php
<?php

/**
 * Validation script for the "numeric key + string value" contract shortcut
 * in Request::validateInput() and Request::validateFiles()
 * (['aa'] equivalent to ['aa' => null]: presence required, content not validated).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Application as TµApplicationException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

$request = new \Temma\Web\Request(false);

/* ********** HELPERS ********** */
// directory for the fake upload files
$tmpDir = sys_get_temp_dir() . '/temma-req-test-' . getmypid();
mkdir($tmpDir, 0755, true);
register_shutdown_function(function() use ($tmpDir) {
	exec('rm -rf ' . escapeshellarg($tmpDir));
});
// creates a fake $_FILES entry pointing to a real temporary file
function makeFile(string $field, string $content) : void {
	global $tmpDir;
	$path = "$tmpDir/$field.bin";
	file_put_contents($path, $content);
	$_FILES[$field] = [
		'name'     => "$field.bin",
		'type'     => 'application/octet-stream',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => mb_strlen($content, 'ascii'),
	];
}
// executes a closure and tells whether an Application exception was thrown
function fails(callable $fn) : bool {
	try {
		$fn();
		return (false);
	} catch (TµApplicationException $e) {
		return (true);
	}
}

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

/* ********** TESTS: validateInput() ********** */
print(TµAnsi::bold("validateInput(): numeric key shortcut\n"));
$_GET = [];
$_POST = ['name' => 'Alice', 'age' => '42'];
check("['name']: parameter present, any content",
      !fails(fn() => $request->validateInput(['name', 'age' => 'int'], 'POST')));
check("the parameter without a contract is kept as is",
      ($_POST['name'] ?? null) === 'Alice' && ($_POST['age'] ?? null) === 42);
$_POST = ['age' => '42'];
check("['name']: parameter missing, failure",
      fails(fn() => $request->validateInput(['name', 'age' => 'int'], 'POST')));
$_POST = ['age' => '42'];
check("['name?']: optional parameter missing, success",
      !fails(fn() => $request->validateInput(['name?', 'age' => 'int'], 'POST')));
$_POST = ['name' => 'Bob', 'extra' => 'x'];
check("wildcard '...' preserved in a mixed list",
      !fails(fn() => $request->validateInput(['name', '...'], 'POST')) &&
      ($_POST['extra'] ?? null) === 'x');
$_POST = ['name' => 'Bob'];
check("explicit equivalence: ['name' => null] also works",
      !fails(fn() => $request->validateInput(['name' => null], 'POST')));

/* ********** TESTS: validateFiles() ********** */
print(TµAnsi::bold("validateFiles(): numeric key shortcut\n"));
$_FILES = [];
makeFile('id_card', 'binary-data');
check("['id_card']: file present, success",
      !fails(fn() => $request->validateFiles(['id_card'])));
$_FILES = [];
check("['id_card']: file missing, failure with the right field name",
      fails(fn() => $request->validateFiles(['id_card'])));
try {
	$request->validateFiles(['id_card']);
} catch (TµApplicationException $e) {
	check("the error message mentions 'id_card' (not '0')",
	      str_contains($e->getMessage(), 'id_card'));
}
$_FILES = [];
check("['id_card?']: optional file missing, success",
      !fails(fn() => $request->validateFiles(['id_card?'])));
// presence only: the content must not be read (tmp_name intentionally invalid)
$_FILES = ['id_card' => [
	'name'     => 'id.bin',
	'type'     => 'application/octet-stream',
	'tmp_name' => '/nonexistent/path.bin',
	'error'    => UPLOAD_ERR_OK,
	'size'     => 10,
]];
$warned = false;
set_error_handler(function() use (&$warned) { $warned = true; return (true); });
$failedValidation = fails(fn() => $request->validateFiles(['id_card']));
restore_error_handler();
check("null contract: the file is not read (no access to the tmp_name)",
      !$failedValidation && !$warned);
// mixed forms and wildcard
$_FILES = [];
makeFile('id_card', 'raw');
makeFile('avatar', 'raw');
makeFile('other', 'raw');
check("mixed form: ['id_card', 'avatar' => null, '...'] in strict mode",
      !fails(fn() => $request->validateFiles(['id_card', 'avatar' => null, '...'], true)));
// non-regression: a content contract is still enforced
$_FILES = [];
makeFile('data', '{"num": 42}');
check("non-regression: content contract enforced (valid json)",
      !fails(fn() => $request->validateFiles(['data' => 'json'])));
$_FILES = [];
makeFile('data', 'not json at all');
check("non-regression: content contract enforced (invalid json, failure)",
      fails(fn() => $request->validateFiles(['data' => 'json'])));

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
