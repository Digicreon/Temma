#!/usr/bin/php
<?php

/** Script de validation du DataFilter. */

require_once(__DIR__ . '/../lib/Temma/Base/Autoload.php');

use \Temma\Base\Autoload as TµAutoload;
use \Temma\Base\Loader as TµLoader;
use \Temma\Utils\Validation\DataFilter as TµDataFilter;
use \Temma\Utils\Ansi as TµAnsi;
use \Temma\Exceptions\IO as TµIOException;
use \Temma\Exceptions\Application as TµApplicationException;

// initialisation
\Temma\Base\Autoload::autoload(__DIR__ . '/../lib');
$loader = new \Temma\Base\Loader();
TµDataFilter::setLoader($loader);

// fichier image
$gif = base64_decode('R0lGODdhAQABAIAAAAAAAAAAACwAAAAAAQABAAACAkQBADs=');

// ajout de contrat
TµDataFilter::registerAlias('user', 'assoc; keys: id, login, name');
class DbMock {
	public function getCategory(int $id) : array {
		return [
			'id' => $id,
			'name' => 'Catégorie 1',
			'dateCreation' => '2026-02-20 16:49:32',
		];
	}
}
$dbMock = new DbMock();
$loader['db'] = $dbMock;
class CategoryValidator implements \Temma\Utils\Validation\Validator {
	public function __construct(private DbMock $db){
	}
	public function validate(mixed $data, array $contract=[], mixed &$output=null) : mixed {
		if (!is_numeric($data))
			throw new TµApplicationException("Bad value.");
		$category = $this->db->getCategory($data);
		$output = $category;
		return $category;
	}
}
TµDataFilter::registerAlias('category', CategoryValidator::class);

// liste des tests
$tests = [
	[
		'abc', null,
		'strict' => false,
		'expect' => 'io',
	],
	'null',
	[
		'null', null,
		'strict' => false,
		'expect' => true,
	],
	[
		'null', null,
		'strict' => true,
		'expect' => true,
	],
	[
		'=null', null,
		'strict' => false,
		'expect' => true,
	],
	[
		'~null', null,
		'strict' => true,
		'expect' => true,
	],
	[
		'null', 123,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'null', 123,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'null; default: null', 123,
		'strict' => false,
		'expect' => true,
	],
	[
		'null; default: null', null,
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'type' => 'null',
			'default' => 123,
		],
		'abc',
		'strict' => false,
		'expect' => 'io',
	],
	[
		'null; default: null', 123,
		'strict' => true,
		'expect' => true,
		'output' => null,
	],
	'false',
	[
		'false', null,
		'strict' => false,
		'expect' => true,
	],
	[
		'false', false,
		'strict' => false,
		'expect' => true,
	],
	[
		'false', null,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'?false', null,
		'strict' => true,
		'expect' => true,
	],
	[
		'=false', 123,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'~false', 0,
		'strict' => true,
		'expect' => true,
	],
	[
		'=false', 0,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'false', 0,
		'strict' => false,
		'expect' => true,
		'res' => false,
		'output' => false,
	],
	'true',
	[
		'true', true,
		'strict' => false,
		'expect' => true,
	],
	[
		'true', 1,
		'strict' => false,
		'expect' => true,
	],
	[
		'true', 1,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'=true', 1,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'~true', 123,
		'strict' => true,
		'expect' => true,
	],
	[
		'=true; default: true', false,
		'strict' => false,
		'expect' => true,
	],
	[
		'true', true,
		'strict' => true,
		'expect' => true,
		'res' => true,
		'output' => true,
	],
	'bool',
	[
		'bool', 0,
		'strict' => false,
		'expect' => true,
		'res' => false,
	],
	[
		'bool', 1,
		'strict' => false,
		'expect' => true,
		'res' => true,
	],
	[
		'bool', 0,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'bool', false,
		'strict' => true,
		'expect' => true,
		'res' => false,
	],
	[
		'=bool', true,
		'strict' => false,
		'expect' => true,
		'res' => true,
	],
	[
		'~bool', 0,
		'strict' => true,
		'expect' => true,
		'res' => false,
	],
	[
		'=bool', 0,
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => 'bool',
			'default' => true,
		],
		'abc',
		'strict' => 'true',
		'expect' => true,
		'res' => true,
	],
	[
		'bool', true,
		'strict' => true,
		'expect' => true,
		'res' => true,
		'output' => true,
	],
	'int',
	[
		'int', null,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'int', false,
		'strict' => false,
		'expect' => true,
		'res' => 0,
	],
	[
		'int', true,
		'strict' => false,
		'expect' => true,
		'res' => 1,
	],
	[
		'int', false,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'=int', true,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'int', 0,
		'strict' => false,
		'expect' => true,
		'res' => 0,
	],
	[
		'int', 123,
		'strict' => false,
		'expect' => true,
		'res' => 123,
	],
	[
		'int', 12.56,
		'strict' => false,
		'expect' => true,
		'res' => 12,
	],
	[
		'int', 12.56,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'int', '123',
		'strict' => false,
		'expect' => true,
		'res' => 123,
	],
	[
		'=int', '123',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'=int', '123',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'int; default: 444', 'abc',
		'strict' => true,
		'expect' => true,
		'res' => 444,
	],
	[
		[
			'type' => 'int',
			'default' => 456,
		],
		'abc',
		'strict' => true,
		'expect' => true,
		'res' => 456,
	],
	[
		'int; min: 3', 1,
		'strict' => false,
		'expect' => true,
		'res' => 3,
	],
	[
		'int; min: 3', 1,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'=int; max: 3', 12,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'int; max: 3', 12,
		'strict' => false,
		'expect' => true,
		'res' => 3,
	],
	[
		[
			'type' => 'int',
			'min' => 3,
			'max' => 12,
		],
		15,
		'strict' => false,
		'expect' => true,
		'res' => 12,
	],
	[
		[
			'type' => 'int',
			'min' => 3,
			'max' => 12,
		],
		15,
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => '=int',
			'min' => 3,
			'max' => 12,
		],
		15,
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => '~int',
			'min' => 3,
			'max' => 12,
		],
		2,
		'strict' => true,
		'expect' => true,
		'res' => 3,
	],
	[
		'int', 12,
		'strict' => true,
		'expect' => true,
		'output' => 12,
	],
	'float',
	[
		'float', null,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'float', false,
		'strict' => false,
		'expect' => true,
		'res' => 0.0,
	],
	[
		'float', true,
		'strict' => false,
		'expect' => true,
		'res' => 1.0,
	],
	[
		'float', false,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'=float', true,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'float', 0.0,
		'strict' => false,
		'expect' => true,
		'res' => 0.0,
	],
	[
		'float', 123.23,
		'strict' => false,
		'expect' => true,
		'res' => 123.23,
	],
	[
		'float', 12,
		'strict' => false,
		'expect' => true,
		'res' => 12.0,
	],
	[
		'float', 12,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'float; default: 12.34', 'abc',
		'strict' => true,
		'expect' => true,
		'res' => 12.34,
	],
	[
		'float; min: 3.5', 1,
		'strict' => false,
		'expect' => true,
		'res' => 3.5,
	],
	[
		'=float; min: 3.5', 1,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'float; max: 7', 12.5,
		'strict' => false,
		'expect' => true,
		'res' => 7.0,
	],
	[
		'float; max: 7', 12.5,
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'float',
			'min' => 3.2,
			'max' => 7.4,
		],
		'5',
		'strict' => false,
		'expect' => true,
		'res' => 5.0,
	],
	[
		[
			'type' => 'float',
			'min' => 3.2,
			'max' => 7.4,
		],
		2.2,
		'strict' => false,
		'expect' => true,
		'res' => 3.2,
	],
	[
		[
			'type' => 'float',
			'min' => 3.2,
			'max' => 7.4,
		],
		'12.32',
		'strict' => false,
		'expect' => true,
		'res' => 7.4,
	],
	[
		[
			'type' => '=float',
			'min' => 3.2,
			'max' => 7.4,
		],
		12.32,
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => 'float',
			'min' => 3.2,
			'max' => 7.4,
			'default' => 5.5,
		],
		12.32,
		'strict' => false,
		'expect' => true,
		'res' => 7.4,
	],
	[
		[
			'type' => 'float',
			'min' => 3.2,
			'max' => 7.4,
			'default' => 5.5,
		],
		12.32,
		'strict' => true,
		'expect' => true,
		'res' => 5.5,
	],
	[
		'float', 12.56,
		'strict' => true,
		'expect' => true,
		'res' => 12.56,
		'output' => 12.56,
	],
	'string',
	[
		'string', 'abc def',
		'strict' => false,
		'expect' => true,
		'res' => 'abc def',
	],
	[
		'string', 12,
		'strict' => false,
		'expect' => true,
		'res' => '12',
	],
	[
		'string', 12.45,
		'strict' => false,
		'expect' => true,
		'res' => '12.45',
	],
	[
		'string', true,
		'strict' => false,
		'expect' => true,
		'res' => 'true',
	],
	[
		'string', false,
		'strict' => false,
		'expect' => true,
		'res' => 'false',
	],
	[
		'string', null,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'string', 12,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'string; default: abcdef', 12,
		'strict' => false,
		'expect' => true,
		'res' => '12',
	],
	[
		'string; default: abcdef', 12,
		'strict' => true,
		'expect' => true,
		'res' => 'abcdef',
	],
	[
		'string; minLen: 3', 'aa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'string; maxLen: 3', 'aa',
		'strict' => true,
		'expect' => true,
		'res' => 'aa',
	],
	[
		'string; maxLen: 3', 'abcdef',
		'strict' => false,
		'expect' => true,
		'res' => 'abc',
	],
	[
		'string; maxLen: 3', 'abcdef',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'string; mask: ^a.*z$', 'aze',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'string; mask: ^a.*z$', 'abcdz',
		'strict' => false,
		'expect' => true,
		'res' => 'abcdz',
	],
	[
		[
			'type' => 'string',
			'mask' => 'abc',
			'default' => 'aaabccc',
		],
		'aaa',
		'strict' => false,
		'expect' => true,
		'res' => 'aaabccc',
	],
	[
		[
			'type' => 'string',
			'mask' => 'abc',
			'default' => 'zzz',
		],
		'aaa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => 'string',
			'mask' => 'abc',
			'minLen' => 12,
			'default' => 'aabcc',
		],
		'aaa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'string; charset: ascii', 'abc',
		'strict' => false,
		'expect' => true,
	],
	[
		'string; charset: ascii', 'été',
		'strict' => false,
		'expect' => true,
		'res' => '?t?',
	],
	[
		'string; charset: latin1', 'été',
		'strict' => false,
		'expect' => true,
		'res' => chr(233) . 't' . chr(233),
	],
	[
		'=string; charset: latin1', 'été',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'string', 12,
		'strict' => false,
		'expecct' => true,
		'res' => '12',
		'output' => '12',
	],
	'email',
	[
		'email', 'toto',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'email', 'toto@tutu.com',
		'strict' => false,
		'expect' => true,
		'res' => 'toto@tutu.com',
	],
	[
		'email; minLen: 8', 'a@a.fr',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'email; minLen: 8', 'aa@aa.fr',
		'strict' => false,
		'expect' => true,
		'res' => 'aa@aa.fr',
	],
	[
		'email; maxLen: 7', 'aa@aa.com',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'email; maxLen: 7', 'a@aa.fr',
		'strict' => false,
		'expect' => true,
		'res' => 'a@aa.fr',
	],
	[
		'email; mask: @toto.com$', 'toto@toto.com',
		'strict' => false,
		'expect' => true,
	],
	[
		'email; mask: @toto.com$', 'toto@tutu.com',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'email; default: toto@toto.com', 'aaa',
		'strict' => true,
		'expect' => true,
		'res' => 'toto@toto.com',
	],
	[
		[
			'type' => 'email',
			'minLen' => 5,
			'maxLen' => 15,
			'mask' => '@toto.com$',
			'default' => 'toto@toto.com',
		],
		'tutu@tutu.com',
		'strict' => false,
		'expect' => true,
		'res' => 'toto@toto.com',
	],
	[
		[
			'type' => 'email',
			'minLen' => 5,
			'maxLen' => 15,
			'mask' => '@toto.com$',
			'default' => 'toto@to',
		],
		'tutu@tutu.com',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'email', 'toto@toto.com',
		'strict' => false,
		'expect' => true,
		'value' => 'toto@toto.com',
		'output' => 'toto@toto.com',
	],
	'url',
	[
		'url', 'aaa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'url', 'http://www.toto.com',
		'strict' => false,
		'expect' => true,
	],
	[
		'url', 'http://www.toto.com/bidule/truc#aaa?bb=cc',
		'strict' => false,
		'expect' => true,
	],
	[
		'url', 'http://www.toto.com/bidule/truc#aaa?bb=cc',
		'strict' => true,
		'expect' => true,
	],
	[
		'url; scheme: http', 'http://www.toto.com/',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; scheme: http, https', 'https://www.toto.com/',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; scheme: http', 'ftp://login@server.com/path',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'url; scheme: http, ftp, sftp', 'ftp://login@server.com/path',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; host: server.com; path: /path', 'ftp://login@server.com/path',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; port: 8080, 8888; path: /, /foo', 'https://toto.com:8888/foo',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; fragment: toto, titi', 'https://toto.com:8888/foo#titi',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; default: https://www.toto.com/', 'aaa',
		'strict' => false,
		'expect' => true,
		'res' => 'https://www.toto.com/',
	],
	[
		'url; mask: www.toto.com', 'https://www.toto.com/bidule/',
		'strict' => false,
		'expect' => true,
	],
	[
		'url; maxLen: 8', 'https://www.toto.com',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'url; maxLen: 8', 'https://www.toto.com',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'url; minLen: 18', 'http://to.com',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'url', 'https://www.toto.com/aa/bb?dd=ee&ff=gg',
		'strict' => false,
		'expect' => true,
		'res' => 'https://www.toto.com/aa/bb?dd=ee&ff=gg',
		'output' => [
			'scheme' => "https",
			'host' => "www.toto.com",
			'path' => "/aa/bb",
			'query' => "dd=ee&ff=gg",
			'domain' => "toto.com",
		],
	],
	'uuid',
	[
		'uuid', '123e4567-e89b-12d3-a456-426614174003',
		'strict' => false,
		'expect' => true,
	],
	[
		'uuid', 'azeazeaze',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'uuid; default: 123e4567-e89b-12d3-a456-426614174003', 'aaaa',
		'strict' => false,
		'expect' => true,
		'res' => '123e4567-e89b-12d3-a456-426614174003',
	],
	[
		'uuid', '123e4567-e89b-12d3-a456-426614174003',
		'strict' => false,
		'expect' => true,
		'res' => '123e4567-e89b-12d3-a456-426614174003',
		'output' => '123e4567-e89b-12d3-a456-426614174003',
	],
	'hash',
	[
		'hash', md5('abc'),
		'strict' => false,
		'expect' => 'io',
	],
	[
		'hash; algo: md5', md5('abc'),
		'strict' => false,
		'expect' => true,
	],
	[
		'hash; algo: md5, sha1', sha1('abc'),
		'strict' => false,
		'expect' => true,
	],
	[
		'hash; algo: aaa', sha1('abc'),
		'strict' => false,
		'expect' => 'io',
	],
	[
		'hash; algo: md5, sha1, sha224, sha256, sha384, crc32', hash('sha3-512', 'tralala'),
		'strict' => false,
		'expect' => 'app',
	],
	[
		'hash; algo: md5, sha1, sha224, sha256, sha384, crc32, sha3-512', hash('sha3-512', 'tralala'),
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'type'   => 'hash',
			'algo'   => ['md5', 'sha1', 'sha224', 'sha256', 'sha384', 'crc32', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'],
			'source' => 'tralala',
		],
		hash('sha3-512', 'tralala'),
		'strict' => false,
		'expect' => true,
	],
	[
		'hash; algo: md5', md5('abc'),
		'strict' => false,
		'expect' => true,
		'res' => md5('abc'),
		'output' => md5('abc'),
	],
	'binary',
	[
		'binary', 'aqwzsxedcrfv',
		'strict' => false,
		'expect' => true,
	],
	[
		'binary; charset=ascii', 'aqwzsxedcrfv',
		'strict' => false,
		'expect' => true,
	],
	[
		'=binary; charset=ascii', 'aqwzsxedcrfv',
		'strict' => false,
		'expect' => true,
	],
	[
		'binary; charset: latin1', 'été',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'binary; charset: latin1', 'été',
		'strict' => false,
		'expect' => true,
		'res' => chr(233) . 't' . chr(233),
	],
	[
		'binary; mime: image/gif', $gif,
		'strict' => false,
		'expect' => true,
		'res' => $gif,
	],
	[
		'binary; mime: image, application/pdf', $gif,
		'strict' => false,
		'expect' => true,
		'res' => $gif,
	],
	[
		'binary; mime: image/png, application/pdf', $gif,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'binary; minLen: 4', 'aaa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'binary; maxLen: 3', 'azeaze',
		'strict' => false,
		'expect' => true,
	],
	[
		'binary; maxLen: 3', 'azeaze',
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'binary',
			'minLen' => 4,
			'mime' => 'image/gif',
			'default' => $gif,
		],
		'aa',
		'strict' => false,
		'expect' => true,
		'res' => $gif,
		'output' => [
			'binary' => $gif,
			'mime' => 'image/gif',
			'charset' => 'binary',
		],
	],
	'base64',
	[
		'base64', base64_encode($gif),
		'strict' => false,
		'expect' => true,
		'res' => base64_encode($gif),
	],
	[
		'base64', 'a',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'base64; default: ' . base64_encode($gif), null,
		'strict' => false,
		'expect' => true,
		'res' => base64_encode($gif),
	],
	[
		'base64; mime: image/gif', base64_encode($gif),
		'strict' => false,
		'expect' => true,
		'res' => base64_encode($gif),
	],
	[
		'base64; minLen: 3', 'aa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'base64; maxLen: 2', base64_encode($gif),
		'strict' => false,
		'expect' => 'app',
	],
	[
		'base64; maxLen: 2', base64_encode($gif),
		'strict' => true,
		'expect' => 'app',
	],
	[
		'base64', base64_encode($gif),
		'strict' => true,
		'expect' => true,
		'res' => base64_encode($gif),
		'output' => [
			'binary' => $gif,
			'mime' => 'image/gif',
			'charset' => 'binary',
		],
	],
	'date',
	[
		'date', '2026-02-07',
		'strict' => true,
		'expect' => true,
		'res' => '2026-02-07',
	],
	[
		'date', '2026-02-30',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'date', '2026-02-30',
		'strict' => false,
		'expect' => true,
		'res' => '2026-03-02',
	],
	[
		'date', '07/02/2026',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'date; format: d/m/Y', '07/02/2026',
		'strict' => true,
		'expect' => true,
		'res' => '07/02/2026',
	],
	[
		'date; inFormat: d/m/Y', '07/02/2026',
		'strict' => false,
		'expect' => true,
		'res' => '2026-02-07',
	],
	[
		'date; outFormat: d/m/Y', '2026-02-07',
		'strict' => false,
		'expect' => true,
		'res' => '07/02/2026',
	],
	[
		[
			'type' => 'date',
			'min' => '2026-01-01',
		],
		'2025-12-30',
		'strict' => false,
		'expect' => true,
		'res' => '2026-01-01',
	],
	[
		[
			'type' => 'date',
			'min' => '2026-01-01',
		],
		'2025-12-30',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'~date; max: 2025-12-31', '2026-02-07',
		'strict' => true,
		'expect' => true,
		'res' => '2025-12-31',
	],
	[
		'=date; max: 2025-12-31', '2026-02-07',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'date; format: d/m/Y; min: 01/01/2026', '01/12/2025',
		'strict' => false,
		'expect' => true,
		'res' => '01/01/2026',
	],
	[
		'date; format: d/m/Y; min: 01/01/2026', '01/12/2025',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'date; min: 2026-01-01; default: 2026-02-07', '2025-12-31',
		'strict' => true,
		'expect' => true,
		'res' => '2026-02-07',
	],
	[
		'date; inFormat: d/m/Y; outFormat: Y--d--m; default: 2026--16--10', '07/02/2026',
		'strict' => true,
		'expect' => true,
		'res' => '2026--07--02',
	],
	[
		'date; inFormat: d/m/Y; outFormat: Y--d--m; default: 2026--16--10', null,
		'strict' => false,
		'expect' => 'app',
	],
	'time',
	[
		'time', '23:17:44',
		'strict' => true,
		'expect' => true,
		'res' => '23:17:44',
	],
	[
		'time', '18:65:44',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'time', '18:65:44',
		'strict' => false,
		'expect' => true,
		'res' => '19:05:44',
	],
	[
		'time', '23h17:44',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'time; format: H\hi', '23h17',
		'strict' => true,
		'expect' => true,
		'res' => '23h17',
	],
	[
		'time; inFormat: H\hi', '23h17',
		'strict' => false,
		'expect' => true,
		'res' => '23:17:00',
	],
	[
		'time; outFormat: H\hi', '23:17:44',
		'strict' => false,
		'expect' => true,
		'res' => '23h17',
	],
	[
		[
			'type' => 'time',
			'min' => '17:18:19',
		],
		'07:15:11',
		'strict' => false,
		'expect' => true,
		'res' => '17:18:19',
	],
	[
		[
			'type' => 'time',
			'min' => '17:18:19',
		],
		'07:15:11',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'~time; max: 15:17:18', '23:17:44',
		'strict' => true,
		'expect' => true,
		'res' => '15:17:18',
	],
	[
		'=time; max: 15:17:18', '23:17:44',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'time; format: H\hi; min: 17h18', '13h15',
		'strict' => false,
		'expect' => true,
		'res' => '17h18',
	],
	[
		'time; format: H\hi; min: 17h18', '13h15',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'time; min: 17:18:00; default: 18:30:15', '13:15:17',
		'strict' => true,
		'expect' => true,
		'res' => '18:30:15',
	],
	[
		'time; inFormat: H\hi; outFormat: H--i--s; default: 17--55--44', '07h33',
		'strict' => true,
		'expect' => true,
		'res' => '07--33--00',
	],
	[
		'time; inFormat: H\hi; outFormat: H--i--s; default: 17--55--44', null,
		'strict' => false,
		'expect' => 'app',
	],
	'datetime',
	[
		'datetime', null,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'datetime', '2026-02-07 19:05:34',
		'strict' => false,
		'expect' => true,
	],
	[
		'datetime; default: 2026-02-07 19:05:34', null,
		'strict' => false,
		'expect' => true,
		'res' => '2026-02-07 19:05:34',
	],
	[
		'datetime', '2026-02-07 19:05:34',
		'strict' => false,
		'expect' => true,
		'res' => '2026-02-07 19:05:34',
		'output' => [
			'iso' => "2026-02-07T19:05:34.000+00:00",
			'timestamp' => 1770491134,
			'timezone' => "UTC",
			'offset' => 0,
			'year' => 2026,
			'month' => 2,
			'day' => 7,
			'hour' => 19,
			'minute' => 5,
			'second' => 34,
			'micro' => 0,
		],
	],
	'isbn',
	[
		'isbn', null,
		'strist' => false,
		'expect' => 'app',
	],
	[
		'isbn', '0-306-40615-2',
		'strict' => false,
		'expect' => true,
	],
	[
		'isbn', '0306406152',
		'strict' => false,
		'expect' => true,
	],
	[
		'isbn', '978-3-16-148410-0',
		'strict' => false,
		'expect' => true,
	],
	[
		'isbn', '9783161484100',
		'strict' => false,
		'expect' => true,
	],
	[
		'isbn; default: 9783161484100', 'aaa',
		'strict' => false,
		'expect' => true,
		'res' => '9783161484100',
	],
	[
		'isbn', '0-306-40615-2',
		'strict' => true,
		'expect' => true,
		'res' => '0306406152',
		'output' => '0306406152',
	],
	'ean',
	[
		'ean', 'abc',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'ean', '4006381333931',
		'strict' => false,
		'expect' => true,
	],
	[
		'ean; default: 4006381333931', 123,
		'strict' => false,
		'expect' => true,
		'res' => '4006381333931',
	],
	[
		'ean; default: 123', null,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'ean', '4006381333931',
		'strict' => false,
		'expect' => true,
		'res' => '4006381333931',
		'output' => '4006381333931',
	],
	'ipv4',
	[
		'ipv4', '123.123',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'ipv4', '127.0.0.1',
		'strict' => true,
		'expect' => true,
		'res' => '127.0.0.1',
	],
	[
		'ipv4; default: 127.0.0.1', 'abc',
		'strict' => true,
		'expect' => true,
		'res' => '127.0.0.1',
	],
	[
		'ipv4', '127.0.0.1',
		'strict' => true,
		'expect' => true,
		'res' => '127.0.0.1',
		'output' => '127.0.0.1',
	],
	'ipv6',
	[
		'ipv6', 'abc',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'ipv6', '::1',
		'strict' => true,
		'expect' => true,
	],
	[
		'ipv6; default: ::1', '127.0.0.1',
		'strict' => false,
		'expect' => true,
		'res' => '::1',
	],
	[
		'ipv6', '::1',
		'strict' => true,
		'expect' => true,
		'res' => '::1',
		'output' => '::1',
	],
	'ip',
	[
		'ip', 'af:af:af',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'ip', '127.0.0.1',
		'strict' => false,
		'expect' => true,
	],
	[
		'ip', '::1',
		'strict' => false,
		'expect' => true,
	],
	[
		'ip; default: 127.0.0.1', '::1',
		'strict' => false,
		'expect' => true,
		'res' => '::1',
	],
	[
		'ip; default: 127.0.0.1', 'abc',
		'strict' => true,
		'expect' => true,
		'res' => '127.0.0.1',
		'output' => '127.0.0.1',
	],
	'mac',
	[
		'mac', 'abcabc',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'mac', '00:1A:2B:3C:4D:5E',
		'strict' => false,
		'expect' => true,
	],
	[
		'mac; default: 00:1A:2B:3C:4D:5E', 'abcbac',
		'strict' => false,
		'expect' => true,
		'res' => '00:1A:2B:3C:4D:5E',
	],
	[
		'mac', '00:1A:2B:3C:4D:5E',
		'strict' => false,
		'expect' => true,
		'res' => '00:1A:2B:3C:4D:5E',
		'output' => '00:1A:2B:3C:4D:5E',
	],
	'port',
	[
		'port', 12345,
		'strict' => true,
		'expect' => true,
	],
	[
		'port', '12345',
		'strict' => false,
		'expect' => true,
	],
	[
		'port', '12345',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'port', 100000,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'port', -100,
		'strict' => false,
		'expect' => 'app',
	],
	[
		'port', '100000',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'port; default: 32000', 'abc',
		'strict' => false,
		'expect' => true,
		'res' => 32000,
	],
	[
		'port', 123,
		'strict' => true,
		'expect' => true,
		'res' => 123,
		'output' => 123,
	],
	[
		'port', '123',
		'strict' => false,
		'expect' => true,
		'res' => 123,
		'output' => 123,
	],
	'slug',
	[
		'slug', 123,
		'strict' => false,
		'expect' => true,
		'res' => '123',
	],
	[
		'slug', 123,
		'strict' => true,
		'expect' => 'app',
	],
	[
		'slug', '123 à côté',
		'strict' => false,
		'expect' => true,
		'res' => '123-a-cote',
	],
	[
		'slug; default: aaa-bbb', 'aa bb',
		'strict' => true,
		'expect' => true,
		'res' => 'aaa-bbb',
	],
	[
		'slug; minLen: 3', 'aa',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'slug; maxLen: 2', 'abcdef',
		'strict' => false,
		'expect' => true,
		'res' => 'ab',
	],
	[
		'slug; maxLen: 2', 'abcdef',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'slug; mask: ^article-', 'article-1234',
		'strict' => false,
		'expect' => true,
	],
	[
		'slug; mask: ^article-', 'post-1234',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'slug', 'aa',
		'strict' => true,
		'expect' => true,
		'res' => 'aa',
		'output' => 'aa',
	],
	'color',
	[
		'color', '#0000ff',
		'strict' => false,
		'expect' => true,
		'res' => '#0000ff',
	],
	[
		'color', 'AFAF80',
		'strict' => true,
		'expect' => true,
		'res' => '#afaf80',
	],
	[
		'color', '#af8',
		'strict' => true,
		'expect' => true,
		'res' => '#aaff88',
	],
	[
		'color; default: #aabbcc', 'zzz',
		'strict' => false,
		'expect' => true,
		'res' => '#aabbcc',
	],
	[
		'color', 'aabbcc',
		'strict' => true,
		'expect' => true,
		'res' => '#aabbcc',
		'output' => '#aabbcc',
	],
	'geo',
	[
		'geo', '48.8566, 2.3522',
		'strict' => true,
		'expect' => true,
	],
	[
		'geo', '123',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'geo; default: 48.8566, 2.3522', 123,
		'strict' => false,
		'expect' => true,
		'res' => '48.8566, 2.3522',
	],
	[
		'geo', '48.8566, 2.3522',
		'strict' => true,
		'expect' => true,
		'res' => '48.8566, 2.3522',
		'output' => '48.8566, 2.3522',
	],
	'phone',
	[
		'phone', 123456,
		'strict' => false,
		'expect' => true,
	],
	[
		'phone', 'abcdef',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'phone', '+33102030405',
		'strict' => true,
		'expect' => true,
		'res' => '+33102030405',
	],
	[
		'phone', '+33 1.02 03-04.05',
		'strict' => true,
		'expect' => true,
		'res' => '+33102030405',
	],
	[
		'phone; default: +1 514-123-4567', null,
		'strict' => false,
		'expect' => true,
		'res' => '+1 514-123-4567',
	],
	[
		'phone', '0102030405',
		'strict' => true,
		'expect' => true,
		'res' => '0102030405',
		'output' => '0102030405',
	],
	'enum',
	[
		'enum', 'toto',
		'strict' => true,
		'expect' => 'io',
	],
	[
		'enum; values: red, green, blue', 'red',
		'strict' => false,
		'expect' => true,
		'res' => 'red',
	],
	[
		'enum; values: red, green, blue', 'yellow',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'enum; values: red, green, blue; default: blue', 'yellow',
		'strict' => false,
		'expect' => true,
		'res' => 'blue',
	],
	[
		'enum; values: red, green, blue; default: indigo', 'yellow',
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => 'enum',
			'values' => ['red', 'green', 'blue'],
			'default' => 'blue',
		],
		'red',
		'strict' => true,
		'expect' => true,
		'res' => 'red',
	],
	[
		'enum; values: red, green', 'red',
		'strict' => true,
		'expect' => true,
		'res' => 'red',
		'output' => 'red',
	],
	'list',
	[
		'list', [1, 2],
		'strict' => false,
		'expect' => true,
	],
	[
		'list; contract: int', [1, 2],
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'type' => 'list',
			'minLen' => 3,
			'contract' => 'int',
		],
		[1, 2],
		'strict' => false,
		'expect' => 'app',
	],
	[
		'list; maxLen: 3', [1, 2, 3, 4, 5],
		'strict' => false,
		'expect' => true,
	],
	[
		'list; maxLen: 3', [1, 2, 3, 4, 5],
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'list',
			'contract' => 'int',
			'default' => [1, 2, 3],
		],
		'abc',
		'strict' => true,
		'expect' => true,
	],
	[
		'list; values: int, int, float, string, int', [12, 23, 34.45, 'abcdef'],
		'strict' => false,
		'expect' => true,
	],
	[
		'list; values: int, int, float, string, int', [12, 23, 34.45, 'abcdef'],
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'list',
			'values' => [
				'int',
				'int',
				'float',
				'string',
				'int',
			],
		],
		[12, 23, 34.45, 'abcdef', 12],
		'strict' => true,
		'expect' => true,
	],
	[
		[
			'type' => 'list',
			'values' => [
				'int; min: 333',
				'int',
				'float',
				'string',
				'int',
			],
		],
		[12, 23, 34.45, 'abcdef', 12],
		'strict' => true,
		'expect' => 'app',
	],
	[
		'list; values: int, int, string, ...', [12, 23, 'abc', 'def', 1234],
		'strict' => false,
		'expect' => true,
	],
	[
		'list; values: int, string, ...', [12, 23, 'abc', 'def', 1234],
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'type' => 'list',
			'values' => [
				'int',
				'int; max: 200',
				'string; minLen: 2',
				'...'
			],
		],
		[12, 23, 'abc', 'def', 1234],
		'strict' => true,
		'expect' => true,
	],
	[
		'list', [1, 2],
		'strict' => true,
		'expect' => true,
		'res' => [1, 2],
		'output' => [1, 2],
	],
	'assoc',
	[
		'assoc', null,
		'strict' => false,
		'expect' => 'io',
	],
	[
		'assoc; keys: id, login', ['id' => 1, 'login' => 'aa', 'toto' => 'tutu'],
		'strict' => false,
		'expect' => true,
	],
	[
		'assoc; keys: id, login?', ['id' => 1],
		'strict' => false,
		'expect' => true,
	],
	[
		'assoc; keys: id, login', ['id' => 1, 'login' => 'aa', 'toto' => 'tutu'],
		'strict' => true,
		'expect' => 'app',
	],
	[
		'assoc; keys: id, login, ...', ['id' => 1, 'login' => 'aa', 'toto' => 'tutu'],
		'strict' => true,
		'expect' => true,
	],
	[
		[
			'type' => 'assoc',
			'keys' => [
				'id' => 'int',
				'login' => '?string',
				'name?' => 'string',
			],
		],
		['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'type' => 'assoc',
			'keys' => [
				'id' => 'int',
				'login' => '?string',
				'name?' => 'string',
			],
		],
		['id' => '3', 'login' => null],
		'strict' => false,
		'expect' => true,
	],
	[
		[
			'id' => 'int',
			'login' => 'string',
			'...',
		],
		[
			'id' => 3,
			'login' => 'toto',
			'name' => 'bidule',
		],
		'strict' => true,
		'expect' => true,
	],
	[
		'assoc; keys: aa, bb', ['aa' => 1, 'bb' => 2],
		'strict' => true,
		'expect' => true,
		'res' => ['aa' => 1, 'bb' => 2],
		'output' => ['aa' => 1, 'bb' => 2],
	],
	'json',
	[
		'json', '{abc!',
		'strict' => false,
		'expect' => 'app',
	],
	[
		'json', '{"id": 3, "name": "Toto"}',
		'strict' => true,
		'expect' => true,
	],
	[
		'json; default: 123', '{abc!',
		'strict' => false,
		'expect' => true,
		'res' => 123,
	],
	[
		'json', '{"id" : 3, "name": "Toto"}',
		'strict' => true,
		'except' => true,
		'res' => '{"id" : 3, "name": "Toto"}',
		'output' => ['id' => 3, 'name' => 'Toto'],
	],
	[
		[
			'type' => 'json',
			'contract' => [
				'type' => 'assoc',
				'keys' => [
					'id' => 'int',
					'name' => 'string',
				],
			],
		],
		'{"id": 3, "name": "Toto"}',
		'strict' => true,
		'expect' => true,
		'res' => '{"id": 3, "name": "Toto"}',
		'output' => ['id' => 3, 'name' => 'Toto'],
	],
	'list + assoc',
	[
		[
			'type' => 'list',
			'contract' => [
				'type' => 'assoc',
				'keys' => ['id', 'login'],
			]
		],
		[
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'strict' => false,
		'expect' => true,
		'res' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi'],
		],
		'output' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi'],
		],
	],
	[
		[
			'type' => 'list',
			'contract' => [
				'type' => 'assoc',
				'keys' => ['id', 'login'],
			]
		],
		[
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'list',
			'contract' => [
				'id',
				'login',
			]
		],
		[
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'strict' => false,
		'expect' => true,
		'res' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi'],
		],
		'output' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi'],
		],
	],
	[
		[
			'type' => 'list',
			'contract' => [
				'id',
				'login',
			]
		],
		[
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'strict' => true,
		'expect' => 'app',
	],
	[
		[
			'type' => 'list',
			'contract' => [
				'id',
				'login',
				'...',
			]
		],
		[
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'strict' => true,
		'expect' => true,
		'res' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
		'output' => [
			['id' => 3, 'login' => 'toto'],
			['id' => 4, 'login' => 'tutu'],
			['id' => 5, 'login' => 'titi', 'name' => 'aaa'],
		],
	],
	'?int',
	[
		'?int', '12',
		'strict' => false,
		'expect' => true,
		'res' => 12,
	],
	[
		'?int', '12',
		'strict' => true,
		'expect' => 'app',
	],
	[
		'?int', null,
		'strict' => true,
		'expect' => true,
		'res' => null,
	],
	[
		'null|int', 3,
		'strict' => false,
		'expect' => true,
		'res' => 3,
		'output' => 3,
	],
	[
		'=null|int', null,
		'strict' => false,
		'expect' => true,
		'res' => null,
		'output' => null,
	],
	'int|float',
	[
		'int|float', 12.34,
		'strict' => false,
		'expect' => true,
		'res' => 12,
		'output' => 12,
	],
	[
		'=int|float', 12.34,
		'strict' => false,
		'expect' => true,
		'res' => 12.34,
		'output' => 12.34,
	],
	'int|string',
	[
		'int|string', '12',
		'strict' => false,
		'expect' => true,
		'res' => 12,
		'output' => 12,
	],
	[
		'int|string', '12',
		'strict' => true,
		'expect' => true,
		'res' => '12',
		'output' => '12',
	],
	[
		'int|string', 'abc',
		'strict' => false,
		'expect' => true,
		'res' => 'abc',
		'output' => 'abc',
	],
	'string|list',
	[
		'string|list', 'abc',
		'strict' => false,
		'expect' => true,
		'res' => 'abc',
		'output' => 'abc',
	],
	[
		'string|list', [1, 2, 3],
		'strict' => false,
		'expect' => true,
		'res' => [1, 2, 3],
		'output' => [1, 2, 3],
	],
	'int|list|assoc',
	[
		[
			'type' => 'int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		123,
		'strict' => true,
		'expect' => true,
		'res' => 123,
		'output' => 123,
	],
	[
		[
			'type' => '~int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		123,
		'strict' => true,
		'expect' => true,
		'res' => 123,
		'output' => 123,
	],
	[
		[
			'type' => 'int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		['aa', 'bb'],
		'strict' => true,
		'expect' => true,
		'res' => ['aa', 'bb'],
		'output' => ['aa', 'bb'],
	],
	[
		[
			'type' => '~int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		['aa', 12],
		'strict' => true,
		'expect' => true,
		'res' => ['aa', '12'],
		'output' => ['aa', '12'],
	],
	[
		[
			'type' => '=int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		['aa', 12],
		'strict' => false,
		'expect' => 'app',
	],
	[
		[
			'type' => 'int|list|assoc',
			'contract' => 'string',
			'keys' => ['id' => 'int', 'login' => 'string'],
		],
		['id' => 3, 'login' => 'toto'],
		'strict' => true,
		'expect' => true,
		'res' => ['id' => 3, 'login' => 'toto'],
		'output' => ['id' => 3, 'login' => 'toto'],
	],
	'user',
	[
		'user', ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'strict' => false,
		'expect' => true,
		'res' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'output' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
	],
	[
		'user', ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'strict' => true,
		'expect' => true,
		'res' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'output' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
	],
	[
		'user', ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto', 'age' => 33],
		'strict' => false,
		'expect' => true,
		'res' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
		'output' => ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto'],
	],
	[
		'user', ['id' => 3, 'login' => 'toto', 'name' => 'Mister Toto', 'age' => 33],
		'strict' => true,
		'expect' => 'app',
	],
	'category',
	[
		'category', 3,
		'strict' => false,
		'expect' => true,
		'res' => ['id' => 3, 'name' => 'Catégorie 1', 'dateCreation' => '2026-02-20 16:49:32'],
		'output' => ['id' => 3, 'name' => 'Catégorie 1', 'dateCreation' => '2026-02-20 16:49:32'],
	],
	[
		'category', 'toto',
		'strict' => false,
		'expect' => 'app',
	],
];

$count = 1;
$mustBreak = false;
foreach ($tests as $test) {
	if (is_null($test))
		break;
	if (is_string($test)) {
		if ($mustBreak)
			break;
		print(TµAnsi::bold("$test\n"));
		$count = 1;
		continue;
	}
	$res = null;
	$output = null;
	$status = null; // statut du test (true = test réussi, false = test échoué)
	$contract = $test[0]; // définition du contrat (string ou array)
	$data = $test[1]; // donnée à valider
	$strict = $test['strict'] ?? false; // test strict ou non
	$expect = $test['expect'] ?? null;  // résultat attendu (true = test réussi, false = test échoué)
	$ioE = false; // true si IOException
	$appE = false; // true si ApplicationException
	$exceptionMsg = null;
	try {
		$res = TµDataFilter::process($data, $contract, $strict, output: $output);
		$status = true;
		if ($expect && is_string($expect))
			$status = false;
		if (array_key_exists('res', $test)) {
			if (var_export($res, true) != var_export($test['res'], true))
				$status = false;
		}
		if (array_key_exists('output', $test)) {
			if (var_export($output, true) != var_export($test['output'], true))
				$status = 0;
		}
	} catch (TµIOException $ie) {
		$ioE = true;
		$status = ($expect === 'io') ? true : false;
		$exceptionMsg = $ie->getMessage();
	} catch (TµApplicationException $ae) {
		$appE = true;
		$status = ($expect === 'app') ? true : false;
		$exceptionMsg = $ae->getMessage();
	}
	print(TµAnsi::faint(sprintf("%02d", $count)) . ' ' .
	      TµAnsi::color(($status ? 'green' : ($status === false ? 'red' : 'yellow')), ($status ? 'OK' : 'KO')) . ' ' .
	      TµAnsi::color('blue', "'" . (is_string($contract) ? $contract : (isset($contract['type']) ? $contract['type'] : 'inferred assoc')) . "' ") .
	      TµAnsi::color('yellow', ($ioE ? 'IOException ' : '') . ($appE ? 'AppException ' : '')) .
	      TµAnsi::faint($exceptionMsg) .
	      "\n");
	if (($test['dump'] ?? false)) {
		var_dump($res);
		var_dump($output);
	}
	if (!$status)
		$mustBreak = true;
	$count++;
}

