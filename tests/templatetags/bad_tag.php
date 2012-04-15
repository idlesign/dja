<?php

$lib = new Library();


$lib->tag('badtag', function($parser, $token) { throw new RuntimeError('I am a bad tag'); });
$lib->simpleTag('badsimpletag', function() { throw new RuntimeError('I am a bad simpletag'); });

return $lib;
