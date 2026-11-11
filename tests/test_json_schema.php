#!/usr/bin/php
<?php

/** Validation script for the conversion of DataFilter contracts to JSON Schema. */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Utils\DataFilter as TµDataFilter;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\IO as TµIOException;

// initialization
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');

// contract aliases
TµDataFilter::registerAlias('user', 'assoc; keys: id, login; description: A user');
TµDataFilter::registerAlias('flag', 'bool; default: false');
TµDataFilter::registerAlias([
	'node' => [
		'type' => 'assoc',
		'keys' => [
			'name'      => 'string',
			'children?' => 'list; contract: node',
		],
	],
]);
// custom validator (not exportable to JSON Schema)
class CustomValidator implements \Temma\Utils\Validation\Validator {
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		return ($data);
	}
}
TµDataFilter::registerAlias('custom', CustomValidator::class);

// list of tests: contract, expected schema (without the '$schema' keyword)
$tests = [
	'pass-through',
	[null, []],
	[['type' => null, 'description' => 'Anything'], ['description' => 'Anything']],
	['custom', []],
	'scalars',
	['null', ['type' => 'null']],
	['bool', ['type' => 'boolean']],
	['false', ['type' => 'boolean', 'const' => false]],
	['=true', ['type' => 'boolean', 'const' => true]],
	['int; min: 1; max: 10', ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
	['port', ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535]],
	['float; min: 0.5', ['type' => 'number', 'minimum' => 0.5]],
	['string; minLen: 2; maxLen: 1K; mask: "^\d+$"', ['type' => 'string', 'minLength' => 2, 'maxLength' => 1024, 'pattern' => '^\d+$']],
	['enum; values: a, b', ['type' => 'string', 'enum' => ['a', 'b']]],
	['enum', 'io'],
	'annotations',
	[
		'bool; default: false; description: True pour effacer le document.',
		['description' => 'True pour effacer le document.', 'type' => 'boolean', 'default' => false],
	],
	[
		['type' => 'bool', 'default' => false, 'description' => 'True pour effacer le document.'],
		['description' => 'True pour effacer le document.', 'type' => 'boolean', 'default' => false],
	],
	[
		'int; default: 3; title: Count; examples: 5',
		['title' => 'Count', 'type' => 'integer', 'default' => 3, 'examples' => [5]],
	],
	['float; default: 2', ['type' => 'number', 'default' => 2.0]],
	['?int; default: null', ['type' => ['integer', 'null'], 'default' => null]],
	['flag', ['type' => 'boolean', 'default' => false]],
	['flag; description: A flag', ['description' => 'A flag', 'type' => 'boolean', 'default' => false]],
	'multiple types',
	['?int', ['type' => ['integer', 'null']]],
	['~?string', ['type' => ['string', 'null']]],
	['int|string', ['type' => ['integer', 'string']]],
	['null|int|float; min: 0', ['type' => ['integer', 'number', 'null'], 'minimum' => 0]],
	['int|string; min: 0; maxLen: 5', ['type' => ['integer', 'string'], 'minimum' => 0, 'maxLength' => 5]],
	['?enum; values: a, b', ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]]],
	['?false', ['type' => ['boolean', 'null'], 'enum' => [false, null]]],
	['email|url', ['anyOf' => [['type' => 'string', 'format' => 'email'], ['type' => 'string', 'format' => 'uri']]]],
	['?email|url', ['anyOf' => [['type' => 'string', 'format' => 'email'], ['type' => 'string', 'format' => 'uri'], ['type' => 'null']]]],
	[['type' => ['int', 'null']], ['type' => ['integer', 'null']]],
	'specific types',
	['email', ['type' => 'string', 'format' => 'email']],
	['url; scheme: https, svn+ssh', ['type' => 'string', 'format' => 'uri', 'pattern' => '^(?:https|svn\+ssh):']],
	['uuid', ['type' => 'string', 'format' => 'uuid']],
	['ipv6', ['type' => 'string', 'format' => 'ipv6']],
	['ip', ['type' => 'string', 'anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]]],
	['date', ['type' => 'string', 'format' => 'date']],
	['time', ['type' => 'string', 'examples' => ['14:30:00']]],
	['time; format: H:i:sP', ['type' => 'string', 'format' => 'time']],
	['datetime', ['type' => 'string', 'examples' => ['2026-01-31 14:30:00']]],
	['datetime; inFormat: Y-m-d\TH:i:sP; outFormat: Y-m-d', ['type' => 'string', 'format' => 'date-time']],
	['date; format: d/m/Y', ['type' => 'string', 'examples' => ['31/01/2026']]],
	['md5', ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{32}$']],
	['sha256', ['type' => 'string', 'pattern' => '^[0-9a-fA-F]{64}$']],
	['hash; algo: md5, sha1', ['type' => 'string', 'pattern' => '^(?:[0-9a-fA-F]{32}|[0-9a-fA-F]{40})$']],
	['hash; algo: foo', 'io'],
	['slug; maxLen: 20', ['type' => 'string', 'pattern' => '^[a-z0-9-]+$', 'maxLength' => 20]],
	['json; contract: list', ['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'array']]],
	['base64; mime: image/png; maxLen: 3K', ['type' => 'string', 'contentEncoding' => 'base64', 'contentMediaType' => 'image/png', 'maxLength' => 4096]],
	['binary; mime: image', ['type' => 'string']],
	'lists',
	['list', ['type' => 'array']],
	['list; contract: int; minLen: 1; maxLen: 5', ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 5]],
	['list; values: int, string', ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => false, 'minItems' => 2]],
	['list; values: float, float, ...', ['type' => 'array', 'prefixItems' => [['type' => 'number'], ['type' => 'number']], 'minItems' => 2]],
	['list; contract: "?int"', ['type' => 'array', 'items' => ['type' => ['integer', 'null']]]],
	'associative arrays',
	[
		['name', 'age?' => 'int'],
		['type' => 'object', 'properties' => ['name' => new \stdClass(), 'age' => ['type' => 'integer']], 'required' => ['name'], 'additionalProperties' => false],
	],
	[
		'assoc; keys: id, name?',
		['type' => 'object', 'properties' => ['id' => new \stdClass(), 'name' => new \stdClass()], 'required' => ['id'], 'additionalProperties' => false],
	],
	[
		[
			'type'        => 'assoc',
			'description' => 'Document',
			'keys'        => [
				'id'     => 'int; description: Identifier',
				'delete' => ['type' => 'bool', 'mandatory' => false],
				'tags?'  => ['type' => 'list', 'contract' => 'string', 'mandatory' => true],
			],
		],
		[
			'description'          => 'Document',
			'type'                 => 'object',
			'properties'           => [
				'id'     => ['description' => 'Identifier', 'type' => 'integer'],
				'delete' => ['type' => 'boolean'],
				'tags'   => ['type' => 'array', 'items' => ['type' => 'string']],
			],
			'required'             => ['id'],
			'additionalProperties' => false,
		],
	],
	[
		['id' => 'int', '...'],
		['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
	],
	[
		['id' => 'int', '...' => 'string'],
		['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => ['type' => 'string']],
	],
	[
		['0', '1'],
		['type' => 'object', 'properties' => (object)['0' => new \stdClass(), '1' => new \stdClass()], 'required' => ['0', '1'], 'additionalProperties' => false],
	],
	['assoc', 'io'],
	'aliases',
	[
		'?user',
		['description' => 'A user', 'type' => ['object', 'null'], 'properties' => ['id' => new \stdClass(), 'login' => new \stdClass()], 'required' => ['id', 'login'], 'additionalProperties' => false],
	],
	[
		'node',
		[
			'type'                 => 'object',
			'properties'           => [
				'name'     => ['type' => 'string'],
				'children' => ['type' => 'array', 'items' => new \stdClass()],
			],
			'required'             => ['name'],
			'additionalProperties' => false,
		],
	],
];

// execution of the tests
$count = 1;
$failures = 0;
foreach ($tests as $test) {
	if (is_string($test)) {
		print(TµAnsi::bold("$test\n"));
		$count = 1;
		continue;
	}
	[$contract, $expected] = $test;
	$label = is_string($contract) ? $contract : json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$message = '';
	try {
		$schema = TµDataFilter::toJsonSchema($contract);
		if ($expected === 'io') {
			$status = false;
			$message = 'No exception';
		} else {
			$expected = ['$schema' => TµDataFilter::JSON_SCHEMA_DIALECT] + $expected;
			$json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$status = ($json === json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if (!$status)
				$message = $json;
		}
	} catch (TµIOException $ie) {
		$status = ($expected === 'io');
		$message = 'IOException: ' . $ie->getMessage();
	}
	if (!$status)
		$failures++;
	print(TµAnsi::faint(sprintf("%02d", $count)) . ' ' .
	      TµAnsi::color(($status ? 'green' : 'red'), ($status ? 'OK' : 'KO')) . ' ' .
	      TµAnsi::color('blue', "'$label' ") .
	      TµAnsi::faint($message) . "\n");
	$count++;
}
// the root schema must always be encoded as a JSON object
$status = (json_encode(TµDataFilter::toJsonSchema(null), JSON_UNESCAPED_SLASHES) === '{"$schema":"' . TµDataFilter::JSON_SCHEMA_DIALECT . '"}');
if (!$status)
	$failures++;
print(TµAnsi::bold("encoding\n") . TµAnsi::faint('01') . ' ' . TµAnsi::color(($status ? 'green' : 'red'), ($status ? 'OK' : 'KO')) . " root schema\n");
exit($failures ? 1 : 0);
