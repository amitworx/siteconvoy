#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

set -ex

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# Resolve latest stable version from WordPress.org API.
	WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*"' | head -n 1 | sed 's/"version":"//;s/"//')
	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.0$ ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%.0}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
fi

mkdir -p "$WP_CORE_DIR"

if [ ! -f "${WP_CORE_DIR}/wp-load.php" ]; then
	curl -sL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar --strip-components=1 -zxmf - -C "$WP_CORE_DIR"
fi

mkdir -p "$WP_TESTS_DIR"

if [ ! -f "${WP_TESTS_DIR}/includes/functions.php" ]; then
	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes"
	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data"
fi

if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
	curl -sL "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" -o "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s:__DIR__ . '/src/':'${WP_CORE_DIR}/':" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s/yourusernamehere/$DB_USER/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s/yourpasswordhere/$DB_PASS/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed -i "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
fi

if [ "$SKIP_DB_CREATE" != "true" ]; then
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp || true
fi
