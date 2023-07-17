#!/bin/bash

# Validation script used to check Temma source code using PHPStan static analyzer.
# See: https://github.com/phpstan/phpstan/releases

pushd /opt/Temma/bin > /dev/null

# check if the "phpstan.phar" file exists
if [ ! -e phpstan.phar ]; then
	wget https://github.com/phpstan/phpstan/releases/download/1.10.25/phpstan.phar
	chmod +x phpstan.phar
fi
# check if the "autoloader.php" file exists
if [ ! -e autoloader.php ]; then
	echo "<?php

require_once('/opt/Temma/lib/Temma/Base/Autoload.php');

\Temma\Base\Autoload::autoload([
	'/opt/Temma/lib/',
]);
" > autoloader.php
fi

# start code anlysis
./phpstan.phar --autoload-file=autoloader.php --level=5 analyze /opt/Temma/lib/Temma | \
	grep -v "Access to an undefined property Temma.Base.Loader" | \
	grep -v "Learn more at https://phpstan.org/user-guide/discovering-symbols" | \
	grep -v "See: https://phpstan.org/developing-extensions/always-read-written-properties" | \
	grep -v "::__wakeup() with return type void returns int but should not return anything." | \
	grep -v "::__clone() with return type void returns int but should not return anything." | \
	grep -v -e "Parameter .*expects string, int.* given." | \
	grep -v -e "Parameter .*expects int.*, float.* given." | \
	grep -v -e "Parameter .*expects int, string given." | \
	grep -v -e "Parameter .*expects float|int, string given." | \
	grep -v "Learn more: https://phpstan.org/blog/solving-phpstan-access-to-undefined-property" | \
	grep -v 'Access to an undefined property Temma\\Web\\Config::' | \
	grep -v 'Method Temma\\Web\\Controller::__sleep() should return array<int, string> but return statement is missing.'

popd > /dev/null

