<?php
# Dispatcher dell'API PDF: seleziona l'implementazione da
# usare in base alla versione di PHP in esecuzione sul server.
#
#   PHP >= 8.1  ->  pdf_php8.php   (implementazione moderna)
#   PHP <  8.1  ->  pdf_php55.php  (port compatibile PHP 5.5+)
#
# NOTA: questo file deve restare parsabile da PHP 5.5:
# niente declare(strict_types), operatori ??, tipi scalari
# o return type, perché viene eseguito anche dalle versioni
# vecchie di PHP che devono caricare pdf_php55.php.

$implementation = PHP_VERSION_ID >= 80100
    ? __DIR__ . '/pdf_php8.php'
    : __DIR__ . '/pdf_php55.php';

require $implementation;
