#!/usr/bin/php
<?php

/**
 * Validation script for the copyFrom() method of data sources.
 * Checks that the local file can be created (it was rejected when it didn't exist yet),
 * overwritten, and that an unwritable destination raises an IO exception.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** ENVIRONMENT ********** */
$tmpPath = sys_get_temp_dir() . '/temma-copy-test-' . getmypid();
mkdir("$tmpPath/root", 0755, true);
mkdir("$tmpPath/readonly", 0555);
register_shutdown_function(function() use ($tmpPath) {
	chmod("$tmpPath/readonly", 0755);
	exec('rm -rf ' . escapeshellarg($tmpPath));
});
file_put_contents("$tmpPath/root/source.txt", 'File content');
// data source which uses the copyFrom() method of the base class
$source = new class extends \Temma\Base\Datasource {
	/**
	 * Returns the data associated to a key.
	 * @param	string	$key			Key of the data.
	 * @param	mixed	$defaultOrCallback	Not used.
	 * @param	mixed	$options		Not used.
	 * @return	mixed	The data, or null if the key is unknown.
	 */
	public function read(string $key, mixed $defaultOrCallback=null, mixed $options=null) : mixed {
		return (($key == 'known') ? 'Data content' : null);
	}
};
$file = new \Temma\Datasources\File("$tmpPath/root");

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
// returns the exception thrown by a function, or null
function exception(callable $function) : ?\Throwable {
	try {
		$function();
	} catch (\Throwable $e) {
		return ($e);
	}
	return (null);
}
// calls copyFrom(), returns false if an exception is thrown
function copied(\Temma\Base\Datasource $source, string $key, string $localPath) : bool {
	try {
		return ($source->copyFrom($key, $localPath));
	} catch (\Throwable $e) {
		return (false);
	}
}

/* ********** TESTS ********** */
print(TµAnsi::bold("Datasource::copyFrom()\n"));
check("new local file",
      copied($source, 'known', "$tmpPath/new.txt") && file_get_contents("$tmpPath/new.txt") === 'Data content');
file_put_contents("$tmpPath/existing.txt", 'Old content');
check("existing local file",
      copied($source, 'known', "$tmpPath/existing.txt") && file_get_contents("$tmpPath/existing.txt") === 'Data content');
check("new local file in a new directory",
      copied($source, 'known', "$tmpPath/sub/dir/new.txt") && file_get_contents("$tmpPath/sub/dir/new.txt") === 'Data content');
check("unknown key: false, no file created",
      !copied($source, 'unknown', "$tmpPath/unknown.txt") && !file_exists("$tmpPath/unknown.txt"));
if (posix_getuid() !== 0) {
	check("unwritable directory: IO exception",
	      exception(fn() => $source->copyFrom('known', "$tmpPath/readonly/new.txt")) instanceof \Temma\Exceptions\IO);
}

print("\n" . TµAnsi::bold("File::copyFrom()\n"));
check("new local file",
      copied($file, 'source.txt', "$tmpPath/file-new.txt") && file_get_contents("$tmpPath/file-new.txt") === 'File content');
if (posix_getuid() !== 0) {
	check("unwritable directory: IO exception",
	      exception(fn() => $file->copyFrom('source.txt', "$tmpPath/readonly/new.txt")) instanceof \Temma\Exceptions\IO);
}

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
