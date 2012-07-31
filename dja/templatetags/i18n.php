<?php


class GetAvailableLanguagesNode extends Node {

    public function __construct($variable) {
        $this->variable = $variable;
    }

    public function render($context) {
        $intl_ = Dja::getI18n();
        $langs_ = array();
        foreach ($intl_->getLanguages() as $code_ => $name_) {
            $langs_[] = array($code_, $intl_->ugettext($name_));
        }
        $context[$this->variable] = $langs_;
        return '';
    }
}


class TranslateNode extends Node {

    public function __construct($filter_expression, $noop, $asvar = null, $message_context = null) {
        $this->noop = $noop;
        $this->asvar = $asvar;
        $this->message_context = $message_context;
        $this->filter_expression = $filter_expression;

        if ($this->filter_expression->var instanceof SafeString || is_string($this->filter_expression->var)) {
            $this->filter_expression->var = new Variable("'" . (string)$this->filter_expression->var . "'");
        }
    }

    public function render($context) {
        $this->filter_expression->var->translate = !$this->noop;

        if ($this->message_context) {
            $this->filter_expression->var->message_context[] = $this->message_context->resolve($context);
        }
        $output = $this->filter_expression->resolve($context);
        $value = DjaBase::renderValueInContext($output, $context);
        if ($this->asvar) {
            $context[$this->asvar] = $value;
            return '';
        } else {
            return $value;
        }
    }
}


class BlockTranslateNode extends Node {

    public function __construct($extra_context, $singular, $plural = null, $countervar = null, $counter = null, $message_context = null) {
        $this->extra_context = $extra_context;
        $this->singular = $singular;
        $this->plural = $plural;
        $this->countervar = $countervar;
        $this->counter = $counter;
        $this->message_context = $message_context;
    }

    public function renderTokenList($tokens) {
        $result = array();
        $vars = array();
        foreach ($tokens as $token) {
            if ($token->token_type == DjaBase::TOKEN_TEXT) {
                $result[] = $token->contents;
            } elseif ($token->token_type == DjaBase::TOKEN_VAR) {
                $result[] = '%(' . $token->contents . ')s';
                $vars[] = $token->contents;
            }
        }
        return array(join('', $result), $vars);
    }

    public function render($context) {
        if ($this->message_context) {
            $message_context = $this->message_context->resolve($context);
        } else {
            $message_context = null;
        }
        $tmp_context = array();
        foreach ($this->extra_context as $var => $val) {
            $tmp_context[$var] = $val->resolve($context);
        }
        // Update() works like a push(), so corresponding $context->pop() is at the end of function
        $context->update($tmp_context);
        list($singular, $vars) = $this->renderTokenList($this->singular);
        // Escape all isolated '%'
        $singular = preg_replace('~%(?!\()~', '%%', $singular);
        $translation = Dja::getI18n();
        if ($this->plural && $this->countervar && $this->counter) {
            $count = $this->counter->resolve($context);
            $context[$this->countervar] = $count;
            list($plural, $plural_vars) = $this->renderTokenList($this->plural);
            $plural = preg_replace('~%(?!\()~', '%%', $plural);
            if ($message_context) {
                $result = $translation->npgettext($message_context, $singular, $plural, $count);
            } else {
                $result = $translation->ungettext($singular, $plural, $count);
            }
            $vars = array_merge($vars, $plural_vars);
        } else {
            if ($message_context) {
                $result = $translation->pgettext($message_context, $singular);
            } else {
                $result = $translation->ugettext($singular);
            }
        }
        $data = array();
        foreach ($vars as $v) {
            $data[$v] = DjaBase::renderValueInContext(py_arr_get($context, $v, ''), $context);
        }

        $context->pop();
        if (!empty($data)) {
            foreach ($data as $k_ => $v_) {
                $result = str_replace('%(' . $k_ . ')s', $v_, $result);
            }
        } else {
            $lang = $translation->getLanguage();
            $translation->deactivate($lang);
            $result = $this->render($context);
            $translation->activate($lang);
        }
        return $result;
    }
}


class TranslateParser extends TokenParser {

    private $_base_parser;

    public function setBaseParser($parser) {
        $this->_base_parser = $parser;
    }

    public function top() {
        $value = $this->value();

        if ($value[0] == "'") {
            $m = py_re_match("~^'([^']+)'(\|.*$)~", $value);
            if ($m) {
                $value = '"' . str_replace('"', '\\"', $m->group(1)) . '"' . $m->group(2);
            } elseif (py_str_ends_with($value, "'")) {
                $value = '"' . str_replace('"', '\\"', py_slice($value, 1, -1)) . '"';
            }
        }

        $noop = False;
        $asvar = null;
        $message_context = null;

        while ($this->more()) {
            $tag = $this->tag();
            if ($tag == 'noop') {
                $noop = True;
            } elseif ($tag == 'context') {
                $message_context = $this->_base_parser->compileFilter($this->value());
            } elseif ($tag == 'as') {
                $asvar = $this->tag();
            } else {
                throw new TemplateSyntaxError("Only options for 'trans' are 'noop', 'context \"xxx\"', and 'as VAR'.");
            }
        }

        return array($value, $noop, $asvar, $message_context);
    }

}


$lib = new Library();


$lib->tag('get_available_languages', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $args = py_str_split($token->contents);
    if (count($args) != 3 || $args[1] != 'as') {
        throw new TemplateSyntaxError("'get_available_languages' requires 'as variable' (got " . $args . ")");
    }
    return new GetAvailableLanguagesNode($args[2]);
});


$lib->tag('trans', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $translate_parser = new TranslateParser($token->contents);
    $translate_parser->setBaseParser($parser);
    list($value, $noop, $asvar, $message_context) = $translate_parser->top();
    return new TranslateNode($parser->compileFilter($value), $noop, $asvar, $message_context);
});


$lib->tag('blocktrans', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = $token->splitContents();

    $options = array();
    $remaining_bits = py_slice($bits, 1);
    while ($remaining_bits) {
        $option = py_arr_pop($remaining_bits, 0);
        if (in_array($option, $options)) {
            throw new TemplateSyntaxError('The ' . $option . ' option was specified more than once.');
        }
        if ($option == 'with') {
            $value = DjaBase::tokenKwargs($remaining_bits, $parser, True);
            if (!$value) {
                throw new TemplateSyntaxError('"with" in ' . $bits[0] . ' tag needs at least one keyword argument.');
            }
        } elseif ($option == 'count') {
            $value = DjaBase::tokenKwargs($remaining_bits, $parser, True);
            if (count($value) != 1) {
                throw new TemplateSyntaxError('"count" in ' . $bits[0] . ' tag expected exactly one keyword argument.');
            }
        } elseif ($option == 'context') {
            try {
                $value = py_arr_pop($remaining_bits, 0);
                $value = $parser->compileFilter($value);
            } catch (Exception $e) {
                throw new TemplateSyntaxError('"context" in ' . $bits[0] . ' tag expected exactly one argument.');
            }
        } else {
            throw new TemplateSyntaxError('Unknown argument for ' . $bits[0] . ' tag: ' . $option . '.');
        }
        $options[$option] = $value;
    }

    if (isset($options['count'])) {
        foreach ($options['count'] as $k_ => $v_) {
            $countervar = $k_;
            $counter = $v_;
            break;
        }
    } else {
        $countervar = null;
        $counter = null;
    }
    if (isset($options['context'])) {
        $message_context = $options['context'];
    } else {
        $message_context = null;
    }
    $extra_context = py_arr_get($options, 'with', array());

    $singular = array();
    $plural = array();
    while ($parser->tokens) {
        $token = $parser->nextToken();
        if (in_array($token->token_type, array(DjaBase::TOKEN_VAR, DjaBase::TOKEN_TEXT))) {
            $singular[] = $token;
        } else {
            break;
        }
    }
    if ($countervar && $counter) {
        if (trim($token->contents) != 'plural') {
            throw new TemplateSyntaxError("'blocktrans' doesn't allow other block tags inside it");
        }
        while ($parser->tokens) {
            $token = $parser->nextToken();
            if (in_array($token->token_type, array(DjaBase::TOKEN_VAR, DjaBase::TOKEN_TEXT))) {
                $plural[] = $token;
            } else {
                break;
            }
        }
    }
    if (trim($token->contents) != 'endblocktrans') {
        throw new TemplateSyntaxError("'blocktrans' doesn't allow other block tags (seen " . $token->contents . ") inside it");
    }

    return new BlockTranslateNode($extra_context, $singular, $plural, $countervar, $counter, $message_context);
});


return $lib;