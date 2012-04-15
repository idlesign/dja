<?php

require_once 'dja_bootstrap.php';


class ParserTest extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    /**
     * Tests for TokenParser behavior in the face of quoted strings with spaces.
     */
    public function testTokenParsing() {
        $p = new TokenParser('tag thevar|filter sometag');
        $this->assertEquals($p->tagname, 'tag');
        $this->assertEquals($p->value(), 'thevar|filter');
        $this->assertTrue($p->more());
        $this->assertEquals($p->tag(), 'sometag');
        $this->assertFalse($p->more());

        $p = new TokenParser('tag "a value"|filter sometag');
        $this->assertEquals($p->tagname, 'tag');
        $this->assertEquals($p->value(), '"a value"|filter');
        $this->assertTrue($p->more());
        $this->assertEquals($p->tag(), 'sometag');
        $this->assertFalse($p->more());

        $p = new TokenParser("tag 'a value'|filter sometag");
        $this->assertEquals($p->tagname, 'tag');
        $this->assertEquals($p->value(), "'a value'|filter");
        $this->assertTrue($p->more());
        $this->assertEquals($p->tag(), 'sometag');
        $this->assertFalse($p->more());
    }


    public function testVariableParsing() {
        $c = array('article'=>array('section'=>'News'));

        $v = new Variable('article.section');
        $this->assertEquals('News', $v->resolve($c));
        $v = new Variable('"News"');
        $this->assertEquals('News', $v->resolve($c));
        $v = new Variable("'News'");
        $this->assertEquals('News', $v->resolve($c));

        // Translated strings are handled correctly.
        $v = new Variable('_(article.section)');
        $this->assertEquals('News', $v->resolve($c));
        $v = new Variable('_("Good News")');
        $this->assertEquals('Good News', $v->resolve($c));
        $v = new Variable("_('Better News')");
        $this->assertEquals('Better News', $v->resolve($c));


        // Escaped quotes work correctly as well.
        $v = new Variable('"Some \"Good\" News"');
        $this->assertEquals('Some "Good" News', $v->resolve($c));
        $v = new Variable("'Some 'Better' News'");
        $this->assertEquals("Some 'Better' News", $v->resolve($c));

        // Variables should reject access of attributes beginning with underscores.
        $this->setExpectedException('TemplateSyntaxError');
        new Variable('article._hidden');
    }

    public function testFilterParsing() {

        $c = array('article'=>array('section'=>'News'));
        $p = new Parser('');

        $fe = new FilterExpression('article.section', $p);
        $this->assertEquals('News', $fe->resolve($c));

        $fe = new FilterExpression('article.section|upper', $p);
        $this->assertEquals('NEWS', $fe->resolve($c));

        $fe = new FilterExpression('"News"', $p);
        $this->assertEquals('News', $fe->resolve($c));

        $fe = new FilterExpression("'News'", $p);
        $this->assertEquals('News', $fe->resolve($c));

        $fe = new FilterExpression('"Some \"Good\" News"', $p);
        $this->assertEquals('Some "Good" News', $fe->resolve($c));

        $fe = new FilterExpression("'Some \"Good\" News'", $p);
        $this->assertEquals('Some "Good" News', $fe->resolve($c));

        $fe = new FilterExpression('"Some \"Good\" News"', $p);
        $this->assertEquals(array(), $fe->filters);
        $this->assertEquals('Some "Good" News', $fe->var);

        $fe = new FilterExpression("'Some \'Bad\' News'", $p);
        $this->assertEquals("Some 'Bad' News", $fe->resolve($c));

        // Filtered variables should reject access of attributes beginning with underscores.
        $this->setExpectedException('TemplateSyntaxError');
        new FilterExpression('article._hidden|upper', $p);
    }


}
