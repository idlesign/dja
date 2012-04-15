<?php

$lib = new Library();
$lib->simpleTag('echo2', function($arg) { return $arg; });
return $lib;