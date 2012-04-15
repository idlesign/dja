<?php

require_once 'dja_bootstrap.php';


class Doodad {

    public function __construct($value) {
        $this->num_calls = 0;
        $this->value = $value;
    }

    public function __invoke() {
        $this->num_calls += 1;
        return array('the_value'=>$this->value);
    }
}


class DoodadAlters {

    public $alters_data = True;

    public function __construct($value) {
        $this->num_calls = 0;
        $this->value = $value;
    }

    public function __invoke() {
        $this->num_calls += 1;
        return array('the_value'=>$this->value);
    }
}


class DoodadDoNotCall {

    public $do_not_call_in_templates = True;

    public function __construct($value) {
        $this->num_calls = 0;
        $this->value = $value;
    }

    public function __invoke() {
        $this->num_calls += 1;
        return array('the_value'=>$this->value);
    }
}


class DoodadDoNotCallAndAltersData {

    public $do_not_call_in_templates = True;
    public $alters_data = True;

    public function __construct($value) {
        $this->num_calls = 0;
        $this->value = $value;
    }

    public function __invoke() {
        $this->num_calls += 1;
        return array('the_value'=>$this->value);
    }
}


class CallableVariablesTests extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function testCallable() {
        $my_doodad = new Doodad(42);
        $c = new Context(array('my_doodad' => $my_doodad));

        /*
        We can't access ``my_doodad.value`` in the template, because
        ``my_doodad.__invoke`` will be invoked first, yielding a dictionary
        without a key ``value``.
        */
        $t = new Template('{{ my_doodad.value }}');
        $this->assertEquals($t->render($c), '');

        // We can confirm that the doodad has been called
        $this->assertEquals($my_doodad->num_calls, 1);

        // But we can access keys on the dict that's returned by ``__invoke``, instead.
        $t = new Template('{{ my_doodad.the_value }}');
        $this->assertEquals($t->render($c), '42');
        $this->assertEquals($my_doodad->num_calls, 2);
    }

    public function testAltersData() {
        $my_doodad = new DoodadAlters(42);
        $c = new Context(array('my_doodad' => $my_doodad));

        /*
        Since ``my_doodad.alters_data`` is True, the template system will not
        try to call our doodad but will use TEMPLATE_STRING_IF_INVALID
        */
        $t = new Template('{{ my_doodad.value }}');
        $this->assertEquals($t->render($c), '');

        $t = new Template('{{ my_doodad.the_value }}');
        $this->assertEquals($t->render($c), '');

        /*
        Double-check that the object was really never called during the
        template rendering.
        */
        $this->assertEquals($my_doodad->num_calls, 0);
    }

    public function test_do_not_call() {

        $my_doodad = new DoodadDoNotCall(42);
        $c = new Context(array('my_doodad' => $my_doodad));

        /*
        Since ``my_doodad.do_not_call_in_templates`` is True, the template
        system will not try to call our doodad.  We can access its attributes
        as normal, and we don't have access to the dict that it returns when
        called.
        */
        $t = new Template('{{ my_doodad.value }}');
        $this->assertEquals($t->render($c), '42');

        $t = new Template('{{ my_doodad.the_value }}');
        $this->assertEquals($t->render($c), '');

        /*
        Double-check that the object was really never called during the
        template rendering.
        */
        $this->assertEquals($my_doodad->num_calls, 0);

    }

    public function test_do_not_call_and_alters_data() {

        /*
        If we combine ``alters_data`` and ``do_not_call_in_templates``, the
        ``alters_data`` attribute will not make any difference in the
        template system's behavior.
        */

        $my_doodad = new DoodadDoNotCallAndAltersData(42);
        $c = new Context(array('my_doodad' => $my_doodad));

        $t = new Template('{{ my_doodad.value }}');
        $this->assertEquals($t->render($c), '42');

        $t = new Template('{{ my_doodad.the_value }}');
        $this->assertEquals($t->render($c), '');

        /*
        Double-check that the object was really never called during the
        template rendering.
        */
        $this->assertEquals($my_doodad->num_calls, 0);
    }

}
