#!/usr/bin/php
<?php

/**
 * Validation script for the Email utility object and the SMTP datasource.
 * Depends neither on a real SMTP server nor on Smarty: sendings are captured by
 * test doubles. templatedMail() escaping is covered by tests/test_smarty.php.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Utils\Email as TµEmail;
use \Temma\Datasources\Smtp as TµSmtp;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

/* ********** TEST DOUBLES ********** */
// "delivery" datasource: receives the structured array through set() (like the SMTP datasource)
class CaptureDelivery extends \Temma\Base\Datasource {
	public array $captured = [];
	public function set(string $key, mixed $value=null, mixed $options=null) : bool {
		$this->captured = ['key' => $key, 'value' => $value];
		return (true);
	}
}
// "storage" datasource: doesn't implement set(), inherits the base set() (json_encode + write)
class CaptureStorage extends \Temma\Base\Datasource {
	public string $key = '';
	public string $raw = '';
	public function write(string $key, string $value, mixed $options=null) : mixed {
		$this->key = $key;
		$this->raw = $value;
		return (true);
	}
}
// Email subclass exposing the resolved transport (from the constructor)
class TestEmail extends TµEmail {
	public function transport() : ?\Temma\Base\Datasource {
		return ($this->_transport);
	}
}

/* ********** HELPERS ********** */
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

/* ********** TESTS: SMTP DSN PARSING ********** */
print(TµAnsi::bold("SMTP datasource: DSN parsing\n"));
$s = TµSmtp::factory('smtp://localhost');
check("smtp:// : security none, port 25, no auth",
      smtpProp($s, '_security') === 'none' && smtpProp($s, '_port') === 25 && smtpProp($s, '_user') === null);
$s = TµSmtp::factory('smtp+tls://user:pass@smtp.gmail.com:587');
check("smtp+tls:// : STARTTLS, port 587, host and auth",
      smtpProp($s, '_security') === 'starttls' && smtpProp($s, '_port') === 587 &&
      smtpProp($s, '_host') === 'smtp.gmail.com' && smtpProp($s, '_user') === 'user');
$s = TµSmtp::factory('smtps://u:p@relay');
check("smtps:// : implicit TLS, default port 465",
      smtpProp($s, '_security') === 'tls' && smtpProp($s, '_port') === 465);
$s = TµSmtp::factory('smtp://user:p%40ss%2Fx@host');
check("URL-encoded password is decoded",
      smtpProp($s, '_password') === 'p@ss/x');
$s = TµSmtp::factory('smtp+tls://h?helo=mail.me.com&timeout=10&verify=0');
check("query string parameters (helo, timeout, verify)",
      smtpProp($s, '_helo') === 'mail.me.com' && smtpProp($s, '_timeout') === 10 && smtpProp($s, '_verify') === false);
$s = TµSmtp::fromParams(['host' => 'h', 'security' => 'starttls']);
check("fromParams: default port inferred from security",
      smtpProp($s, '_port') === 587 && smtpProp($s, '_security') === 'starttls');
$exception = false;
try {
	TµSmtp::fromParams(['security' => 'starttls']);
} catch (\Temma\Exceptions\Database $e) {
	$exception = true;
}
check("fromParams without host: exception", $exception);
check("generic factory Datasource::factory() recognizes smtp+tls://",
      \Temma\Base\Datasource::factory('smtp+tls://u:p@host:587') instanceof TµSmtp);

/* ********** TESTS: AGNOSTIC EMAIL (delivery path) ********** */
print(TµAnsi::bold("Email: sending through a delivery datasource\n"));
$delivery = new CaptureDelivery();
$loader = makeLoader();
$email = new TµEmail($loader);
$email->setTransport($delivery);
$email->textMail('Sender <s@dom.com>', 'a@x.com', 'Bonjour', 'Corps du message',
                 ['Cici <c@x.com>'], ['bcc@x.com'], 'env@dom.com');
$value = $delivery->captured['value'] ?? [];
check("set() receives a structured from/recipients/message array",
      is_array($value) && isset($value['from'], $value['recipients'], $value['message']));
check("envelope sender = bare address",
      ($value['from'] ?? null) === 'env@dom.com');
check("recipients = to+cc+bcc, deduplicated bare addresses",
      ($value['recipients'] ?? []) === ['a@x.com', 'c@x.com', 'bcc@x.com']);
$message = $value['message'] ?? '';
check("message contains the To, Cc, Subject, Date, Message-ID headers",
      str_contains($message, 'To: a@x.com') && str_contains($message, 'Cc: Cici <c@x.com>') &&
      str_contains($message, 'Subject: Bonjour') && str_contains($message, 'Date: ') &&
      str_contains($message, 'Message-ID: <'));
check("message does NOT contain a Bcc header",
      !str_contains($message, 'Bcc:'));
check("message contains the body",
      str_contains($message, 'Corps du message') && str_contains($message, 'Content-Type: text/plain'));
// HTML message
$delivery = new CaptureDelivery();
$email = new TµEmail(makeLoader());
$email->setTransport($delivery);
$email->mimeMail('s@dom.com', 'a@x.com', 'HTML', '<h1>Salut</h1>');
$message = $delivery->captured['value']['message'] ?? '';
check("HTML message: Content-Type text/html and HTML body",
      str_contains($message, 'Content-Type: text/html') && str_contains($message, '<h1>Salut</h1>'));
// non-ASCII subject encoded as RFC 2047
$delivery = new CaptureDelivery();
$email = new TµEmail(makeLoader());
$email->setTransport($delivery);
$email->textMail('s@dom.com', 'a@x.com', 'Été', 'x');
$message = $delivery->captured['value']['message'] ?? '';
check("non-ASCII subject encoded as RFC 2047",
      str_contains($message, 'Subject: =?UTF-8?B?' . base64_encode('Été') . '?='));

/* ********** TESTS: PORTABILITY (storage path) ********** */
print(TµAnsi::bold("Email: portability to a storage datasource\n"));
$storage = new CaptureStorage();
$email = new TµEmail(makeLoader());
$email->setTransport($storage);
$email->textMail('s@dom.com', 'a@x.com', 'Archive', 'Contenu', [], ['bcc@x.com'], 'env@dom.com');
$decoded = json_decode($storage->raw, true);
check("storage serializes to JSON through the base set()",
      is_array($decoded) && isset($decoded['from'], $decoded['recipients'], $decoded['message']));
check("envelope is preserved (bcc present in the recipients)",
      in_array('bcc@x.com', $decoded['recipients'] ?? []) && ($decoded['from'] ?? null) === 'env@dom.com');

/* ********** TESTS: TRANSPORT RESOLUTION ********** */
print(TµAnsi::bold("Email: transport resolution\n"));
$email = new TestEmail(makeLoader());
check("no configured transport: null (mail() fallback)",
      $email->transport() === null);
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader(['transport' => 'mail'], ['mail' => $ds]));
check("x-email/transport (name): resolves the referenced declared datasource",
      $email->transport() === $ds);
$email = new TestEmail(makeLoader(['transport' => 'smtp+tls://u:p@host:587']));
check("x-email/transport (DSN): builds an SMTP datasource",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader(['transport' => ['host' => 'localhost', 'security' => 'none']]));
check("x-email/transport (SMTP parameters): builds an SMTP datasource",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader(['transport' => 'dummy://']));
check("x-email/transport accepts any DSN (not just SMTP)",
      $email->transport() instanceof \Temma\Datasources\Dummy);
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader(['transport' => 'smtp://localhost']));
$email->setTransport($ds);
check("priority: the runtime setter overrides the configuration",
      $email->transport() === $ds);

/* ********** TESTS: polymorphic setTransport() ********** */
print(TµAnsi::bold("Email: setTransport() (object, DSN, SMTP parameters)\n"));
$ds = new CaptureDelivery();
$email = new TestEmail(makeLoader());
$email->setTransport($ds);
check("setTransport(object): datasource used as-is",
      $email->transport() === $ds);
$email = new TestEmail(makeLoader());
$email->setTransport('smtp+tls://u:p@host:587');
check("setTransport(DSN): builds an SMTP datasource",
      $email->transport() instanceof TµSmtp);
$email = new TestEmail(makeLoader());
$email->setTransport(['host' => 'smtp.example.com', 'port' => 587, 'security' => 'starttls']);
$transport = $email->transport();
check("setTransport(array): SMTP parameters",
      $transport instanceof TµSmtp && smtpProp($transport, '_host') === 'smtp.example.com');

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
