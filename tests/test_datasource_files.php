#!/usr/bin/php
<?php

/**
 * Validation script for the management of local files by data sources:
 * - copyFrom() of the base class and of the File data source (the local file was rejected when it didn't exist yet);
 * - File data source: DSN parsing, umask, creation of files and directories by write() and copyTo(),
 *   files management like S3 (recursive search, only files, empty directories removed);
 * - File data source: paths normalisation (backslashes, '.' segments, repeated slashes), and rejection of the paths
 *   which could reach a file outside of the root path ('..' segments, null bytes) by all the methods.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

/* ********** ENVIRONMENT ********** */
$tmpPath = sys_get_temp_dir() . '/temma-files-test-' . getmypid();
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
$file = \Temma\Datasources\File::factory("file://$tmpPath/root");

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
// returns the content of a file, or null if it doesn't exist
function content(string $path) : ?string {
	return (is_file($path) ? file_get_contents($path) : null);
}
// returns the permissions of a file or a directory
function permissions(string $path) : int {
	clearstatcache();
	return (fileperms($path) & 0777);
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

print("\n" . TµAnsi::bold("File data source\n"));
check("DSN without umask: absolute root path",
      !exception(fn() => $file->write('dsn.txt', 'Data')) && content("$tmpPath/root/dsn.txt") === 'Data');
foreach (['file://relative/path', 'file://007/var/data', 'file:///', 'files:///var/data'] as $dsn) {
	check("invalid DSN '$dsn': exception",
	      exception(fn() => \Temma\Datasources\File::factory($dsn)) instanceof \Temma\Exceptions\Database);
}
$umask = umask();
$file77 = \Temma\Datasources\File::factory("file://0077$tmpPath/root");
check("DSN with umask: umask applied to the created directory and file, then restored",
      !exception(fn() => $file77->write('umask/new.txt', 'Data')) && content("$tmpPath/root/umask/new.txt") === 'Data' &&
      permissions("$tmpPath/root/umask") === 0700 && permissions("$tmpPath/root/umask/new.txt") === 0600 && umask() === $umask);
check("write(): new file in new directories",
      !exception(fn() => $file->write('sub/dir/new.txt', 'Data')) && content("$tmpPath/root/sub/dir/new.txt") === 'Data');
check("write(): existing file",
      !exception(fn() => $file->write('sub/dir/new.txt', 'New data')) && content("$tmpPath/root/sub/dir/new.txt") === 'New data');
check("write(): permissions",
      !exception(fn() => $file->write('perm.txt', 'Data', 0640)) && permissions("$tmpPath/root/perm.txt") === 0640);
$file['array/new.txt'] = 'Data';
check("array syntax: new file in a new directory",
      is_file("$tmpPath/root/array/new.txt") && $file['array/new.txt'] === 'Data');
check("copyTo(): new file in new directories, with permissions",
      !exception(fn() => $file->copyTo('copy/dir/new.txt', "$tmpPath/root/source.txt", 0640)) &&
      content("$tmpPath/root/copy/dir/new.txt") === 'File content' && permissions("$tmpPath/root/copy/dir/new.txt") === 0640);
// files management like S3: recursive search, only files, empty directories removed
$search = \Temma\Datasources\File::factory("file://$tmpPath/search");
$search['dir/b.txt'] = 'B';
$search['dir/a.txt'] = 'A';
$search['dir/sub/c.txt'] = 'C';
$search['dir/.hidden'] = 'H';
$search['other.json'] = 'O';
mkdir("$tmpPath/search/empty");
check("find(): paths relative to the root path, sub-directories included, no directories, sorted",
      $search->find('dir/*') === ['dir/a.txt', 'dir/b.txt', 'dir/sub/c.txt']);
check("find(): '*' matches '/'",
      $search->find('*.txt') === ['dir/a.txt', 'dir/b.txt', 'dir/sub/c.txt']);
check("find(): fixed path",
      $search->find('dir/sub/c.txt') === ['dir/sub/c.txt'] && $search->find('dir/sub') === []);
check("find(): hidden files skipped, but readable from their path",
      !in_array('dir/.hidden', $search->find('*')) && $search['dir/.hidden'] === 'H');
check("find(): raw values",
      $search->find('dir/*', true) === ['dir/a.txt' => '"A"', 'dir/b.txt' => '"B"', 'dir/sub/c.txt' => '"C"']);
check("search(): JSON-decoded values",
      $search->search('dir/*', true) === ['dir/a.txt' => 'A', 'dir/b.txt' => 'B', 'dir/sub/c.txt' => 'C']);
check("search(): reverse order, offset and limit",
      $search->search('dir/*', false, true) === ['dir/sub/c.txt', 'dir/b.txt', 'dir/a.txt'] &&
      $search->search('dir/*', false, null, 1, 1) === ['dir/b.txt']);
check("count(): all files of the tree",
      count($search) === 4 && $search->count('dir/*') === 3 && $search->count('nothing/*') === 0);
error_clear_last();
check("directory: not set, no content, no PHP error",
      !$search->isSet('dir') && $search->read('dir') === null && $search->read('dir', 'default') === 'default' &&
      $search['dir'] === null && $search->copyFrom('dir', "$tmpPath/dir-copy.txt") === false && error_get_last() === null);
$search->remove('dir/sub/c.txt');
check("remove(): empty parent directory removed",
      !file_exists("$tmpPath/search/dir/sub") && is_file("$tmpPath/search/dir/a.txt"));
$search->clear('dir/*');
check("clear(): files removed, non-empty directory kept (hidden file)",
      $search->find('dir/*') === [] && is_file("$tmpPath/search/dir/.hidden") && is_file("$tmpPath/search/other.json"));
$search->remove('dir/.hidden');
$search->remove('other.json');
check("remove(): the root directory is never removed",
      !file_exists("$tmpPath/search/dir") && is_dir("$tmpPath/search") && count($search) === 0);
if (posix_getuid() !== 0) {
	check("write(): unwritable directory: IO exception",
	      exception(fn() => \Temma\Datasources\File::factory("file://$tmpPath/readonly")->write('new.txt', 'Data')) instanceof \Temma\Exceptions\IO);
}

// path traversal
print("\n" . TµAnsi::bold("File data source: paths\n"));
file_put_contents("$tmpPath/secret.txt", 'Secret');
$paths = new \Temma\Datasources\File("$tmpPath/root/paths");
check("write(): repeated slashes and '.' segments removed",
      !exception(fn() => $paths->write('/a//b/./c.txt/', 'Data')) && content("$tmpPath/root/paths/a/b/c.txt") === 'Data');
check("read(), isSet(): backslashes are directory separators",
      $paths->read('a\\b\\c.txt') === 'Data' && $paths->isSet('.\\a\\b\\c.txt'));
check("'....' is a regular file name, inside the root path",
      $paths->read('....//secret.txt') === null && $paths->read('....//....//secret.txt') === null &&
      !exception(fn() => $paths->write('....//x.txt', 'X')) && content("$tmpPath/root/paths/..../x.txt") === 'X');
$badPaths = ['../../secret.txt', '..\\..\\secret.txt', 'a/../../../secret.txt', 'a/./../../../secret.txt', '/../../secret.txt',
             '..', 'a/..', "secret.txt\0.png", '../..//secret.txt'];
foreach ($badPaths as $badPath) {
	$label = addcslashes($badPath, "\0");
	check("'$label': read(), isSet(), find(), search(), get() and count() raise an IO exception",
	      exception(fn() => $paths->read($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->isSet($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->find($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->search($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->get($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->count($badPath)) instanceof \Temma\Exceptions\IO);
	check("'$label': write(), set(), remove(), clear() raise an IO exception, the outside file is unchanged",
	      exception(fn() => $paths->write($badPath, 'Hacked')) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->set($badPath, 'Hacked')) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->remove($badPath)) instanceof \Temma\Exceptions\IO &&
	      exception(fn() => $paths->clear($badPath)) instanceof \Temma\Exceptions\IO &&
	      content("$tmpPath/secret.txt") === 'Secret');
	check("'$label': copyFrom() and copyTo() raise an IO exception, no file copied",
	      exception(fn() => $paths->copyFrom($badPath, "$tmpPath/stolen.txt")) instanceof \Temma\Exceptions\IO &&
	      !file_exists("$tmpPath/stolen.txt") &&
	      exception(fn() => $paths->copyTo(str_replace('secret', 'copied', $badPath), "$tmpPath/root/source.txt")) instanceof \Temma\Exceptions\IO &&
	      !file_exists("$tmpPath/copied.txt") && !file_exists("$tmpPath/root/copied.txt"));
}
check("mWrite() and mCopyFrom() skip the bad paths",
      $paths->mWrite(['../../secret.txt' => 'Hacked', 'ok.txt' => 'OK']) === 1 && content("$tmpPath/secret.txt") === 'Secret' &&
      $paths->mCopyFrom(['../../secret.txt' => "$tmpPath/stolen.txt", 'ok.txt' => "$tmpPath/ok-copy.txt"]) === 1 &&
      !file_exists("$tmpPath/stolen.txt") && content("$tmpPath/ok-copy.txt") === 'OK');
check("mRemove() stops at the first bad path",
      exception(fn() => $paths->mRemove(['../../secret.txt'])) instanceof \Temma\Exceptions\IO && is_file("$tmpPath/secret.txt"));

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
