#!/usr/bin/env bash
# Reexecuta apenas os testes que falharam na última rodada (usa .phpunit.result.cache).
# Uso: bin/phpunit-failed.sh   (rodar dentro do diretório do plugin; a rodada completa gera o cache)
set -e
cd "$(dirname "$0")/.."
CACHE=.phpunit.result.cache
[ -f "$CACHE" ] || { echo "Sem $CACHE: rode a suíte uma vez com --cache-result."; exit 1; }
FILTER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);$d=$d["defects"]??[];
$n=[];foreach(array_keys($d) as $k){$n[]=preg_quote(preg_replace("/ with data set.*/","",$k),"/");}
echo implode("|",array_unique($n));' "$CACHE")
[ -n "$FILTER" ] || { echo "Nenhum teste vermelho na última rodada."; exit 0; }
exec ../../bin/phpunit -d memory_limit=2048M --bootstrap ../../vendor/autoload.php --configuration phpunit.xml --testsuite=all --cache-result --filter "/($FILTER)/" "$@"
