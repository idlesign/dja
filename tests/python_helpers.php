<?php

require_once 'dja_bootstrap.php';


class PythonHelpersTest extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function testStrStartsWith() {
        $this->assertFalse(py_str_starts_with('abcd', 'q'));
        $this->assertFalse(py_str_starts_with(' abcd', 'ab'));
        $this->assertFalse(py_str_starts_with(' фыв', 'ab'));
        $this->assertFalse(py_str_starts_with('', 'abcd'));
        $this->assertTrue(py_str_starts_with('source', 'so'));
    }

    public function testStrEndsWith() {
        $this->assertFalse(py_str_ends_with('abcd', 'q'));
        $this->assertFalse(py_str_ends_with('abcd ', 'ab'));
        $this->assertFalse(py_str_ends_with('фыв ', 'ab'));
        $this->assertFalse(py_str_ends_with('', 'abcd'));
        $this->assertTrue(py_str_ends_with('source', 'ce'));
    }

    public function testStrSlice() {
        $this->assertEquals('pyt', py_slice('python_power', null, 3));
        $this->assertEquals('power', py_slice('python_power', 7));
        $this->assertEquals('power', py_slice('python_power', -5));
        $this->assertEquals('pow', py_slice('python_power', -5, -2));
        $this->assertEquals('python_', py_slice('python_power', null, -5));
        $this->assertEquals(' a ', py_slice('{{ a }}', 2, -2));
    }

    public function testArrPop() {
        $input_arr = array('first', 'second', 'third', 'fourth');
        py_arr_pop($input_arr);
        $this->assertEquals($input_arr, array('first', 'second', 'third'));
        py_arr_pop($input_arr, 0);
        $this->assertEquals($input_arr, array('second', 'third'));
        py_arr_pop($input_arr, 1);
        $this->assertEquals($input_arr, array('second'));
    }

    public function testArrInsert() {
        $empty_arr = array();
        py_arr_insert($empty_arr, 0, 'first');
        $this->assertEquals($empty_arr, array('first'));

        $input_arr = array('second', 'fourth');
        py_arr_insert($input_arr, 0, 'first');
        $this->assertEquals($input_arr, array('first', 'second', 'fourth'));
        py_arr_insert($input_arr, 2, 'third');
        $this->assertEquals($input_arr, array('first', 'second', 'third', 'fourth'));
    }

    public function testArrVal() {
        $input_arr = array('first', 'second', 'third', 'fourth');
        $this->assertEquals('second', py_arr_get($input_arr, 1));
        $this->assertEquals('third', py_arr_get($input_arr, 2));
        $this->assertEquals('third', py_arr_get($input_arr, -2));
        $this->assertEquals('second', py_arr_get($input_arr, -3));
    }

    public function testReFinditer() {
        $matches = new PyReFinditer('~(?P<digit>[\d])(?P<letter>[^\d])~u', '1a2b3ц');
        $this->assertTrue(is_a($matches[0], 'pyReMatchObject'));
        $this->assertEquals(0, $matches[0]->start());
        $this->assertEquals(2, $matches[0]->end());
        $this->assertEquals(array(0, 2), $matches[0]->span());
        $this->assertEquals(2, $matches[1]->start());
        $this->assertEquals(4, $matches[1]->end());
        $this->assertEquals(array(2, 4), $matches[1]->span());
        $this->assertEquals(4, $matches[2]->start());
        $this->assertEquals(6, $matches[2]->end());
        $this->assertEquals(array(4, 6), $matches[2]->span());
        $this->assertEquals('1a', $matches[0]->group(0));
        $this->assertEquals('2b', $matches[1]->group(0));
        $this->assertEquals('3ц', $matches[2]->group(0));
        $this->assertEquals('1', $matches[0]->group(1));
        $this->assertEquals('2', $matches[1]->group(1));
        $this->assertEquals('3', $matches[2]->group(1));
        $this->assertEquals('a', $matches[0]->group(2));
        $this->assertEquals('b', $matches[1]->group(2));
        $this->assertEquals('ц', $matches[2]->group(2));
        $this->assertEquals(1, $matches[0]->group('digit'));
        $this->assertEquals(2, $matches[1]->group('digit'));
        $this->assertEquals(3, $matches[2]->group('digit'));
        $this->assertEquals('a', $matches[0]->group('letter'));
        $this->assertEquals('b', $matches[1]->group('letter'));
        $this->assertEquals('ц', $matches[2]->group('letter'));
        $this->assertEquals(array(1), $matches[0]->group(array('digit')));
        $this->assertEquals(array(1, 'a'), $matches[0]->group(array('digit', 'letter')));
        $this->assertEquals(null, $matches[0]->group('no_such_group'));
        $this->assertEquals(array(1, 'a', null), $matches[0]->group(array('digit', 'letter', 'no_such_group')));
    }

}
