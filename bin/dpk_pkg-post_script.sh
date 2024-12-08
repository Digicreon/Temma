#!/usr/bin/env bash

# Post-packacing script

# platform parameter
PKG_PLATFORM="$1"
# new tag parameter
PKG_TAG="$2"

if [ "$PKG_PLATFORM" = "" ] || [ "$PKG_TAG" = "" ]; then
	echo "Empty parameter."
	echo "Usage: $0 PLATFORM TAG"
	echo "    PLATFORM = dev|test|prod"
	echo "    TAG      = new tag number"
	exit 1
fi

# ########## temma-lib ##########
# check if the directory exists
if [ ! -d /opt/temma-lib ]; then
	echo "No directory '/opt/temma-lib' found."
else
	echo "== Update 'temma-lib'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp -a lib/*         /opt/temma-lib/lib/
	popd > /dev/null
	# commit tiles
	pushd /opt/temma-lib > /dev/null
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
		dpk pkg --tag=$PKG_TAG
	fi
	echo "   done"
	popd > /dev/null
fi

# ########## temma-skel-web ##########
# check if the directory exists
if [ ! -d /opt/temma-skel-web ]; then
	echo "No directory '/opt/temma-skel-web' found."
else
	echo "== Update 'temma-skel-web'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp    bin/comma          /opt/temma-skel-web/bin/
	cp    etc/apache.conf    /opt/temma-skel-web/etc/
	cp    etc/temma-mini.php /opt/temma-skel-web/etc/temma.php
	cp    etc/temma-full.php /opt/temma-skel-web/etc/
	cp -a etc/asynk          /opt/temma-skel-web/etc/
	cp -a templates/*        /opt/temma-skel-web/templates/
	cp    tests/*            /opt/temma-skel-web/tests/
	cp    www/index.php      /opt/temma-skel-web/www/
	cp    www/*.html         /opt/temma-skel-web/www/
	cp    www/.htaccess      /opt/temma-skel-web/www/
	cp    .htaccess          /opt/temma-skel-web/
	# manage composer.json
	pushd /opt/temma-skel-web > /dev/null
	sed "s/\"digicreon\/temma-lib\": \"[^\"]*\"/\"digicreon\/temma-lib\": \"$PKG_TAG\"/" composer.json > composer.json.tmp
	mv composer.json.tmp composer.json
	# commit tiles
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
		dpk pkg --tag=$PKG_TAG
	fi
	echo "   done"
	popd > /dev/null
fi

# ########## temma-skel-api ##########
# check if the directory exists
if [ ! -d /opt/temma-skel-api ]; then
	echo "No directory '/opt/temma-skel-api' found."
else
	echo "== Update 'temma-skel-api'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp    bin/comma              /opt/temma-skel-api/bin/
	cp    etc/apache.conf        /opt/temma-skel-api/etc/
	cp    etc/temma-mini-api.php /opt/temma-skel-api/etc/temma.php
	cp    etc/temma-full.php     /opt/temma-skel-api/etc/
	cp -a etc/asynk              /opt/temma-skel-api/etc/
	cp    tests/*                /opt/temma-skel-api/tests/
	cp    www/index.php          /opt/temma-skel-web/www/
	cp    www/.htaccess          /opt/temma-skel-api/www/
	cp    .htaccess              /opt/temma-skel-api/
	# manage composer.json
	pushd /opt/temma-skel-api > /dev/null
	sed "s/\"digicreon\/temma-lib\": \"[^\"]*\"/\"digicreon\/temma-lib\": \"$PKG_TAG\"/" composer.json > composer.json.tmp
	mv composer.json.tmp composer.json
	# commit tiles
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
		dpk pkg --tag=$PKG_TAG
	fi
	echo "   done"
	popd > /dev/null
fi

