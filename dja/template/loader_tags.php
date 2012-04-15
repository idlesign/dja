<?php


define('BLOCK_CONTEXT_KEY', 'block_context');


class BlockContext {

    public function __construct() {
        // Dictionary of FIFO queues.
        $this->blocks = array();
    }

    public function addBlocks($blocks) {
        foreach ($blocks as $name => $block) {
            if (isset($this->blocks[$name])) {
                py_arr_insert($this->blocks[$name], 0, $block);
            } else {
                $this->blocks[$name] = array($block);
            }
        }
    }

    public function pop($name) {
        try {
            return py_arr_pop($this->blocks[$name]);
        } catch (KeyError $e) { // IndexError, KeyError
            return null;
        }
    }

    public function push($name, $block) {
        $this->blocks[$name][] = $block;
    }

    public function getBlock($name) {
        try {
            return py_arr_get($this->blocks[$name], -1);
        } catch (KeyError $e) { // IndexError, KeyError
            return null;
        }
    }
}


class BlockNode extends Node {

    /**
     * @param string $name
     * @param NodeList $nodelist
     * @param null $parent
     */
    public function __construct($name, $nodelist, $parent = null) {
        $this->name = $name;
        $this->nodelist = $nodelist;
        $this->parent = $parent;
    }

    public function __toString() {
        return '<Block Node: ' . $this->name . '. Contents: ' . $this->nodelist . '>';
    }

    /**
     * @param Context $context
     *
     * @return mixed
     */
    public function render($context) {
        /** @var $block_context BlockContext */
        $block_context = null;
        if (isset($context->render_context[BLOCK_CONTEXT_KEY])) {
            $block_context = $context->render_context[BLOCK_CONTEXT_KEY];
        }
        $context->push();
        if ($block_context === null) {
            $context['block'] = $this;
            $result = $this->nodelist->render($context);
        } else {
            $push = $block = $block_context->pop($this->name);
            if ($block === null) {
                $block = $this;
            }
            // Create new block so we can store context without thread-safety issues.
            $block = new BlockNode($block->name, $block->nodelist);
            $block->context = $context;
            $context['block'] = $block;
            $result = $block->nodelist->render($context);
            if ($push !== null) {
                $block_context->push($this->name, $push);
            }
        }
        $context->pop();
        return $result;
    }

    public function super() {
        $render_context = $this->context->render_context;
        if (isset($render_context[BLOCK_CONTEXT_KEY]) && $render_context[BLOCK_CONTEXT_KEY]->getBlock($this->name) !== null) {
            return mark_safe($this->render($this->context));
        }
        return '';
    }
}


class ExtendsNode extends Node {

    public $must_be_first = True;

    /**
     * @param NodeList $nodelist
     * @param string|null $parent_name
     * @param FilterExpression|null $parent_name_expr
     * @param array|null $template_dirs
     */
    public function __construct($nodelist, $parent_name, $parent_name_expr, $template_dirs = null) {
        $this->nodelist = $nodelist;
        $this->parent_name = $parent_name;
        $this->parent_name_expr = $parent_name_expr;
        $this->template_dirs = $template_dirs;
        $blocks_ = array();
        foreach ($nodelist->getNodesByType('BlockNode') as $n) {
            $blocks_[$n->name] = $n;
        }
        $this->blocks = $blocks_;
    }

    public function __toString() {
        if ($this->parent_name_expr) {
            return '<ExtendsNode: extends ' . $this->parent_name_expr->token . '>';
        }
        return '<ExtendsNode: extends ' . $this->parent_name . '>';
    }

    /**
     * @param Context $context
     *
     * @return Template
     * @throws TemplateSyntaxError
     */
    public function getParent($context) {
        if ($this->parent_name_expr) {
            $parent = $this->parent_name_expr->resolve($context);
        } else {
            $parent = $this->parent_name;
        }
        if (!$parent) {
            $error_msg = 'Invalid template name in \'extends\' tag: ' . $parent . '.';
            if ($this->parent_name_expr) {
                $error_msg .= ' Got this from the \'' . $this->parent_name_expr->token . '\' variable.';
            }
            throw new TemplateSyntaxError($error_msg);
        }
        if (py_hasattr($parent, 'render')) {
            return $parent; // parent is a Template object
        }
        return DjaLoader::getTemplate($parent);
    }

    /**
     * @param Context $context
     *
     * @return mixed
     */
    public function render($context) {
        $compiled_parent = $this->getParent($context);

        if (!isset($context->render_context[BLOCK_CONTEXT_KEY])) {
            $context->render_context[BLOCK_CONTEXT_KEY] = new BlockContext();
        }
        /** @var $block_context BlockContext */
        $block_context = $context->render_context[BLOCK_CONTEXT_KEY];

        // Add the block nodes from this node to the block context
        $block_context->addBlocks($this->blocks);

        /*
         * If this block's parent doesn't have an extends node it is the root,
         * and its block nodes also need to be added to the block context.
         */
        foreach ($compiled_parent->nodelist as $node) {
            // The ExtendsNode has to be the first non-text node.
            if (!$node instanceof TextNode) {
                if (!$node instanceof ExtendsNode) {
                    $blocks = array();
                    foreach ($compiled_parent->nodelist->getNodesByType('BlockNode') as $n) {
                        $blocks[$n->name] = $n;
                    }
                    $block_context->addBlocks($blocks);
                }
                break;
            }
        }
        // Call Template._render explicitly so the parser context stays the same.
        return $compiled_parent->render_($context);
    }
}


class BaseIncludeNode extends Node {

    private $_extra_context = array();
    private $_isolated_context = False;

    public function setExtraContext($context) {
        $this->_extra_context = $context;
    }

    public function setIsolatedContext($context) {
        $this->_isolated_context = $context;
    }

    /**
     * @param Template $template
     * @param Context $context
     *
     * @return mixed
     */
    public function renderTemplate($template, $context) {
        $values = array();
        /** @var $var FilterExpression */
        foreach ($this->_extra_context as $name => $var) {
            $values[$name] = $var->resolve($context);
        }

        if ($this->_isolated_context) {
            return $template->render($context->new_($values));
        }
        $context->update($values);
        $output = $template->render($context);
        $context->pop();
        return $output;
    }
}


class ConstantIncludeNode extends BaseIncludeNode {

    public function __construct($template_path, $extra_context, $isolated_context) {
        $this->setExtraContext($extra_context);
        $this->setIsolatedContext($isolated_context);

        try {
            $t = DjaLoader::getTemplate($template_path);
            $this->template = $t;
        } catch (Exception $e) {
            if (Dja::getSetting('TEMPLATE_DEBUG')) {
                throw $e;
            }
            $this->template = null;
        }
    }

    public function render($context) {
        if (!$this->template) {
            return '';
        }
        return $this->renderTemplate($this->template, $context);
    }
}


class IncludeNode extends BaseIncludeNode {

    /**
     * @param FilterExpression $template_name
     * @param array $extra_context
     * @param bool $isolated_context
     */
    public function __construct($template_name, $extra_context, $isolated_context) {
        $this->template_name = $template_name;
        $this->setExtraContext($extra_context);
        $this->setIsolatedContext($isolated_context);
    }

    public function render($context) {
        try {
            $template_name = $this->template_name->resolve($context);
            $template = DjaLoader::getTemplate($template_name);
            return $this->renderTemplate($template, $context);
        } catch (Exception $e) {
            if (Dja::getSetting('TEMPLATE_DEBUG')) {
                throw $e;
            }
            return '';
        }
    }
}


$lib = new Library();


$lib->tag('block', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = py_str_split($token->contents);
    if (count($bits) != 2) {
        throw new TemplateSyntaxError('\'block\' tag takes only one argument');
    }
    $block_name = $bits[1];
    // Keep track of the names of BlockNodes found in this template, so we can check for duplication.
    if (property_exists($parser, '__loaded_blocks')) {
        if (isset($parser->__loaded_blocks[$block_name])) {
            throw new TemplateSyntaxError('\'block\' tag with name \'' . $block_name . '\' appears more than once');
        }
        $parser->__loaded_blocks[] = $block_name;
    } else { // parser.__loaded_blocks isn't a list yet
        $parser->__loaded_blocks = array($block_name);
    }

    $nodelist = $parser->parse(array('endblock'));

    // This check is kept for backwards-compatibility. See #3100.
    $endblock = $parser->nextToken();
    $acceptable_endblocks = array('endblock', 'endblock ' . $block_name);
    if (!in_array($endblock->contents, $acceptable_endblocks)) {
        $parser->invalidBlockTag($endblock, 'endblock', $acceptable_endblocks);
    }
    return new BlockNode($block_name, $nodelist);
});


$lib->tag('extends', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = $token->splitContents();
    if (count($bits) != 2) {
        throw new TemplateSyntaxError('\'extends\' takes one argument');
    }
    $parent_name = $parent_name_expr = null;

    if (in_array($bits[1][0], array('"', "'")) && (py_arr_get($bits[1], -1) == $bits[1][0])) {
        $parent_name = py_slice($bits[1], 1, -1);
    } else {
        $parent_name_expr = $parser->compileFilter($bits[1]);
    }
    /** @var $nodelist NodeList */
    $nodelist = $parser->parse();
    if ($nodelist->getNodesByType('ExtendsNode')) {
        throw new TemplateSyntaxError('\'extends\' cannot appear more than once in the same template');
    }
    return new ExtendsNode($nodelist, $parent_name, $parent_name_expr);
});


$lib->tag('include', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = $token->splitContents();

    if (count($bits) < 2) {
        throw new TemplateSyntaxError('\'include\' tag takes at least one argument: the name of the template to be included.');
    }

    $options = array();
    $remaining_bits = py_slice($bits, 2);

    while ($remaining_bits) {
        $option = py_arr_pop($remaining_bits, 0);
        if (isset($options[$option])) {
            throw new TemplateSyntaxError('The ' . $option . ' option was specified more than once.');
        }
        if ($option == 'with') {
            $value = DjaBase::tokenKwargs($remaining_bits, $parser, False);
            if (!$value) {
                throw new TemplateSyntaxError('"with" in \'include\' tag needs at least one keyword argument.');
            }
        } elseif ($option == 'only') {
            $value = True;
        } else {
            throw new TemplateSyntaxError('Unknown argument for \'include\' tag: ' . $option . '.');
        }

        $options[$option] = $value;
    }
    $isolated_context = py_arr_get($options, 'only', False);
    $namemap = py_arr_get($options, 'with', array());
    $path = $bits[1];

    if (in_array($path[0], array('"', "'")) && py_arr_get($path, -1) == $path[0]) {
        return new ConstantIncludeNode(py_slice($path, 1, -1), $namemap, $isolated_context);
    }
    return new IncludeNode($parser->compileFilter($bits[1]), $namemap, $isolated_context);
});


return $lib;