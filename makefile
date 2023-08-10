-include .env
export

# https://stackoverflow.com/a/14061796/4837606
# Ulož si všechny přepínače za "--" do proměnné, tedy vezmi všechny targety od druhého po poslední a ulož je do RUN_ARGS.
RUN_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
SHELL=/bin/bash

CODECEPT=bin/php vendor/bin/codecept


# aplikace
# ------------------------------------------------------------------------------

config:
	cp .env.local .env

init:
	docker exec -e COMPOSER_HOME=/var/www/html/.composer background-queue_php composer install
	echo "DROP DATABASE IF EXISTS \`$(PROJECT_DB_DBNAME)\`; CREATE DATABASE \`$(PROJECT_DB_DBNAME)\` CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci';" | docker exec -i background-queue_mysql mysql --socket /var/lib/mysql/mysql.sock -h mysql -u root -p$(PROJECT_DB_PASSWORD)
	de php rm -rf $(PROJECT_TMP_FOLDER)/*

docker-up:
	docker-compose up --force-recreate --build

# spustí unit testy
# ------------------------------------------------------------------------------

test:
	$(CODECEPT) run integration



# Převeď všechny RUN_ARGS do formy:
# <target1> <target2>:;
#     @:
# , tedy nedělej nic. A protože v targetu může být $, který se evalem expanduje, tak je třeba ho escapovat druhým dolarem.
# Abychom to udělali musíme při zadávání dolary také zdvojit (takže subst nahrazuje "$" za "$$").
# Musi byt na konci, protoze pokud se parametr za -- shoduje s nazvem targetu, spusti se oba a potrebujeme, aby ten druhy byl prazdny
$(eval $(subst $$, $$$$, $(RUN_ARGS)):;@:)