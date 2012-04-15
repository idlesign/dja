<?php

require_once 'dja_bootstrap.php';


class NodeListTest extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function testFor() {
        $source = '{% for i in 1 %}{{ a }}{% endfor %}';
        $template = DjaLoader::getTemplateFromString($source);
        $vars = $template->nodelist->getNodesByType('VariableNode');
        $this->assertEquals(count($vars), 1);
    }

    public function testIf() {
        $source = '{% if x %}{{ a }}{% endif %}';
        $template = DjaLoader::getTemplateFromString($source);
        $vars = $template->nodelist->getNodesByType('VariableNode');
        $this->assertEquals(count($vars), 1);
    }

    public function testIfequal() {
        $source = '{% ifequal x y %}{{ a }}{% endifequal %}';
        $template = DjaLoader::getTemplateFromString($source);
        $vars = $template->nodelist->getNodesByType('VariableNode');
        $this->assertEquals(count($vars), 1);
    }

    public function testIfchanged() {
        $source = '{% ifchanged x %}{{ a }}{% endifchanged %}';
        $template = DjaLoader::getTemplateFromString($source);
        $vars = $template->nodelist->getNodesByType('VariableNode');
        $this->assertEquals(count($vars), 1);
    }

}

/**
 * Checks whether index of error is calculated correctly in template debugger in for loops.
 * Refs ticket #5831
 */
class ErrorIndexTest extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function testCorrectExceptionIndex() {
        $debug_old_ = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);
        $tests = array(
            array('{% load bad_tag %}{% for i in range %}{% badsimpletag %}{% endfor %}', array(38, 56)),
            array('{% load bad_tag %}{% for i in range %}{% for j in range %}{% badsimpletag %}{% endfor %}{% endfor %}', array(58, 76)),
            array('{% load bad_tag %}{% for i in range %}{% badsimpletag %}{% for j in range %}Hello{% endfor %}{% endfor %}', array(38, 56)),
            array('{% load bad_tag %}{% for i in range %}{% for j in five %}{% badsimpletag %}{% endfor %}{% endfor %}', array(38, 57)),
            array('{% load bad_tag %}{% for j in five %}{% badsimpletag %}{% endfor %}', array(18, 37)),
        );
        // {% for j in five %}
        // {% badsimpletag %}
        $context = new Context(array('range'=>array(1, 2, 3, 4, 5), 'five'=>5));

        foreach ($tests as $item) {
            list($source, $expected_error_source_index) = $item;
            $template = DjaLoader::getTemplateFromString($source);
            try {
                $template->render($context);
            } catch (RuntimeError $e) {  // TODO except (RuntimeError, TypeError), e:
                $error_source_index = $e->django_template_source[1];
                $this->assertEquals($expected_error_source_index, $error_source_index);
            }

        }
        Dja::setSetting('TEMPLATE_DEBUG', $debug_old_);
    }

}
