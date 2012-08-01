<?php


/**
 * This node class can be used as a template for custom naive if-like tags.
 *
 * This allows {% if_name_is_mike name %}Hi, Mike!{% else %}Where is Mike?{% endif_name_is_mike %}
 * and alike constructions in templates.
 *
 * It exposes registerAsTag method to quick tag registration within
 * a tag library.
 *
 * Example:
 *
 *     $lib = new Library();
 *
 *     NaiveIfTemplateNode::registerAsTag($lib, 'if_name_is_mike',
 *         function ($value) { return $value=='Mike'; }  // This closure will test value on rendering.
 *     );
 *
 *     return $lib;
 *
 */
class NaiveIfTemplateNode extends Node {

    public $child_nodelists = array('nodelist_true', 'nodelist_false');
    private $_values;
    /**
     * @var Closure
     */
    private $_condition;

    /**
     * @param NodeList $nodelist_true
     * @param NodeList $nodelist_false
     * @param Closure $condition
     * @param array $values
     */
    public function __construct($nodelist_true, $nodelist_false, $values, $condition) {
        $this->nodelist_true = $nodelist_true;
        $this->nodelist_false = $nodelist_false;
        $this->_values = $values;
        $this->_condition = $condition;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        $condition = $this->_condition;
        if ($condition($this->_values)) {
            return $this->nodelist_true->render($context);
        } elseif ($this->nodelist_false) {
            return $this->nodelist_false->render($context);
        }
        return '';
    }

    /**
     * Registers tag within a library handled
     * by this naive if template node.
     *
     * @static
     * @param Library $lib
     * @param string $tag_name
     * @param Closure $condition
     */
    public static function registerAsTag($lib, $tag_name, $condition) {
        $lib->tag($tag_name,
            function($parser, $token) use ($tag_name, $condition) {
                /**
                 * @var Parser $parser
                 * @var Token $token
                 */
                $bits = py_str_split($token->contents);
                $nodelist_true = $parser->parse(array('else', 'end' . $tag_name));
                $token = $parser->nextToken();
                if ($token->contents == 'else') {
                    $nodelist_false = $parser->parse(array('end' . $tag_name));
                    $parser->deleteFirstToken();
                } else {
                    $nodelist_false = new NodeList();
                }
                $values = array();
                foreach (py_slice($bits, 1) as $bit) {
                    $values[] = $parser->compileFilter($bit);
                }
                return new self($nodelist_true, $nodelist_false, $values, $condition);
        });
    }

}
