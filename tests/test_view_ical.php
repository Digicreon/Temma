#!/usr/bin/php
<?php

/**
 * Validation script for the iCal view.
 * Creates a response object filled with template variables, processes it with the iCal view
 * (init() and sendBody()) and checks the generated iCalendar stream.
 */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\Ansi as TµAnsi;

\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
\Temma\Base\Log::disable();
// PHP warnings and notices are converted to exceptions, so they make the tests fail
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/* ********** HELPERS ********** */
$count = 0;
$failed = 0;
// displays the result of a test
function check(string $label, bool $ok) : void {
	global $count, $failed;
	$count++;
	if (!$ok)
		$failed++;
	print(TµAnsi::faint(sprintf('%02d', $count)) . ' ' .
	      TµAnsi::color(($ok ? 'green' : 'red'), ($ok ? 'OK' : 'KO')) . ' ' .
	      "$label\n");
}
// processes the given template variables with the iCal view, and returns the generated stream
function render(array $data) : string {
	$config = new \Temma\Web\Config(sys_get_temp_dir());
	$response = new \Temma\Web\Response();
	$response->setData($data);
	$view = new \Temma\Views\ICal([], $config, $response);
	$view->init();
	ob_start();
	$view->sendBody();
	return (ob_get_clean());
}
/**
 * Creates calendar data.
 * @param	string	$prefix	Prefix of the calendar name, description and event name.
 * @return	array	The calendar data.
 */
function calendar(string $prefix) : array {
	return ([
		'name'        => "{$prefix}Calendar",
		'description' => "{$prefix}Description",
		'events'      => [
			[
				'name' => "{$prefix}Event",
				'date' => '2026-11-15',
				'uid'  => "{$prefix}-01",
			],
		],
	]);
}
// tells if a generated stream contains the given calendar
function hasCalendar(string $stream, string $prefix) : bool {
	return (str_contains($stream, "X-WR-CALNAME:{$prefix}Calendar\r\n") &&
	        str_contains($stream, "X-WR-CALDESC:{$prefix}Description\r\n") &&
	        str_contains($stream, "SUMMARY:{$prefix}Event\r\n") &&
	        str_contains($stream, "UID:{$prefix}-01\r\n"));
}
// template variables defined by the framework, and private variables
$frameworkData = [
	'URL'             => '/user/show/12',
	'CONTROLLER'      => 'user',
	'ACTION'          => 'show',
	'_temmaCacheable' => true,
	'_private'        => 'abc',
	'__flash'         => 'def',
];

/* ********** TESTS ********** */
print(TµAnsi::bold("Data source\n"));
$result = render(['ical' => calendar('Ical')] + $frameworkData);
check("data of the 'ical' variable are used", hasCalendar($result, 'Ical'));
$result = render([
	'@output' => calendar('Output'),
	'ical'    => calendar('Ical'),
] + calendar('Vars') + $frameworkData);
check("data of the '@output' variable take precedence over the 'ical' variable and the template variables",
      hasCalendar($result, 'Output') && !str_contains($result, 'Ical') && !str_contains($result, 'Vars'));
$result = render(['ical' => calendar('Ical')] + calendar('Vars'));
check("data of the 'ical' variable take precedence over the 'name', 'description' and 'events' variables",
      hasCalendar($result, 'Ical') && !str_contains($result, 'Vars'));
$result = render(calendar('Vars') + $frameworkData);
check("without '@output' nor 'ical', the 'name', 'description' and 'events' variables are used", hasCalendar($result, 'Vars'));
$data = calendar('Vars');
unset($data['name'], $data['description']);
$result = render($data);
check("the 'name' and 'description' variables are optional",
      str_contains($result, "SUMMARY:VarsEvent\r\n") && !str_contains($result, 'X-WR-CALNAME') && !str_contains($result, 'X-WR-CALDESC'));
$result = render(['@output' => null, 'ical' => null] + calendar('Vars'));
check("'@output' and 'ical' variables set to null are ignored", hasCalendar($result, 'Vars'));
$data = calendar('Vars');
unset($data['events']);
$result = render($data + $frameworkData);
check("without '@output', 'ical' nor 'events', nothing is sent", $result === '');

print("\n" . TµAnsi::bold("Event dates\n"));
$result = render(['events' => [['name' => 'Meeting', 'dateStart' => '2026-11-15 10:00:00Z', 'dateEnd' => '2026-11-15 11:30:00+01:00']]]);
check("dates of an event with 'dateStart' and 'dateEnd' are converted to UTC",
      str_contains($result, "DTSTART:20261115T100000Z\r\n") && str_contains($result, "DTEND:20261115T103000Z\r\n"));
$result = render(['events' => [['name' => 'Holiday', 'date' => '2026-12-25']]]);
check("dates of an all-day event with 'date'",
      str_contains($result, "DTSTART;VALUE=DATE:20261225\r\n") && str_contains($result, "DTEND;VALUE=DATE:20261226\r\n"));

// summary
print("\n");
if ($failed) {
	print(TµAnsi::color('red', "$failed test(s) failed out of $count.") . "\n");
	exit(1);
}
print(TµAnsi::color('green', "All tests passed ($count).") . "\n");
