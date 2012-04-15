<?php

require_once 'dja_bootstrap.php';

require_once 'tests.php';
require_once 'callables.php';
require_once 'context.php';
require_once 'nodelist.php';
require_once 'parser.php';
require_once 'python_helpers.php';


class AllTests {

    public static function suite() {
        $suite = new PHPUnit_Framework_TestSuite('DjaBase Tests');
        $suite->addTestSuite('TemplatesTests');
        $suite->addTestSuite('CallableVariablesTests');
        $suite->addTestSuite('ContextTests');
        $suite->addTestSuite('NodeListTest');
        $suite->addTestSuite('ErrorIndexTest');
        $suite->addTestSuite('ParserTest');
        $suite->addTestSuite('PythonHelpersTest');
        return $suite;
    }
}
