<?php

$lib = new Library();


/**
 * Implements the actions of the autoescape tag.
 */
class AutoEscapeControlNode extends Node {

    /**
     * @param bool $setting
     * @param NodeList $nodelist
     */
    public function __construct($setting, $nodelist) {
        $this->setting = $setting;
        $this->nodelist = $nodelist;
    }

    public function render($context) {
        $old_setting = $context->autoescape;
        $context->autoescape = $this->setting;
        $output = $this->nodelist->render($context);
        $context->autoescape = $old_setting;
        if ($this->setting) {
            return mark_safe($output);
        } else {
            return $output;
        }
    }
}


class CommentNode extends Node {

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        return '';
    }
}


// TODO CsrfTokenNode


class CycleNode extends Node {

    /**
     * @param array $cyclevars
     * @param null|string $variable_name
     * @param bool $silent
     */
    public function __construct($cyclevars, $variable_name = null, $silent = False) {
        $this->cyclevars = $cyclevars;
        $this->variable_name = $variable_name;
        $this->silent = $silent;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render(&$context) {
        $obj_hash = spl_object_hash($this);
        if (!isset($context->render_context[$obj_hash])) {
            // First time the node is rendered in template
            $context->render_context[$obj_hash] = new PyItertoolsCycle($this->cyclevars);
        }
        $cycle_iter = $context->render_context[$obj_hash];
        $value = $cycle_iter->next()->resolve($context);
        if ($this->variable_name) {
            $context[$this->variable_name] = $value;
        }
        if ($this->silent) {
            return '';
        }
        return $value;
    }
}


class DebugNode extends Node {

    /**
     * @param Context $context
     * @return mixed
     */
    public function render($context) {
        $output = array(
            'context'=>$context->dicts,
            'GET'=>$_GET,
            'POST'=>$_POST,
            'SERVER'=>$_SERVER,
            'SESSION'=>$_SESSION,
            'COOKIE'=>$_COOKIE,
        );
        return print_r($output, True);
    }
}


class FilterNode extends Node {

    /**
     * @param FilterExpression $filter_expr
     * @param NodeList $nodelist
     */
    public function __construct($filter_expr, $nodelist) {
        $this->filter_expr = $filter_expr;
        $this->nodelist = $nodelist;
    }

    /**
     * @param Context $context
     * @return mixed
     */
    public function render($context) {
        $output = $this->nodelist->render($context);
        // Apply filters.
        $context->update(array('var' => $output));
        $filtered = $this->filter_expr->resolve($context);
        $context->pop();
        return $filtered;
    }
}


class FirstOfNode extends Node {

    /**
     * @param array $vars
     */
    public function __construct($vars) {
        $this->vars = $vars;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        foreach ($this->vars as $var) {
            /** @var $var Variable  */
            $value = $var->resolve($context, True);
            if ($value) {
                return $value;
            }
        }
        return '';
    }
}


class ForNode extends Node {

    public $child_nodelists = array('nodelist_loop', 'nodelist_empty');

    /**
     * @param array $loopvars
     * @param FilterExpression $sequence
     * @param bool $is_reversed
     * @param NodeList $nodelist_loop
     * @param null|NodeList $nodelist_empty
     */
    public function __construct($loopvars, $sequence, $is_reversed, $nodelist_loop, $nodelist_empty = null) {
        $this->loopvars = $loopvars;
        $this->sequence = $sequence;
        $this->is_reversed = $is_reversed;
        $this->nodelist_loop = $nodelist_loop;
        if ($nodelist_empty == null) {
            $this->nodelist_empty = new NodeList();
        } else {
            $this->nodelist_empty = $nodelist_empty;
        }
    }

    public function __toString() {
        $reversed_text = ($this->is_reversed && ' reversed' || '');
        return '<For Node: for ' . join(', ', $this->loopvars) . ' in ' . $this->sequence . ', tail_len: ' . count($this->nodelist_loop) . $reversed_text . '>';
    }

    // TODO implement __iter__ if required

    /**
     * @param Context $context
     * @return SafeString|string
     * @throws Exception|TypeError|VariableDoesNotExist
     * @throws RuntimeError
     */
    public function render($context) {
        if (isset($context['forloop'])) {
            $parentloop = $context['forloop'];
        } else {
            $parentloop = array();
        }
        $context->push();

        try {
            $values = $this->sequence->resolve($context, True);
        } catch (VariableDoesNotExist $e) {
            $values = array();
        }

        if ($values === null) {
            $values = array();
        } elseif (is_string($values)) { // We convert a string to array to make it iterable.
            $values = str_split($values);  // TODO Check unicode handling.
        }

        if (!is_array($values)) {
            throw new RuntimeError('Noniterable "' . $values .  '" is passed to for loop.');
        }

        $len_values = count($values);
        if ($len_values < 1) {
            $context->pop();
            return $this->nodelist_empty->render($context);
        }
        $nodelist = new NodeList();
        if ($this->is_reversed) {
            $values_ = $values;
            krsort($values_);
            $values = $values_;
            unset($values_);
        }
        $unpack = (count($this->loopvars) > 1);
        // Create a forloop value in the context.  We'll update counters on each iteration just below.
        $loop_dict = $context['forloop'] = array('parentloop' => $parentloop);

        foreach ($values as $i => $item) {
            // Shortcuts for current loop iteration number.
            $loop_dict['counter0'] = $i;
            $loop_dict['counter'] = $i + 1;
            // Reverse counter iteration numbers.
            $loop_dict['revcounter'] = ($len_values - $i);
            $loop_dict['revcounter0'] = ($len_values - $i - 1);
            // Boolean values designating first and last times through loop.
            $loop_dict['first'] = ($i == 0);
            $loop_dict['last'] = ($i == ($len_values - 1));

            $context['forloop'] = array_merge($context['forloop'], $loop_dict);

            $pop_context = False;
            if ($unpack) {
                // If there are multiple loop variables, unpack the item into them.
                $success_ = True;
                try {
                    $unpacked_vars = py_zip($this->loopvars, $item);
                } catch (TypeError $e) {
                    $success_ = False;
                }
                if ($success_) {
                    $pop_context = True;
                    $context->update($unpacked_vars);
                }
            } else {
                $context[$this->loopvars[0]] = $item;
            }

            // In TEMPLATE_DEBUG mode provide source of the node which actually raised the exception
            if (Dja::getSetting('TEMPLATE_DEBUG')) {
                foreach ($this->nodelist_loop as $node) {
                    /** @var $node Node */
                    try {
                        $nodelist[] = $node->render($context);
                    } catch (Exception $e) {
                        if (!py_hasattr($e, 'django_template_source')) {
                            $e->django_template_source = $node->source;
                        }
                        throw $e;
                    }
                }
            } else {
                foreach ($this->nodelist_loop as $node) {
                    $nodelist[] = $node->render($context);
                }
            }
            if ($pop_context) {
                /*
                 * The loop variables were pushed on to the context so pop them
                 * off again. This is necessary because the tag lets the length
                 * of loopvars differ to the length of each set of items and we
                 * don't want to leave any vars from the previous loop on the
                 * context.
                 */
                $context->pop();
            }
        }
        $context->pop();
        return $nodelist->render($context);
    }
}


class IfChangedNode extends Node {

    public $child_nodelists = array('nodelist_true', 'nodelist_false');
    private $_last_seen;
    private $_varlist;
    private $_id;

    /**
     * @param NodeList $nodelist_true
     * @param NodeList $nodelist_false
     * @param array $varlist
     */
    public function __construct($nodelist_true, $nodelist_false, $varlist) {
        $this->nodelist_true = $nodelist_true;
        $this->nodelist_false = $nodelist_false;
        $this->_last_seen = null;
        $this->_varlist = $varlist;
        $this->_id = spl_object_hash($this);
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        if (isset($context['forloop']) && !isset($context['forloop'][$this->_id])) {
            $this->_last_seen = null;
            $key_ = array('forloop', $this->_id);
            $context[$key_] = 1; // Magically using an array as a key for another array,
        }

        try {
            if ($this->_varlist) {
                // Consider multiple parameters.  This automatically behaves like an OR evaluation of the multiple variables.
                $compare_to = array();
                foreach ($this->_varlist as $var) {
                    /** @var $var FilterExpression */
                    $compare_to[] = $var->resolve($context, True);
                }
            } else {
                $compare_to = $this->nodelist_true->render($context);
            }
        } catch (VariableDoesNotExist $e) {
            $compare_to = null;
        }

        if ($compare_to != $this->_last_seen) {
            $this->_last_seen = $compare_to;
            $content = $this->nodelist_true->render($context);
            return $content;
        } elseif ($this->nodelist_false) {
            return $this->nodelist_false->render($context);
        }
        return '';
    }

}


class IfEqualNode extends Node {

    public $child_nodelists = array('nodelist_true', 'nodelist_false');

    /**
     * @param FilterExpression $var1
     * @param FilterExpression $var2
     * @param NodeList $nodelist_true
     * @param NodeList $nodelist_false
     * @param bool $negate
     */
    public function __construct($var1, $var2, $nodelist_true, $nodelist_false, $negate) {
        $this->var1 = $var1;
        $this->var2 = $var2;
        $this->nodelist_true = $nodelist_true;
        $this->nodelist_false = $nodelist_false;
        $this->negate = $negate;
    }

    public function __toString() {
        return '<IfEqualNode>';
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        $val1 = $this->var1->resolve($context, True);
        $val2 = $this->var2->resolve($context, True);
        if (($this->negate && $val1 != $val2) || (!$this->negate && $val1 == $val2)) {
            return $this->nodelist_true->render($context);
        }
        return $this->nodelist_false->render($context);
    }
}


class IfNode extends Node {

    /**
     * @param array $conditions_nodelists
     */
    public function __construct($conditions_nodelists) {
        $this->conditions_nodelists = $conditions_nodelists;
    }

    public function __toString() {
        return '<IfNode>';
    }

    // TODO implement __iter__ if required

    /**
     * @return NodeList
     */
    public function nodelist() {
        $nodes = array();
        foreach ($this->conditions_nodelists as $nodelist) {
            $nodelist = $nodelist[1];
            foreach ($nodelist as $node) {
                $nodes[] = $node;
            }
        }
        return new NodeList($nodes);
    }

    public function __get($name) {
        if ($name == 'nodelist') {
            return $this->nodelist();
        }
        throw new AttributeError();
    }

    public function __isset($name) {
        if ($name == 'nodelist') {
            return True;
        } else {
            return isset($this->$name);
        }
    }

    public function render($context) {
        foreach ($this->conditions_nodelists as $nl_) {
            list ($condition, $nodelist) = $nl_;
            /**
             * @var $nodelist NodeList
             * @var $condition
             */
            if ($condition !== null) { // if / elseif clause
                try {
                    $match = $condition->eval_($context);
                } catch (VariableDoesNotExist $e) {
                    $match = null;
                }
            } else { // else clause
                $match = True;
            }

            if ($match) {
                return $nodelist->render($context);
            }
        }
        return '';
    }
}


class RegroupNode extends Node {

    /**
     * @param FilterExpression $target
     * @param FilterExpression $expression
     * @param string $var_name
     */
    public function __construct($target, $expression, $var_name) {
        $this->target = $target;
        $this->expression = $expression;
        $this->var_name = $var_name;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        $obj_list = $this->target->resolve($context, True);
        if ($obj_list === null) {
            // target variable wasn't found in context; fail silently.
            $context[$this->var_name] = array();
            return '';
        }
        // List of dictionaries in the format: {'grouper': 'key', 'list': [list of contents]}.
        $v_pre_ = array();
        $v_out_ = array();
        foreach ($obj_list as $item_) {
            $k_ = $this->expression->resolve($item_);
            if (!isset($v_pre_[$k_])) {
                $v_pre_[$k_] = array();
            }
            $v_pre_[$k_][] = $item_;
        }
        foreach ($v_pre_ as $key => $val) {
            if (!is_array($val)) {
                $val = array($val);
            }
            $v_out_[] = array('grouper' => $key, 'list' => $val);
        }
        unset($v_pre_);
        $context[$this->var_name] = $v_out_;
        return '';
    }
}


// TODO include_is_allowed


// TODO SsiNode


class LoadNode extends Node {

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        return '';
    }

}


class NowNode extends Node {

    /**
     * @param string $format_string
     */
    public function __construct($format_string) {
        $this->format_string = $format_string;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        // TODO Maybe get rid of USE_TZ?
        $use_tz = Dja::getSetting('USE_TZ');

        if (!$use_tz) {
            $old_tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
        }
        $d_ = dja_date(time(), $this->format_string);
        if (!$use_tz) {
            date_default_timezone_set($old_tz);
        }
        return $d_;
    }
}


class SpacelessNode extends Node {

    /**
     * @param NodeList $nodelist
     */
    public function __construct($nodelist) {
        $this->nodelist = $nodelist;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        return strip_spaces_between_tags(trim($this->nodelist->render($context)));
    }
}


class TemplateTagNode extends Node {

    public static $mapping = array(
        'openblock' => DjaBase::BLOCK_TAG_START,
        'closeblock' => DjaBase::BLOCK_TAG_END,
        'openvariable' => DjaBase::VARIABLE_TAG_START,
        'closevariable' => DjaBase::VARIABLE_TAG_END,
        'openbrace' => DjaBase::SINGLE_BRACE_START,
        'closebrace' => DjaBase::SINGLE_BRACE_END,
        'opencomment' => DjaBase::COMMENT_TAG_START,
        'closecomment' => DjaBase::COMMENT_TAG_END,
    );

    /**
     * @param string $tagtype
     */
    public function __construct($tagtype) {
        $this->tagtype = $tagtype;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        return py_arr_get(self::$mapping, $this->tagtype, '');
    }
}


class URLNode extends Node {

    /**
     * @param string $view_name
     * @param array $args
     * @param array $kwargs
     * @param bool $asvar
     * @param bool $legacy_view_name
     */
    public function __construct($view_name, $args, $kwargs, $asvar, $legacy_view_name=True) {
        $this->view_name = $view_name;
        $this->legacy_view_name = $legacy_view_name;
        $this->args = $args;
        $this->kwargs = $kwargs;
        $this->asvar = $asvar;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     * @throws UrlNoReverseMatch
     */
    public function render($context) {
        $args = array();
        foreach ($this->args as $arg) {
            $args[] = $arg->resolve($context);
        }

        $kwargs = array();
        foreach ($this->kwargs as $k=>$v) {
            $kwargs[$k] = $v->resolve($context);  // TODO check smart_str(k, 'ascii')
        }

        $view_name = $this->view_name;
        if (!$this->legacy_view_name) {
            $view_name = $view_name->resolve($context);
        }

        /*
         * Try to look up the URL twice: once given the view name, and again
         * relative to what we guess is the "main" app. If they both fail,
         * re-raise the NoReverseMatch unless we're using the
         * {% url ... as var %} construct in which cause return nothing.
         */
        $url = '';
        try {
            $url_manager = Dja::getUrlManager();
            $url = $url_manager->reverse($view_name, null, $args, $kwargs, null, $context->current_app);
        } catch (UrlNoReverseMatch $e) {
            if ($this->asvar === null) {
                throw $e;
            }
        }

        if ($this->asvar) {
            $context[$this->asvar] = $url;
            return '';
        } else {
            return $url;
        }
    }
}


class WidthRatioNode extends Node {

    /**
     * @param FilterExpression $val_expr
     * @param FilterExpression $max_expr
     * @param FilterExpression $max_width
     */
    public function __construct($val_expr, $max_expr, $max_width) {
        $this->val_expr = $val_expr;
        $this->max_expr = $max_expr;
        $this->max_width = $max_width;
    }

    /**
     * @param Context $context
     * @return SafeString|string
     * @throws TemplateSyntaxError
     */
    public function render($context) {
        try {
            $value = $this->val_expr->resolve($context);
            $max_value = $this->max_expr->resolve($context);
            $max_width = $this->max_width->resolve($context);
        } catch (VariableDoesNotExist $e) {
            return '';
        }

        if (!is_numeric($max_width)) {
            throw new TemplateSyntaxError('widthratio final argument must be an number');
        }

        $value = (float)$value;
        $max_value = (float)$max_value;
        $max_width = (int)$max_width;
        if ($max_value == 0) {
            return '0';
        }
        $ratio = ($value / $max_value) * $max_width;
        return (string)((int)(round($ratio)));
    }
}


class WithNode extends Node {

    /**
     * @param null $var
     * @param null $name
     * @param NodeList $nodelist
     * @param null|array $extra_context
     */
    public function __construct($var, $name, $nodelist, $extra_context = null) {
        $this->nodelist = $nodelist;
        // var and name are legacy attributes, being left in case they are used by third-party subclasses of this Node.
        if ($extra_context) {
            $this->extra_context = $extra_context;
        } else {
            $this->extra_context = array();
        }
        if ($name) {
            $this->extra_context[$name] = $var;
        }
    }

    public function __toString() {
        return '<WithNode>';
    }

    /**
     * @param Context $context
     * @return SafeString|string
     */
    public function render($context) {
        $values = array();
        foreach ($this->extra_context as $key => $val) {
            /** @var $val FilterExpression */
            $values[$key] = $val->resolve($context);
        }
        $context->update($values);
        $output = $this->nodelist->render($context);
        $context->pop();
        return $output;
    }
}


$lib->tag('autoescape', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $args = py_str_split($token->contents);
    if (count($args) != 2) {
        throw new TemplateSyntaxError('\'autoescape\' tag requires exactly one argument.');
    }
    $arg = $args[1];
    if (!in_array($arg, array('on', 'off'))) {
        throw new TemplateSyntaxError('\'autoescape\' argument should be \'on\' or \'off\'');
    }
    $nodelist = $parser->parse(array('endautoescape'));
    $parser->deleteFirstToken();
    return new AutoEscapeControlNode(($arg == 'on'), $nodelist);
});


$lib->tag('comment', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $parser->skipPast('endcomment');
    return new CommentNode();
});


$lib->tag('cycle', function(&$parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */

    /*
     * Note: This returns the exact same node on each {% cycle name %} call;
     * that is, the node object returned from {% cycle a b c as name %} and the
     * one returned from {% cycle name %} are the exact same object. This
     * shouldn't cause problems (heh), but if it does, now you know.
     *
     * Ugly hack warning: This stuffs the named template dict into parser so
     * that names are only unique within each template (as opposed to using
     * a global variable, which would make cycle names have to be unique across
     * *all* templates.
     */

    $args = $token->splitContents();

    if (count($args) < 2) {
        throw new TemplateSyntaxError('\'cycle\' tag requires at least two arguments');
    }

    if (strpos($args[1], ',') !== False) {
        // Backwards compatibility: {% cycle a,b %} or {% cycle a,b as foo %} case.
        $args_ = array();
        foreach (explode(',', $args[1]) as $arg) {
            $args_[] = '"' . $arg . '"';
        }
        array_splice($args, 1, 1, $args_);
        unset($args_);
    }

    if (count($args) == 2) {
        // {% cycle foo %} case.
        $name = $args[1];

        if (!py_hasattr($parser, '_named_cycle_nodes')) {
            throw new TemplateSyntaxError('No named cycles in template. \'' . $name . '\' is not defined');
        }
        if (!isset($parser->_named_cycle_nodes[$name])) {
            throw new TemplateSyntaxError('Named cycle \'' . $name . '\' does not exist');
        }
        return $parser->_named_cycle_nodes[$name];
    }

    $as_form = False;

    if (count($args) > 4) {
        // {% cycle ... as foo [silent] %} case.
        if (py_arr_get($args, -3) == 'as') {
            if (py_arr_get($args, -1) != 'silent') {
                throw new TemplateSyntaxError('Only \'silent\' flag is allowed after cycle\'s name, not \'' . py_arr_get($args, -1) . '\'.');
            }
            $as_form = True;
            $silent = True;
            $args = py_slice($args, null, -1);
        } elseif (py_arr_get($args, -2) == 'as') {
            $as_form = True;
            $silent = False;
        }
    }

    if ($as_form) {
        $name = py_arr_get($args, -1);
        $values = array();
        foreach (py_slice($args, 1, -2) as $arg) {
            $values[] = $parser->compileFilter($arg);
        }
        $node = new CycleNode($values, $name, $silent);
        if (!py_hasattr($parser, '_named_cycle_nodes')) {
            $parser->_named_cycle_nodes = array();
        }
        $parser->_named_cycle_nodes[$name] = $node;
    } else {
        $values = array();
        foreach (py_slice($args, 1) as $arg) {
            $values[] = $parser->compileFilter($arg);
        }
        $node = new CycleNode($values);
    }
    return $node;
});


// TODO csrf_token


$lib->tag('debug', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    return new DebugNode();
});


$lib->tag('filter', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $rest = trim(substr($token->contents, strpos($token->contents, ' ')));
    /** @var $filter_expr FilterExpression */
    $filter_expr = $parser->compileFilter('var|' . $rest);
    foreach ($filter_expr->filters as $d_) {
        $func_name = $d_[2];
        if (in_array($func_name, array('escape', 'safe'))) {
            throw new TemplateSyntaxError('"filter ' . $func_name . '" is not permitted.  Use the "autoescape" tag instead.');
        }
    }
    $nodelist = $parser->parse(array('endfilter'));
    $parser->deleteFirstToken();
    return new FilterNode($filter_expr, $nodelist);
});


$lib->tag('firstof', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = py_slice($token->splitContents(), 1);
    if (count($bits) < 1) {
        throw new TemplateSyntaxError('\'firstof\' statement requires at least one argument');
    }
    $vars_ = array();
    foreach ($bits as $bit) {
        $vars_[] = $parser->compileFilter($bit);
    }

    return new FirstOfNode($vars_);
});


$lib->tag('for', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */

    $bits = py_str_split( $token->contents);
    if (count($bits) < 4) {
        throw new TemplateSyntaxError('\'for\' statements should have at least four words: ' . $token->contents);
    }

    $is_reversed = (py_arr_get($bits, -1) == 'reversed');
    $in_index = ($is_reversed ? -3 : -2);

    if (py_arr_get($bits, $in_index) != 'in') {
        throw new TemplateSyntaxError('\'for\' statements should use the format \'for x in y\': ' . $token->contents);
    }

    $loopvars = preg_split('~ *, *~', join(' ', py_slice($bits, 1, $in_index)));
    foreach ($loopvars as $var) {
        if (!$var || strpos($var, ' ') !== False) {
            throw new TemplateSyntaxError('\'for\' tag received an invalid argument: ' . $token->contents);
        }
    }

    $sequence = $parser->compileFilter(py_arr_get($bits, $in_index + 1));
    $nodelist_loop = $parser->parse(array('empty', 'endfor'));
    $token = $parser->nextToken();
    if ($token->contents == 'empty') {
        $nodelist_empty = $parser->parse(array('endfor'));
        $parser->deleteFirstToken();
    } else {
        $nodelist_empty = null;
    }
    return new ForNode($loopvars, $sequence, $is_reversed, $nodelist_loop, $nodelist_empty);

});


/**
 * @param Parser $parser
 * @param Token $token
 * @param bool $negate
 * @return IfEqualNode
 * @throws TemplateSyntaxError
 */
function do_ifequal($parser, $token, $negate) {
    $bits = $token->splitContents();
    if (count($bits) != 3) {
        throw new TemplateSyntaxError($bits[0] . ' takes two arguments');
    }
    $end_tag = 'end' . $bits[0];
    $nodelist_true = $parser->parse(array('else', $end_tag));
    $token = $parser->nextToken();
    if ($token->contents == 'else') {
        $nodelist_false = $parser->parse(array($end_tag));
        $parser->deleteFirstToken();
    } else {
        $nodelist_false = new NodeList();
    }
    $val1 = $parser->compileFilter($bits[1]);
    $val2 = $parser->compileFilter($bits[2]);
    return new IfEqualNode($val1, $val2, $nodelist_true, $nodelist_false, $negate);
}


$lib->tag('ifequal', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    return do_ifequal($parser, $token, False);
});


$lib->tag('ifnotequal', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    return do_ifequal($parser, $token, True);
});


class TemplateLiteral extends Literal {

    /**
     * @param Variable|string $value
     * @param $text
     */
    public function __construct($value, $text) {
        $this->value = $value;
        $this->text = $text;  // for better error messages
    }

    public function display() {
        return $this->text;
    }

    /**
     * @param Context $context
     * @return mixed
     */
    public function eval_($context) {
        return $this->value->resolve($context, True);
    }
}


class TemplateIfParser extends IfParser {

    public $error_class = 'TemplateSyntaxError';

    /**
     * @param Parser $parser
     * @param array $tokens
     */
    public function __construct($parser, $tokens) {
        $this->template_parser = $parser;
        parent::__construct($tokens);
    }

    /**
     * @param Token $value
     * @return TemplateLiteral|Literal
     */
    public function createVar($value) {
        return new TemplateLiteral($this->template_parser->compileFilter($value), $value);
    }
}


$lib->tag('if', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    // {% if ... %}
    $bits = py_slice($token->splitContents(), 1);
    $condition = new TemplateIfParser($parser, $bits);
    $condition = $condition->parse();
    $nodelist = $parser->parse(array('elif', 'else', 'endif'));
    $conditions_nodelists = array(array($condition, $nodelist));
    $token = $parser->nextToken();

    // {% elif ... %} (repeatable)
    while (py_str_starts_with($token->contents, 'elif')) {
        $bits = py_slice($token->splitContents(), 1);
        $condition = new TemplateIfParser($parser, $bits);
        $condition = $condition->parse();
        $nodelist = $parser->parse(array('elif', 'else', 'endif'));
        $conditions_nodelists[] = array($condition, $nodelist);
        $token = $parser->nextToken();
    }

    // {% else %} (optional)
    if ($token->contents == 'else') {
        $nodelist = $parser->parse(array('endif'));
        $conditions_nodelists[] = array(null, $nodelist);
        $token = $parser->nextToken();
    }

    // {% endif %}
    assert($token->contents == 'endif');

    return new IfNode($conditions_nodelists);
});


$lib->tag('ifchanged', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */

    $bits = py_str_split( $token->contents);
    $nodelist_true = $parser->parse(array('else', 'endifchanged'));
    $token = $parser->nextToken();
    if ($token->contents == 'else') {
        $nodelist_false = $parser->parse(array('endifchanged'));
        $parser->deleteFirstToken();
    } else {
        $nodelist_false = new NodeList();
    }
    $values = array();
    foreach (py_slice($bits, 1) as $bit) {
        $values[] = $parser->compileFilter($bit);
    }
    return new IfChangedNode($nodelist_true, $nodelist_false, $values);
});


// TODO ssi


$lib->tag('load', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */

    $bits = py_str_split( $token->contents);

    if (count($bits) >= 4 && py_arr_get($bits, -2) == 'from') {

        $taglib = py_arr_get($bits, -1);
        try {
            $lib = DjaBase::getLibrary($taglib);
        } catch (InvalidTemplateLibrary $e) {
            throw new TemplateSyntaxError('\'' . $taglib . '\' is not a valid tag library: ' . $e);
        }

        $temp_lib = new Library();
        foreach (py_slice($bits, 1, -2) as $name) {
            if (isset($lib->tags[$name])) {
                $temp_lib->tags[$name] = $lib->tags[$name];
                // a name could be a tag *and* a filter, so check for both
                if (isset($lib->filters[$name])) {
                    $temp_lib->filters[$name] = $lib->filters[$name];
                }
            } elseif (isset($lib->filters[$name])) {
                $temp_lib->filters[$name] = $lib->filters[$name];
            } else {
                throw new TemplateSyntaxError('\'' . $name . '\' is not a valid tag or filter in tag library \'' . $taglib . '\'');
            }
        }
        $parser->addLibrary($temp_lib);
    } else {
        foreach (py_slice($bits, 1) as $taglib) {
            // add the library to the parser
            try {
                $lib = DjaBase::getLibrary($taglib);
                $parser->addLibrary($lib);
            } catch (InvalidTemplateLibrary $e) {
                throw new TemplateSyntaxError('\'' . $taglib . '\' is not a valid tag library: ' . $e);
            }
        }
    }
    return new LoadNode();
});


$lib->tag('now', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = $token->splitContents();
    if (count($bits) != 2) {
        throw new TemplateSyntaxError('\'now\' statement takes one argument');
    }
    $format_string = py_slice($bits[1], 1, -1);
    return new NowNode($format_string);
});


$lib->tag('regroup', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $firstbits = py_str_split($token->contents, 4);
    if (count($firstbits) != 4) {
        throw new TemplateSyntaxError('\'regroup\' tag takes five arguments');
    }
    $target = $parser->compileFilter($firstbits[1]);
    if ($firstbits[2] != 'by') {
        throw new TemplateSyntaxError('second argument to \'regroup\' tag must be \'by\'');
    }
    $lastbits_reversed = py_str_split(py_slice($firstbits[3], null, null, -1), 3);
    if (py_slice($lastbits_reversed[1], null, null, -1) != 'as') {
        throw new TemplateSyntaxError('next-to-last argument to \'regroup\' tag must be \'as\'');
    }
    $expression = $parser->compileFilter(py_slice($lastbits_reversed[2], null, null, -1));
    $var_name = py_slice($lastbits_reversed[0], null, null, -1);
    return new RegroupNode($target, $expression, $var_name);
});


$lib->tag('spaceless', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $nodelist = $parser->parse(array('endspaceless'));
    $parser->deleteFirstToken();
    return new SpacelessNode($nodelist);
});


$lib->tag('templatetag', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = py_str_split($token->contents);
    if (count($bits) != 2) {
        throw new TemplateSyntaxError('\'templatetag\' statement takes one argument');
    }
    $tag = $bits[1];
    if (!isset(TemplateTagNode::$mapping[$tag])) {
        throw new TemplateSyntaxError('Invalid templatetag argument: \'' . $tag . '\'. Must be one of: ' . array_keys(TemplateTagNode::$mapping));
    }
    return new TemplateTagNode($tag);
});


$lib->tag('url', function($parser, $token) {
   /**
    * @var Parser $parser
    * @var Token $token
    */
    $bits = $token->splitContents();
    if (count($bits) < 2) {
        throw new TemplateSyntaxError('\'url\' takes at least one argument (path to a view)');
    }
    $viewname = $bits[1];
    $args = array();
    $kwargs = array();
    $asvar = null;
    $bits = py_slice($bits, 2);
    if (count($bits) >= 2 && py_arr_get($bits, -2) == 'as') {
        $asvar = py_arr_get($bits, -1);
        $bits = py_slice($bits, null, -2);
    }

    /*
     * Backwards compatibility: check for the old comma separated format
     * {% url urlname arg1,arg2 %}
     * Initial check - that the first space separated bit has a comma in it
     */
    if ($bits && strpos($bits[0], ',')!==False) {
        $check_old_format = True;
        /**
         * In order to *really* be old format, there must be a comma
         * in *every* space separated bit, except the last.
         */
        foreach (py_slice($bits, 1, -1) as $bit) {
            if (strpos($bit, ',')===False) {
                /*
                 * No comma in this bit. Either the comma we found
                 * in bit 1 was a false positive (e.g., comma in a string),
                 * or there is a syntax problem with missing commas
                 */
                $check_old_format = False;;
                break;
            }
        }
    } else {
        // No comma found - must be new format.
        $check_old_format = False;
    }

    if ($check_old_format) {
        /*
         * Confirm that this is old format by trying to parse the first
         * argument. An exception will be raised if the comma is
         * unexpected (i.e. outside of a static string).
         */
        $match = py_re_match(DjaBase::$re_kwarg, $bits[0]);
        if ($match) {
            $value = py_arr_get($match->groups(), 1);
            try {
                $parser->compileFilter($value);
            } catch (TemplateSyntaxError $e) {
                $bits = explode(',', join('', $bits));
            }
        }
    }

    // Now all the bits are parsed into new format, process them as template vars
    if (count($bits)) {
        foreach ($bits as $bit) {
            $match = py_re_match(DjaBase::$re_kwarg, $bit);
            if (!$match) {
                throw new TemplateSyntaxError('Malformed arguments to url tag');
            }
            list ($name, $value) = $match->groups();
            if ($name) {
                $kwargs[$name] = $parser->compileFilter($value);
            } else {
                $args[] = $parser->compileFilter($value);
            }
        }
    }

    return new URLNode($viewname, $args, $kwargs, $asvar, True);
});


$lib->tag('widthratio', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = py_str_split( $token->contents);
    if (count($bits) != 4) {
        throw new TemplateSyntaxError('widthratio takes three arguments');
    }
    list($tag, $this_value_expr, $max_value_expr, $max_width) = $bits;

    return new WidthRatioNode($parser->compileFilter($this_value_expr),
        $parser->compileFilter($max_value_expr),
        $parser->compileFilter($max_width));
});


$lib->tag('with', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = py_str_split( $token->contents);
    $remaining_bits = py_slice($bits, 1);
    $extra_context = DjaBase::tokenKwargs($remaining_bits, $parser, True);
    if (!$extra_context) {
        throw new TemplateSyntaxError('\'with\' expected at least one variable assignment');
    }
    if ($remaining_bits) {
        throw new TemplateSyntaxError('\'with\' received an invalid token: ' . $remaining_bits[0]);
    }
    $nodelist = $parser->parse(array('endwith'));
    $parser->deleteFirstToken();
    return new WithNode(null, null, $nodelist, $extra_context);
});


return $lib;