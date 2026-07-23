#!/usr/bin/php
<?php

/**
 * Script de validation de l'objet utilitaire Email et de la datasource SMTP.
 * Ne dépend ni d'un vrai serveur SMTP ni de Smarty : les envois sont capturés par des
 * doubles de test. L'échappement de templatedMail() est couvert par tests/test_smarty.php.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Email as TµEmail;
use \Temma\Datasources\Smtp as TµSmtp;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** DOUBLES DE TEST ********** */
// datasource de "livraison" : reçoit le tableau structuré via set() (comme la datasource SMTP)
class CaptureDelivery extends \Temma\Base\Datasource {
	public array $captured = [];
	public function set(string $key, mixed $value=null, mixed $options=null) : bool {
		$this->captured = ['key' => $key, 'value' => $value];
		return (true);
	}
}
// datasource de "stockage" : n'implémente pas set(), hérite du set() de base (json_encode + write)
class CaptureStorage extends \Temma\Base\Datasource {
	public string $key = '';
	public string $raw = '';
	public function write(string $key, string $value, mixed $options=null) : mixed {
		$this->key = $key;
		$this->raw = $value;
		return (true);
	}
}
// sous-classe d'Email exposant le transport résolu (dans le constructeur)
class TestEmail extends TµEmail {
	public function transport() : ?\Temma\Base\Datasource {
		return ($this->_transport);
	}
}

/* ********** OUTILS ********** */
function makeLoader(array $emailXtra=[], array $extra=[]) : TµLoader {
	$config = new \Temma\Web\Config('/tmp');
	foreach ($emailXtra as $key => $value)
		$config->setXtra('email', $key, $value);
	$data = ['config' => $config] + $extra;
	return (new TµLoader($data));
}
function smtpProp(TµSmtp $smtp, string $name) : mixed {
	$property = (new ReflectionObject($smtp))->getProperty($name);
	$property->setAccessible(true);
	return ($property->getValue($smtp));
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

/* ********** TESTS : PARSING DU DSN SMTP ********** */
print(TµAnsi::bold("Datasource SMTP : parsing du DSN\n"));
$s = TµSmtp::factory('smtp://localhost');
check("smtp:// : sécurité none, port 25, pas d'auth",
      smtpProp($s, '_security') === 'none' && smtpProp($s, '_port') === 25 && smtpProp($s, '_user') === null);
$s = TµSmtp::factory('smtp+tls://user:pass@smtp.gmail.com:587');
check("smtp+tls:// : STARTTLS, port 587, host et auth",
      smtpProp($s, '_security') === 'starttls' && smtpProp($s, '_port') === 587 &&
      smtpProp($s, '_host') === 'smtp.gmail.com' && smtpProp($s, '_user') === 'user');
$s = TµSmtp::factory('smtps://u:p@relay');
check("smtps:// : TLS implicite, port 465 par défaut",
      smtpProp($s, '_security') === 'tls' && smtpProp($s, '_port') === 465);
$s = TµSmtp::factory('smtp://user:p%40ss%2Fx@host');
check("mot de passe URL-encodé décodé",
      smtpProp($s, '_password') === 'p@ss/x');
$s = TµSmtp::factory('smtp+tls://h?helo=mail.me.com&timeout=10&verify=0');
check("paramètres de requête (helo, timeout, verify)",
      smtpProp($s, '_helo') === 'mail.me.com' && smtpProp($s, '_timeout') === 10 && smtpProp($s, '_verify') === false);
$s = TµSmtp::fromParams(['host' => 'h', 'security' => 'starttls']);
check("fromParams : port par défaut déduit de la sécurité",
      smtpProp($s, '_port') === 587 && smtpProp($s, '_security') === 'starttls');
$exception = false;
try {
	TµSmtp::fromParams(['security' => 'starttls']);
} catch (\Temma\Exceptions\Database $e) {
	$exception = true;
}
check("fromParams sans host : exception", $exception);
check("fabrique générique Datasource::factory() reconnaît smtp+tls://",
      \Temma\Base\Datasource::factory('smtp+tls://u:p@host:587') instanceof TµSmtp);

/* ********** TESTS : EMAIL AGNOSTIQUE (chemin livraison) ********** */
print(TµAnsi::bold("Email : envoi via une datasource de livraison\n"));
$delivery = new CaptureDelivery();
$loader = makeLoader();
$email = new TµEmail($loader);
$email->setTransport($delivery);
$email->textMail('Sender <s@dom.com>', 'a@x.com', 'Bonjour', 'Corps du message',
                 ['Cici <c@x.com>'], ['bcc@x.com'], 'env@dom.com');
$value = $delivery->captured['value'] ?? [];
check("set() reçoit un tableau structuré from/recipients/message",
      is_array($value) && isset($value['from'], $value['recipients'], $value['message']));
check("expéditeur d'enveloppe = adresse nue",
      ($value['from'] ?? null) === 'env@dom.com');
check("destinataires = to+cc+bcc, adresses nues dédoublonnées",
      ($value['recipients'] ?? []) === ['a@x.com', 'c@x.com', 'bcc@x.com']);
$message = $value['message'] ?? '';
check("le message contient les en-têtes To, Cc, Subject, Date, Message-ID",
      str_contains($message, 'To: a@x.com') && str_contains($message, 'Cc: Cici <c@x.com>') &&
      str_contains($message, 'Subject: Bonjour') && str_contains($message, 'Date: ') &&
      str_contains($message, 'Message-ID: <'));
check("le message ne contient PAS d'en-tête Bcc",
      !str_contains($message, 'Bcc:'));
check("le message contient le corps",
      str_contains($message, 'Corps du message') && str_contains($message, 'Content-Type: text/plain'));
// message HTML
$delivery = new CaptureDelivery();
$email = new TµEmail(makeLoader());
$email->setTransport($delivery);
$email->mimeMail('s@dom.com', 'a@x.com', 'HTML', '<h1>Salut</h1>');
$message = $delivery->captured['value']['message'] ?? '';
check("message HTML : Content-Type text/html et corps HTML",
      str_contains($message, 'Content-Type: text/html') && str_contains($message, '<h1>Salut</h1>'));
// sujet non-ASCII encodé RFC 2047
$delivery = new CaptureDelivery();
$email = new TµEmail(makeLoader());
$email->setTransport($delivery);
$email->textMail('s@dom.com', 'a@x.com', 'Été', 'x');
$message = $delivery->captured['value']['message'] ?? '';
check("sujet non-ASCII encodé en RFC 2047",
      str_contains($message, 'Subject: =?UTF-8?B?' . base64_encode('Été') . '?='));

/* ********** TESTS : PORTABILITÉ (chemin stockage) ********** */
print(TµAnsi::bold("Email : portabilité vers une datasource de stockage\n"));
$storage = new CaptureStorage();
$email = new TµEmail(makeLoader());
$email->setTransport($storage);
$email->textMail('s@dom.com', 'a@x.com', 'Archive', 'Contenu', [], ['bcc@x.com'], 'env@dom.com');
$decoded = json_decode($storage->raw, true);
check("le stockage sérialise en JSON via le set() de base",
      is_array($decoded) && isset($decoded['from'], $decoded['recipients'], $decoded['message']));
check("l'enveloppe est préservée (bcc présent dans les destinataires)",
      in_array('bcc@x.com', $decoded['recipients'] ?? []) && ($decoded['from'] ?? null) === 'env@dom.com');

/* ********** TESTS : RÉSOLUTION DU TRANSPORT ********** */
print(TµAnsi::bold("Email : résolution du transport\n"));
$email = new TestEmail(makeLoader());
check("aucun transport configuré : null (repli mail())",
      $email->transport() === null);
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader(['transport' => 'mail'], ['mail' => $ds]));
check("x-email/transport (nom) : résout la datasource déclarée référencée",
      $email->transport() === $ds);
$email = new TestEmail(makeLoader(['transport' => 'smtp+tls://u:p@host:587']));
check("x-email/transport (DSN) : fabrique une datasource SMTP",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader(['transport' => ['host' => 'localhost', 'security' => 'none']]));
check("x-email/transport (paramètres SMTP) : fabrique une datasource SMTP",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader(['transport' => 'dummy://']));
check("x-email/transport accepte n'importe quel DSN (pas seulement SMTP)",
      $email->transport() instanceof \Temma\Datasources\Dummy);
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader(['transport' => 'smtp://localhost']));
$email->setTransport($ds);
check("priorité : le setter runtime l'emporte sur la config",
      $email->transport() === $ds);

/* ********** TESTS : setTransport() polymorphe ********** */
print(TµAnsi::bold("Email : setTransport() (objet, DSN, paramètres SMTP)\n"));
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader());
$email->setTransport($ds);
check("setTransport(objet) : datasource utilisée telle quelle",
      $email->transport() === $ds);
$email = new TestEmail(makeLoader());
$email->setTransport('smtp+tls://u:p@host:587');
check("setTransport(DSN) : fabrique une datasource SMTP",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader());
$email->setTransport(['host' => 'smtp.example.com', 'port' => 587, 'security' => 'starttls']);
$transport = $email->transport();
check("setTransport(tableau) : paramètres SMTP",
      $transport instanceof TµSmtp && smtpProp($transport, '_host') === 'smtp.example.com');

// résumé
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) en échec sur $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "Tous les tests ont réussi ($count).") . "\n");
