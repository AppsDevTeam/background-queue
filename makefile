# https://stackoverflow.com/a/14061796/4837606
# Ulož si všechny přepínače za "--" do proměnné, tedy vezmi všechny targety od druhého po poslední a ulož je do RUN_ARGS.
RUN_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
SHELL=/bin/bash



# aplikace
# ------------------------------------------------------------------------------

init:
	docker build -t background-queue_php .
	bin/composer install



# Převeď všechny RUN_ARGS do formy:
# <target1> <target2>:;
#     @:
# , tedy nedělej nic. A protože v targetu může být $, který se evalem expanduje, tak je třeba ho escapovat druhým dolarem.
# Abychom to udělali musíme při zadávání dolary také zdvojit (takže subst nahrazuje "$" za "$$").
# Musi byt na konci, protoze pokud se parametr za -- shoduje s nazvem targetu, spusti se oba a potrebujeme, aby ten druhy byl prazdny
$(eval $(subst $$, $$$$, $(RUN_ARGS)):;@:)