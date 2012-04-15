<?php

require_once '../dja/dja.php';

define('DJA_TESTS_DIR', dirname(__FILE__));

// Add current directory to apps, so that tests can find `templates` and `templatetags`.
Dja::setSetting('INSTALLED_APPS', array(DJA_TESTS_DIR));
Dja::setSetting('TEMPLATE_DIRS', array(DJA_TESTS_DIR, 'templates'));
