#!/usr/bin/php
<?php

/**
 * Script de validation du raccourci de contrat "clé numérique + valeur chaîne"
 * dans Request::validateInput() et Request::validateFiles()
 * (['aa'] équivalent à ['aa' => null] : présence exigée, contenu non validé).
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\Application as TµApplicationException;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();

$request = new \Temma\Web\Request(false);

/* ********** OUTILS ********** */
// répertoire des fichiers d'upload factices
$tmpDir = sys_get_temp_dir() . '/temma-req-test-' . getmypid();
mkdir($tmpDir, 0755, true);
register_shutdown_function(function() use ($tmpDir) {
	exec('rm -rf ' . escapeshellarg($tmpDir));
});
// crée une entrée $_FILES factice pointant sur un vrai fichier temporaire
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
// exécute une closure et indique si une exception Application a été levée
function fails(callable $fn) : bool {
	try {
		$fn();
		return (false);
	} catch (TµApplicationException $e) {
		return (true);
	}
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

/* ********** TESTS : validateInput() ********** */
print(TµAnsi::bold("validateInput() : raccourci clé numérique\n"));
$_GET = [];
$_POST = ['name' => 'Alice', 'age' => '42'];
check("['name'] : paramètre présent, contenu libre",
      !fails(fn() => $request->validateInput(['name', 'age' => 'int'], 'POST')));
check("le paramètre sans contrat est conservé tel quel",
      ($_POST['name'] ?? null) === 'Alice' && ($_POST['age'] ?? null) === 42);
$_POST = ['age' => '42'];
check("['name'] : paramètre absent, échec",
      fails(fn() => $request->validateInput(['name', 'age' => 'int'], 'POST')));
$_POST = ['age' => '42'];
check("['name?'] : paramètre optionnel absent, succès",
      !fails(fn() => $request->validateInput(['name?', 'age' => 'int'], 'POST')));
$_POST = ['name' => 'Bob', 'extra' => 'x'];
check("joker '...' préservé dans une liste mixte",
      !fails(fn() => $request->validateInput(['name', '...'], 'POST')) &&
      ($_POST['extra'] ?? null) === 'x');
$_POST = ['name' => 'Bob'];
check("équivalence explicite : ['name' => null] fonctionne aussi",
      !fails(fn() => $request->validateInput(['name' => null], 'POST')));

/* ********** TESTS : validateFiles() ********** */
print(TµAnsi::bold("validateFiles() : raccourci clé numérique\n"));
$_FILES = [];
makeFile('id_card', 'binary-data');
check("['id_card'] : fichier présent, succès",
      !fails(fn() => $request->validateFiles(['id_card'])));
$_FILES = [];
check("['id_card'] : fichier absent, échec avec le bon nom de champ",
      fails(fn() => $request->validateFiles(['id_card'])));
try {
	$request->validateFiles(['id_card']);
} catch (TµApplicationException $e) {
	check("le message d'erreur cite 'id_card' (et non '0')",
	      str_contains($e->getMessage(), 'id_card'));
}
$_FILES = [];
check("['id_card?'] : fichier optionnel absent, succès",
      !fails(fn() => $request->validateFiles(['id_card?'])));
// présence seule : le contenu ne doit pas être lu (tmp_name volontairement invalide)
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
check("contrat null : le fichier n'est pas lu (aucun accès au tmp_name)",
      !$failedValidation && !$warned);
// formes mixtes et joker
$_FILES = [];
makeFile('id_card', 'raw');
makeFile('avatar', 'raw');
makeFile('other', 'raw');
check("forme mixte : ['id_card', 'avatar' => null, '...'] en mode strict",
      !fails(fn() => $request->validateFiles(['id_card', 'avatar' => null, '...'], true)));
// non-régression : un contrat de contenu est toujours appliqué
$_FILES = [];
makeFile('data', '{"num": 42}');
check("non-régression : contrat de contenu appliqué (json valide)",
      !fails(fn() => $request->validateFiles(['data' => 'json'])));
$_FILES = [];
makeFile('data', 'not json at all');
check("non-régression : contrat de contenu appliqué (json invalide, échec)",
      fails(fn() => $request->validateFiles(['data' => 'json'])));

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
