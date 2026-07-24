#!/usr/bin/php
<?php

/**
 * Script de validation du mécanisme de synchronisation des skills IA (skills/sync.php),
 * et du frontmatter des skills présents dans le répertoire skills/ du framework.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** BAC À SABLE ********** */
$sandbox = sys_get_temp_dir() . '/temma-skills-test-' . getmypid();
$vendorSkills = "$sandbox/vendor/digicreon/temma-lib/skills";
if (!mkdir($vendorSkills, 0755, true)) {
	fwrite(STDERR, "Impossible de créer le bac à sable '$sandbox'.\n");
	exit(2);
}
copy(__DIR__ . '/../skills/sync.php', "$vendorSkills/sync.php");
register_shutdown_function(function() use ($sandbox) {
	exec('rm -rf ' . escapeshellarg($sandbox));
});

/* ********** OUTILS ********** */
// crée un skill factice dans le vendor du bac à sable
function makeSkill(string $name) : void {
	global $vendorSkills;
	@mkdir("$vendorSkills/$name", 0755, true);
	file_put_contents("$vendorSkills/$name/SKILL.md", "---\nname: $name\ndescription: Test skill.\n---\nBody.\n");
}
// supprime un skill factice du vendor
function dropSkill(string $name) : void {
	global $vendorSkills;
	exec('rm -rf ' . escapeshellarg("$vendorSkills/$name"));
}
// exécute le script de synchronisation depuis la racine du bac à sable
function runSync() : string {
	global $sandbox;
	exec('cd ' . escapeshellarg($sandbox) . ' && php vendor/digicreon/temma-lib/skills/sync.php 2>&1', $output, $code);
	// le script (processus externe) modifie le système de fichiers : purge du cache de stat de PHP
	clearstatcache(true);
	return (implode("\n", $output) . (($code != 0) ? "\n[exit=$code]" : ''));
}
// écrit le composer.json du bac à sable
function setComposer(?array $temmaSkills) : void {
	global $sandbox;
	$conf = ['name' => 'test/test'];
	if ($temmaSkills !== null)
		$conf['extra'] = ['temma-skills' => $temmaSkills];
	file_put_contents("$sandbox/composer.json", json_encode($conf));
}

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

/* ********** TESTS : INSTALLATION ********** */
print(TµAnsi::bold("Installation initiale\n"));
makeSkill('temma-aaa');
makeSkill('temma-bbb');
file_put_contents("$vendorSkills/notaskill.txt", 'not a skill');
mkdir("$vendorSkills/no-skill-md");
$out = runSync();
check("symlink créé dans .claude/skills",
      is_link("$sandbox/.claude/skills/temma-aaa"));
check("symlink créé dans .agents/skills",
      is_link("$sandbox/.agents/skills/temma-aaa"));
check("symlink relatif vers le répertoire vendored",
      readlink("$sandbox/.claude/skills/temma-aaa") === '../../vendor/digicreon/temma-lib/skills/temma-aaa');
check("le symlink résout vers le SKILL.md du skill",
      is_file("$sandbox/.claude/skills/temma-aaa/SKILL.md"));
check("un fichier du vendor n'est pas symlinké (sync.php, notaskill.txt)",
      !file_exists("$sandbox/.claude/skills/sync.php") && !file_exists("$sandbox/.claude/skills/notaskill.txt"));
check("un répertoire sans SKILL.md n'est pas symlinké",
      !file_exists("$sandbox/.claude/skills/no-skill-md"));
check("compte rendu d'installation affiché",
      str_contains($out, 'installed'));

print(TµAnsi::bold("Idempotence et mise à jour\n"));
$out = runSync();
check("seconde exécution : aucun changement annoncé, aucun avertissement",
      !str_contains($out, 'installed') && !str_contains($out, 'warning'));
makeSkill('temma-ccc');
runSync();
check("nouveau skill vendored pris en compte à la ré-exécution",
      is_link("$sandbox/.claude/skills/temma-ccc") && is_link("$sandbox/.agents/skills/temma-ccc"));

print(TµAnsi::bold("Purge des liens morts\n"));
dropSkill('temma-ccc');
runSync();
check("lien mort vendored purgé après suppression du skill",
      !file_exists("$sandbox/.claude/skills/temma-ccc") && !is_link("$sandbox/.claude/skills/temma-ccc"));
symlink('/nonexistent/path', "$sandbox/.claude/skills/my-broken-skill");
runSync();
check("lien mort NON vendored laissé intact",
      is_link("$sandbox/.claude/skills/my-broken-skill"));
unlink("$sandbox/.claude/skills/my-broken-skill");

print(TµAnsi::bold("Skills personnels du développeur\n"));
mkdir("$sandbox/.claude/skills/my-own-skill", 0755, true);
file_put_contents("$sandbox/.claude/skills/my-own-skill/SKILL.md", "---\nname: my-own-skill\ndescription: x\n---\n");
runSync();
check("skill personnel (vrai répertoire) laissé intact",
      is_dir("$sandbox/.claude/skills/my-own-skill") && !is_link("$sandbox/.claude/skills/my-own-skill"));
// conflit de nom : un vrai répertoire portant le nom d'un skill vendored
unlink("$sandbox/.claude/skills/temma-bbb");
mkdir("$sandbox/.claude/skills/temma-bbb", 0755, true);
file_put_contents("$sandbox/.claude/skills/temma-bbb/SKILL.md", "---\nname: temma-bbb\ndescription: version locale\n---\n");
$out = runSync();
check("conflit de nom : la version locale gagne",
      !is_link("$sandbox/.claude/skills/temma-bbb") && is_dir("$sandbox/.claude/skills/temma-bbb"));
check("conflit de nom : avertissement affiché",
      str_contains($out, 'warning') && str_contains($out, 'temma-bbb'));
exec('rm -rf ' . escapeshellarg("$sandbox/.claude/skills/temma-bbb"));
runSync();

print(TµAnsi::bold("Exclusions et désactivation (composer.json)\n"));
setComposer(['exclude' => ['temma-aaa']]);
runSync();
check("skill exclu : symlink retiré",
      !is_link("$sandbox/.claude/skills/temma-aaa") && !is_link("$sandbox/.agents/skills/temma-aaa"));
check("les autres skills restent installés",
      is_link("$sandbox/.claude/skills/temma-bbb"));
setComposer(null);
runSync();
check("exclusion levée : symlink recréé",
      is_link("$sandbox/.claude/skills/temma-aaa"));
setComposer(['disabled' => true]);
unlink("$sandbox/.claude/skills/temma-aaa");
$out = runSync();
check("synchronisation désactivée : rien n'est recréé, message affiché",
      !file_exists("$sandbox/.claude/skills/temma-aaa") && str_contains($out, 'disabled'));
setComposer(null);
runSync();

/* ********** TESTS : FRONTMATTER DES SKILLS RÉELS ********** */
print(TµAnsi::bold("Frontmatter des skills du framework\n"));
$skillsDir = realpath(__DIR__ . '/../skills');
$realSkills = glob("$skillsDir/*/SKILL.md");
if (!$realSkills) {
	print(TµAnsi::faint("(aucun skill dans skills/ pour le moment)\n"));
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
		check("$dirName : frontmatter présent, nom conforme, description valide, corps < 500 lignes",
		      $ok &&
		      $name === $dirName &&
		      (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string)$name) &&
		      $description && mb_strlen($description) <= 1024 &&
		      $bodyLines < 500);
	}
}

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
