#!/bin/bash

# Validation script used to check Temma source code using PHPStan static analyzer.
# See: https://github.com/phpstan/phpstan/releases

# find the current directory
DIRNAME="$(dirname "$0")"
PWD="$(pwd)"
CURRENT_DIR=""
if [ "${DIRNAME:0:1}" = "/" ]; then
	CURRENT_DIR="$DIRNAME"
elif [ "$DIRNAME" != "." ]; then
	CURRENT_DIR="$PWD/$DIRNAME"
else
	CURRENT_DIR="$PWD"
fi

VALIDATION_PATHS="$CURRENT_DIR/../controllers
                  $CURRENT_DIR/../cli
                  $CURRENT_DIR/../lib/Temma
                  $CURRENT_DIR/../lib/smarty-plugins"

pushd "$CURRENT_DIR" > /dev/null

# check if the "phpstan.phar" file exists
if [ ! -e phpstan.phar ]; then
	wget https://github.com/phpstan/phpstan/releases/download/1.10.35/phpstan.phar
	chmod +x phpstan.phar
fi
# check if the "autoloader.php" file exists
if [ ! -e autoloader.php ]; then
	echo "<?php

// Temma autoloader
require_once('/opt/Temma/lib/Temma/Base/Autoload.php');
\Temma\Base\Autoload::autoload(phpPath: '/opt/Temma/lib');

// Composer autoloader
if (file_exists('/opt/Temma/vendor/autoload.php'))
	require_once('/opt/Temma/vendor/autoload.php');

// load Smarty library
include_once('smarty4/Autoloader.php');
include_once('smarty4/bootstrap.php');
require_once('smarty4/Smarty.class.php');
" > autoloader.php
fi

# check if the aws.phar file is copied
if [ ! -f /opt/Temma/lib/aws.phar ]; then
	if [ -f /tmp/aws.phar ]; then
		mv /tmp/aws.phar /opt/Temma/lib/aws.phar
	else
		wget -O /opt/Temma/lib/aws.phar https://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.phar
	fi
fi
# check if the Smarty library is available
if [ ! -d /opt/Temma/lib/Smarty ]; then
	# Smarty 4
	if [ ! -f /tmp/smarty-4.5.4.tgz ]; then
		wget https://github.com/smarty-php/smarty/archive/refs/tags/v4.5.4.tar.gz -O /tmp/smarty-4.5.4.tgz
	fi
	pushd /opt/Temma/lib > /dev/null
	cp /tmp/smarty-4.5.4.tgz /opt/Temma/lib/
	tar xzf smarty-4.5.4.tgz smarty-4.5.4/libs
	mv smarty-4.5.4/libs smarty4
	rm -rf smarty-4.5.4.tgz smarty-4.5.4
	popd > /dev/null
	# Smarty 5
	if [ ! -f /tmp/smarty-5.4.1.tgz ]; then
		wget https://github.com/smarty-php/smarty/archive/refs/tags/v5.4.1.tar.gz -O /tmp/smarty-5.4.1.tgz
	fi
	pushd /opt/Temma/lib > /dev/null
	cp -a /tmp/smarty-5.4.1.tgz /opt/Temma/lib/
	tar xzf smarty-5.4.1.tgz smarty-5.4.1/src
	mv smarty-5.4.1/src Smarty
	rm -rf smarty-5.4.1.tgz smarty-5.4.1
	popd > /dev/null
fi
# check if the vendor directory exists and contains the Pheanstalk package
if [ ! -d /opt/Temma/vendor ]; then
	if [ -d /tmp/temma-vendor ]; then
		mv /tmp/temma-vendor /opt/Temma/vendor
	fi
	if [ ! -d /opt/Temma/vendor/pda/pheanstalk ]; then
		pushd /opt/Temma > /dev/null
		echo '{
    "require": {
        "pda/pheanstalk": "v5.x-dev"
    }
}' > composer.json
		composer update
		rm composer.json composer.lock
		popd > /dev/null
	fi
fi

# start code anlysis
RESULT=$(
./phpstan.phar --autoload-file=autoloader.php --level=5 analyze $VALIDATION_PATHS | \
	grep -v "Access to an undefined property Temma.Base.Loader" | \
	grep -v "Learn more at https://phpstan.org/user-guide/discovering-symbols" | \
	grep -v "Learn more: https://phpstan.org/blog/solving-phpstan-access-to-undefined-property" | \
	grep -v "See: https://phpstan.org/developing-extensions/always-read-written-properties" | \
	grep -v "::__wakeup() with return type void returns int but should not return anything." | \
	grep -v "::__clone() with return type void returns int but should not return anything." | \
	grep -v -e "Parameter .*expects string, int.* given." | \
	grep -v -e "Parameter .*expects int.*, float.* given." | \
	grep -v -e "Parameter .*expects int, string given." | \
	grep -v -e "Parameter .*expects int, string|null given." | \
	grep -v -e "Parameter .*expects float|int, string given." | \
	grep -v 'Access to an undefined property Temma\\Web\\Config::' | \
	grep -v 'Method Temma\\Web\\Controller::__sleep() should return array<int, string> but return statement is missing.'
)
echo "
$RESULT" | tr '\n' '\a' | sed 's/\a ------ -[^\a]*\a  Line[^\a]*\a ------ -[^\a]*\a ------ -[^\a]*\a//g' | sed 's/^\a//' | tr '\a' '\n'

popd > /dev/null

# cleanup
mv /opt/Temma/lib/aws.phar /tmp/aws.phar
mv /opt/Temma/vendor /tmp/temma-vendor
rm -rf /opt/Temma/lib/Smarty /opt/Temma/lib/smarty4

