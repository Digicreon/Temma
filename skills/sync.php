<?php

/**
 * Temma AI skills synchronization script.
 *
 * Installs the AI skills shipped with the digicreon/temma-lib Composer package into the
 * project's agent directories ('.claude/skills/' for Claude Code, '.agents/skills/' for
 * OpenAI Codex and compatible tools), as one symlink per skill.
 *
 * Executed from the project root by the Composer hooks declared in the project skeletons
 * (post-create-project-cmd, post-install-cmd, post-update-cmd). It can also be run manually:
 * `php vendor/digicreon/temma-lib/skills/sync.php`
 *
 * Rules:
 * - one symlink per skill (relative path), so developers can add their own skills alongside;
 * - an existing entry that is not a symlink (a developer's own skill with the same name) is
 *   never touched (a warning is displayed);
 * - dangling symlinks pointing to the package's skills directory are removed (a skill that
 *   was removed or renamed upstream); other symlinks are never touched;
 * - if symlink creation fails (e.g. Windows without the required privilege), the skill is
 *   copied instead; copies are never updated afterwards;
 * - opt-out via the project's composer.json:
 *   "extra": { "temma-skills": { "disabled": true, "exclude": ["temma-xxx", ...] } }
 * - never makes Composer fail: problems are reported as warnings, exit code is always 0.
 *
 * @author	Amaury Bouchard <amaury@amaury.net>
 * @copyright	© 2026, Amaury Bouchard
 * @link	https://www.temma.net/en/documentation/skills
 */

// path of the vendored skills (the directory containing this script) and of the project root
$sourceDir = __DIR__;
$projectRoot = getcwd();
// target directories (relative to the project root), one per AI tool family
$targetDirs = [
	'.claude/skills',	// Claude Code
	'.agents/skills',	// OpenAI Codex and compatible tools
];
// path fragment used to identify symlinks managed by this script
$vendorFragment = 'vendor/digicreon/temma-lib/skills';

// read the opt-out configuration from the project's composer.json
$disabled = false;
$excluded = [];
if (is_readable("$projectRoot/composer.json")) {
	$composerConf = json_decode(file_get_contents("$projectRoot/composer.json"), true);
	$skillsConf = $composerConf['extra']['temma-skills'] ?? null;
	if (is_array($skillsConf)) {
		$disabled = (bool)($skillsConf['disabled'] ?? false);
		if (is_array($skillsConf['exclude'] ?? null))
			$excluded = $skillsConf['exclude'];
	}
}
if ($disabled) {
	print("Temma skills: synchronization disabled (composer.json: extra.temma-skills.disabled).\n");
	exit(0);
}

// list the vendored skills (each skill is a sub-directory containing a SKILL.md file)
$skills = [];
foreach ((scandir($sourceDir) ?: []) as $entry) {
	if ($entry[0] == '.')
		continue;
	if (is_dir("$sourceDir/$entry") && is_file("$sourceDir/$entry/SKILL.md"))
		$skills[] = $entry;
}

// synchronize each target directory
$installed = 0;
$removed = 0;
foreach ($targetDirs as $targetDir) {
	$fullTargetDir = "$projectRoot/$targetDir";
	if (!is_dir($fullTargetDir) && !@mkdir($fullTargetDir, 0755, true)) {
		print("Temma skills: warning, unable to create the '$targetDir' directory. Skipped.\n");
		continue;
	}
	// relative path from the target directory to the vendored skills directory
	$depth = substr_count(trim($targetDir, '/'), '/') + 1;
	$relativeSource = str_repeat('../', $depth) . $vendorFragment;
	// addition pass
	foreach ($skills as $name) {
		$linkPath = "$fullTargetDir/$name";
		if (in_array($name, $excluded)) {
			// excluded skill: remove its managed symlink if there is one
			if (is_link($linkPath) && str_contains((string)readlink($linkPath), $vendorFragment)) {
				unlink($linkPath);
				$removed++;
			}
			continue;
		}
		if (is_link($linkPath)) {
			// already a symlink (managed and up-to-date, or created by the developer): leave it
			continue;
		}
		if (file_exists($linkPath)) {
			print("Temma skills: warning, '$targetDir/$name' already exists and is not a symlink; the local version is left untouched.\n");
			continue;
		}
		if (@symlink("$relativeSource/$name", $linkPath)) {
			$installed++;
		} else if (_temmaSkillsCopy("$sourceDir/$name", $linkPath)) {
			// fallback (e.g. Windows): plain copy, never updated afterwards
			print("Temma skills: notice, '$targetDir/$name' installed as a copy (symlink creation failed); it will not be updated automatically.\n");
			$installed++;
		} else {
			print("Temma skills: warning, unable to install the '$name' skill into '$targetDir'.\n");
		}
	}
	// cleanup pass: remove dangling symlinks pointing to the vendored skills directory
	foreach ((scandir($fullTargetDir) ?: []) as $entry) {
		if ($entry[0] == '.')
			continue;
		$linkPath = "$fullTargetDir/$entry";
		if (!is_link($linkPath))
			continue;
		if (!str_contains((string)readlink($linkPath), $vendorFragment))
			continue;
		if (!file_exists($linkPath)) {
			// file_exists() follows symlinks: false means the link is dangling
			unlink($linkPath);
			$removed++;
		}
	}
}
if ($installed || $removed)
	print("Temma skills: $installed skill(s) installed, $removed skill(s) removed.\n");
exit(0);

/**
 * Recursively copy a directory.
 * @param	string	$source	Path of the source directory.
 * @param	string	$destination	Path of the destination directory.
 * @return	bool	True on success.
 */
function _temmaSkillsCopy(string $source, string $destination) : bool {
	if (!@mkdir($destination, 0755, true))
		return (false);
	foreach ((scandir($source) ?: []) as $entry) {
		if ($entry == '.' || $entry == '..')
			continue;
		$sourcePath = "$source/$entry";
		$destinationPath = "$destination/$entry";
		if (is_dir($sourcePath)) {
			if (!_temmaSkillsCopy($sourcePath, $destinationPath))
				return (false);
		} else if (!@copy($sourcePath, $destinationPath)) {
			return (false);
		}
	}
	return (true);
}
