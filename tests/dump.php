<?php

require_once "vendor/autoload.php";
require_once "tests/classes.php";

$schema = new \GQLA\Schema();
echo \GraphQL\Utils\SchemaPrinter::doPrint($schema);
