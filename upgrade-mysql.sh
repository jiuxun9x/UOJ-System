#!/bin/bash
# Moves the database of the site from MySQL 5.7 to MySQL 8.4, in its own data folder. MySQL
# goes from one long-term version to the next only, so the data passes through 8.0 on the way.
# Run it once, in this folder, after `git pull`, in place of `docker compose build && up`:
#
#   bash upgrade-mysql.sh
#
# What it does:
#   1. a backup of the site (backup:run), while the old database still runs;
#   2. stops the site, shuts MySQL 5.7 down the slow and clean way, and keeps a copy of the
#      data folder as it was, uoj_data/db/mysql.5.7;
#   3. starts MySQL 8.0 on the data folder, which upgrades it, and gives the accounts that log
#      in with mysql_native_password the authentication of 8.4 (8.4 does not load the old
#      one), keeping their passwords;
#   4. builds the new images and starts everything: MySQL 8.4 upgrades the folder once more.
#
# It needs the passwords: the one of the site is read from .config.local.php, the one of root
# is UOJ_DB_ROOT_PASSWORD (root, unless it was changed as docs/deployment.md 12.1 says).
# UOJ_SKIP_BUILD=1 starts the images as they were built already, without building them.
# To go back: docker compose down, put uoj_data/db/mysql.5.7 in place of uoj_data/db/mysql,
# and start the images of the version before.
set -euo pipefail
cd "$(dirname "$0")"

compose=${UOJ_COMPOSE:-docker compose}
data="$PWD/uoj_data/db"
root_password=${UOJ_DB_ROOT_PASSWORD:-root}
mysql57=${UOJ_MYSQL57_IMAGE:-mysql:5.7}
mysql80=${UOJ_MYSQL80_IMAGE:-mysql:8.0}
temp=uoj-db-upgrade

say() { printf '==> %s\n' "$*"; }
die() { printf 'upgrade-mysql.sh: %s\n' "$*" >&2; exit 1; }

# the data folder belongs to the user of MySQL in its containers: it is looked at from one
in_data() { docker run --rm -v "$data:/d" --entrypoint sh "$mysql80" -c "$1"; }

# a temporary MySQL on the data folder, reached through its socket only: start_temp image
# [options of mysqld]
start_temp() {
	docker rm -f "$temp" > /dev/null 2>&1 || true
	docker run -d --name "$temp" -v "$data/mysql:/var/lib/mysql" "$1" --skip-networking "${@:2}" > /dev/null
	for _ in $(seq 1 600); do
		if docker exec "$temp" mysqladmin ping --silent > /dev/null 2>&1; then
			return 0
		fi
		if [ "$(docker inspect -f '{{.State.Running}}' "$temp" 2> /dev/null)" != "true" ]; then
			docker logs --tail 40 "$temp" >&2 || true
			die "$1 did not start on the data folder; it is as it was before this step"
		fi
		sleep 1
	done
	docker logs --tail 40 "$temp" >&2 || true
	die "$1 was not ready after ten minutes"
}
temp_sql() { docker exec -i "$temp" mysql -uroot -p"$root_password" -N -B "$@" 2> >(grep -v 'Using a password' >&2); }
stop_temp() {
	# the slow shutdown leaves nothing for the next version to recover
	temp_sql -e "SET GLOBAL innodb_fast_shutdown = 0" > /dev/null
	docker exec "$temp" mysqladmin -uroot -p"$root_password" shutdown 2> /dev/null || true
	docker wait "$temp" > /dev/null
	docker rm "$temp" > /dev/null
}

[ -d "$data/mysql" ] || die "there is no database in $data/mysql"
# MySQL 8 keeps its data dictionary in mysql.ibd; 5.7 has none
if [ -n "$(in_data 'test -f /d/mysql/mysql.ibd && echo 8')" ]; then
	version=$(in_data 'cat /d/mysql/mysql_upgrade_info 2> /dev/null' | tr -dc '0-9.')
	case "$version" in
		8.4*) say "The database is MySQL $version already: starting the images"
			[ "${UOJ_SKIP_BUILD:-0}" = 1 ] || $compose build
			$compose up -d
			exit 0 ;;
		8.0*) say "The database is MySQL $version: it was half way, going on from there" ;;
		*) die "the data folder is of MySQL ${version:-8 of no known version}, which this script does not know" ;;
	esac
else
	version=5.7
fi

# the account of the site, as the site has it
site=$($compose run --rm --no-deps -T --entrypoint php uoj-web -r '
	class UOJContext { public static function __callStatic($name, $args) { return ""; } }
	$config = include "/opt/uoj/web/app/.config.php";
	$db = $config["database"];
	echo $db["username"], "\n", base64_encode($db["password"]), "\n", $db["host"], "\n";')
site_user=$(sed -n 1p <<< "$site")
site_password=$(sed -n 2p <<< "$site" | base64 -d)
site_host=$(sed -n 3p <<< "$site")
[ "$site_host" = "uoj-db" ] || die "the site uses the database at $site_host, not the one of this folder: upgrade that one as its own documentation says"

if [ "$version" = 5.7 ]; then
	if $compose ps --status running --services 2> /dev/null | grep -qx uoj-web; then
		say "A backup of the site, made by the site"
		$compose exec -T uoj-web php /opt/uoj/web/app/cli.php backup:run before-mysql-8.4
	else
		say "The site does not run: no backup of its own (the copy of the data folder below is one)"
	fi

	say "Stopping the site"
	$compose stop

	say "MySQL 5.7 shuts the database down cleanly"
	start_temp "$mysql57"
	temp_sql -e "select 1" > /dev/null || die "root can not log in with UOJ_DB_ROOT_PASSWORD; set it to the password of root"
	stop_temp

	if in_data 'test -e /d/mysql.5.7'; then
		die "$data/mysql.5.7 is there already: move it away first, it may be the only copy of an older database"
	fi
	say "Keeping a copy of the data folder as uoj_data/db/mysql.5.7"
	in_data 'cp -a /d/mysql /d/mysql.5.7'
else
	say "Stopping the site"
	$compose stop
fi

say "MySQL 8.0 upgrades the data folder (this takes a while for a large database)"
# with no binary log, which 8.0 would start in the data folder
start_temp "$mysql80" --skip-log-bin
temp_sql -e "select 1" > /dev/null || die "root can not log in with UOJ_DB_ROOT_PASSWORD; set it to the password of root"
# Every account that logs in with mysql_native_password gets caching_sha2_password, with the
# password it has: root and the account of the site, whose passwords are known.
temp_sql -e "select user, host from mysql.user where plugin = 'mysql_native_password'" | while IFS=$'\t' read -r user host; do
	if [ "$user" = root ]; then
		password=$root_password
	elif [ "$user" = "$site_user" ]; then
		password=$site_password
	else
		echo "    the account $user@$host keeps mysql_native_password: MySQL 8.4 does not let it log in until its password is set again" >&2
		continue
	fi
	quoted_password=${password//\\/\\\\}
	quoted_password=${quoted_password//\'/\\\'}
	temp_sql -e "ALTER USER '$user'@'$host' IDENTIFIED WITH caching_sha2_password BY '$quoted_password'"
	echo "    $user@$host: caching_sha2_password"
done
if [ "$site_user" != root ]; then
	docker exec -i "$temp" mysql -u"$site_user" -p"$site_password" -e "select 1" > /dev/null 2>&1 || die "the account of the site, $site_user, can not log in with the password of .config.local.php"
fi
stop_temp

say "Starting the site on MySQL 8.4"
[ "${UOJ_SKIP_BUILD:-0}" = 1 ] || $compose build
$compose up -d
for _ in $(seq 1 300); do
	if docker exec uoj-db mysqladmin ping -uroot -p"$root_password" --silent > /dev/null 2>&1; then
		break
	fi
	sleep 2
done
docker exec uoj-db mysql -uroot -p"$root_password" -N -B -e "select concat('    MySQL ', version())" 2> /dev/null || die "MySQL 8.4 did not start: docker compose logs uoj-db"
say "Done. The copy of the database of MySQL 5.7 is uoj_data/db/mysql.5.7: remove it once the site works."
