#!/usr/bin/php
<?php

/**
 * Validation script for the AI skills synchronization mechanism (skills/sync.php),
 * and for the frontmatter of the skills located in the framework's skills/ directory.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** SANDBOX ********** */
$sandbox = sys_get_temp_dir() . '/temma-skills-test-' . getmypid();
$vendorSkills = "$sandbox/vendor/digicreon/temma-lib/skills";
if (!mkdir($vendorSkills, 0755, true)) {
	fwrite(STDERR, "Unable to create the sandbox '$sandbox'.\n");
	exit(2);
}
copy(__DIR__ . '/../skills/sync.php', "$vendorSkills/sync.php");
register_shutdown_function(function() use ($sandbox) {
	exec('rm -rf ' . escapeshellarg($sandbox));
});

/* ********** HELPERS ********** */
// creates a fake skill in the sandbox vendor
function makeSkill(string $name) : void {
	global $vendorSkills;
	@mkdir("$vendorSkills/$name", 0755, true);
	file_put_contents("$vendorSkills/$name/SKILL.md", "---\nname: $name\ndescription: Test skill.\n---\nBody.\n");
}
// removes a fake skill from the vendor
function dropSkill(string $name) : void {
	global $vendorSkills;
	exec('rm -rf ' . escapeshellarg("$vendorSkills/$name"));
}
// runs the synchronization script from the sandbox root
function runSync() : string {
	global $sandbox;
	exec('cd ' . escapeshellarg($sandbox) . ' && php vendor/digicreon/temma-lib/skills/sync.php 2>&1', $output, $code);
	// the script (external process) modifies the filesystem: clear PHP's stat cache
	clearstatcache(true);
	return (implode("\n", $output) . (($code != 0) ? "\n[exit=$code]" : ''));
}
// writes the sandbox's composer.json
function setComposer(?array $temmaSkills) : void {
	global $sandbox;
	$conf = ['name' => 'test/test'];
	if ($temmaSkills !== null)
		$conf['extra'] = ['temma-skills' => $temmaSkills];
	file_put_contents("$sandbox/composer.json", json_encode($conf));
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

/* ********** TESTS: INSTALLATION ********** */
print(TµAnsi::bold("Initial installation\n"));
makeSkill('temma-aaa');
makeSkill('temma-bbb');
file_put_contents("$vendorSkills/notaskill.txt", 'not a skill');
mkdir("$vendorSkills/no-skill-md");
$out = runSync();
check("symlink created in .claude/skills",
      is_link("$sandbox/.claude/skills/temma-aaa"));
check("symlink created in .agents/skills",
      is_link("$sandbox/.agents/skills/temma-aaa"));
check("relative symlink to the vendored directory",
      readlink("$sandbox/.claude/skills/temma-aaa") === '../../vendor/digicreon/temma-lib/skills/temma-aaa');
check("the symlink resolves to the skill's SKILL.md",
      is_file("$sandbox/.claude/skills/temma-aaa/SKILL.md"));
check("a vendor file is not symlinked (sync.php, notaskill.txt)",
      !file_exists("$sandbox/.claude/skills/sync.php") && !file_exists("$sandbox/.claude/skills/notaskill.txt"));
check("a directory without SKILL.md is not symlinked",
      !file_exists("$sandbox/.claude/skills/no-skill-md"));
check("installation report displayed",
      str_contains($out, 'installed'));

print(TµAnsi::bold("Idempotence and update\n"));
$out = runSync();
check("second run: no change reported, no warning",
      !str_contains($out, 'installed') && !str_contains($out, 'warning'));
makeSkill('temma-ccc');
runSync();
check("new vendored skill picked up on re-run",
      is_link("$sandbox/.claude/skills/temma-ccc") && is_link("$sandbox/.agents/skills/temma-ccc"));

print(TµAnsi::bold("Dead link purge\n"));
dropSkill('temma-ccc');
runSync();
check("vendored dead link purged after skill removal",
      !file_exists("$sandbox/.claude/skills/temma-ccc") && !is_link("$sandbox/.claude/skills/temma-ccc"));
symlink('/nonexistent/path', "$sandbox/.claude/skills/my-broken-skill");
runSync();
check("NON-vendored dead link left untouched",
      is_link("$sandbox/.claude/skills/my-broken-skill"));
unlink("$sandbox/.claude/skills/my-broken-skill");

print(TµAnsi::bold("Developer's personal skills\n"));
mkdir("$sandbox/.claude/skills/my-own-skill", 0755, true);
file_put_contents("$sandbox/.claude/skills/my-own-skill/SKILL.md", "---\nname: my-own-skill\ndescription: x\n---\n");
runSync();
check("personal skill (real directory) left untouched",
      is_dir("$sandbox/.claude/skills/my-own-skill") && !is_link("$sandbox/.claude/skills/my-own-skill"));
// name conflict: a real directory bearing the name of a vendored skill
unlink("$sandbox/.claude/skills/temma-bbb");
mkdir("$sandbox/.claude/skills/temma-bbb", 0755, true);
file_put_contents("$sandbox/.claude/skills/temma-bbb/SKILL.md", "---\nname: temma-bbb\ndescription: version locale\n---\n");
$out = runSync();
check("name conflict: the local version wins",
      !is_link("$sandbox/.claude/skills/temma-bbb") && is_dir("$sandbox/.claude/skills/temma-bbb"));
check("name conflict: warning displayed",
      str_contains($out, 'warning') && str_contains($out, 'temma-bbb'));
exec('rm -rf ' . escapeshellarg("$sandbox/.claude/skills/temma-bbb"));
runSync();

print(TµAnsi::bold("Exclusions and deactivation (composer.json)\n"));
setComposer(['exclude' => ['temma-aaa']]);
runSync();
check("excluded skill: symlink removed",
      !is_link("$sandbox/.claude/skills/temma-aaa") && !is_link("$sandbox/.agents/skills/temma-aaa"));
check("the other skills remain installed",
      is_link("$sandbox/.claude/skills/temma-bbb"));
setComposer(null);
runSync();
check("exclusion lifted: symlink recreated",
      is_link("$sandbox/.claude/skills/temma-aaa"));
setComposer(['disabled' => true]);
unlink("$sandbox/.claude/skills/temma-aaa");
$out = runSync();
check("synchronization disabled: nothing is recreated, message displayed",
      !file_exists("$sandbox/.claude/skills/temma-aaa") && str_contains($out, 'disabled'));
setComposer(null);
runSync();

/* ********** TESTS: FRONTMATTER OF REAL SKILLS ********** */
print(TµAnsi::bold("Framework skills frontmatter\n"));
$skillsDir = realpath(__DIR__ . '/../skills');
$realSkills = glob("$skillsDir/*/SKILL.md");
if (!$realSkills) {
	print(TµAnsi::faint("(no skill in skills/ for now)\n"));
} else {
	foreach ($realSkills as $skillFile) {
		$dirName = basename(dirname($skillFile));
		$content = file_get_contents($skillFile);
		$ok = (bool)preg_match('/^---\n(.*?)\n---\n(.*)$/s', $content, $matches);
		$name = $description = null;
		$bodyLines = 0;
		if ($ok) {
			$frontmatter = $matches[1];
			$bodyLines = substr_count($matches[2], "\n") + 1;
			if (preg_match('/^name:\s*(.+)$/m', $frontmatter, $m))
				$name = trim($m[1]);
			if (preg_match('/^description:\s*(.+)$/m', $frontmatter, $m))
				$description = trim($m[1]);
		}
		check("$dirName: frontmatter present, compliant name, valid description, body < 500 lines",
		      $ok &&
		      $name === $dirName &&
		      (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string)$name) &&
		      $description && mb_strlen($description) <= 1024 &&
		      $bodyLines < 500);
	}
}

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed failed test(s) out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
