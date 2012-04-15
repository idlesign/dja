<?php

require_once 'dja_bootstrap.php';


class ContextTests extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function test_context() {
        $c = new Context(array('a' => 1, 'b' => 'xyzzy'));
        $this->assertEquals($c['a'], 1);
        $this->assertEquals($c->push(), array());
        $c['a'] = 2;
        $this->assertEquals($c['a'], 2);
        $this->assertEquals($c->get('a'), 2);
        $this->assertEquals($c->pop(), array('a' => 2));
        $this->assertEquals($c['a'], 1);
        $this->assertEquals($c->get('foo', 42), 42);
    }

}
