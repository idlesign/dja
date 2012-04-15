<?php


class DebugLexer extends Lexer {

    /**
     * Return a list of tokens from a given template_string
     *
     * @return array
     */
    public function tokenize() {
        $result = array();
        $upto = 0;
        $matches = new PyReFinditer(DjaBase::getReTag(), $this->template_string);

        /** @var $match pyReMatchObject */
        foreach ($matches as $match) {
            list($start, $end) = $match->span();
            if ($start > $upto) {
                $result[] = $this->createToken(py_slice($this->template_string, $upto, $start), array($upto, $start), False);
                $upto = $start;
            }
            $result[] = $this->createToken(py_slice($this->template_string, $start, $end), array($start, $end), True);
            $upto = $end;
        }
        $last_bit = py_slice($this->template_string, $upto);
        if ($last_bit) {
            $result[] = $this->createToken($last_bit, array($upto, $upto + strlen($last_bit)), False);
        }
        return $result;
    }

    public function createToken($token_string, $source, $in_tag) {
        $token = parent::createToken($token_string, $in_tag);
        $token->source = array($this->origin, $source);
        return $token;
    }
}


class DebugParser extends Parser {

    public function __construct($lexer) {
        parent::__construct($lexer);
        $this->command_stack = array();
    }

    public function enterCommand($command, $token) {
        $this->command_stack[] = array($command, $token->source);
    }

    public function exitCommand() {
        py_arr_pop($this->command_stack);
    }

    public function error($token, $msg) {
        return $this->sourceError($token->source, $msg);
    }

    public function sourceError($source, $msg) {
        $e = new TemplateSyntaxError($msg);
        $e->source = $source;
        return $e;
    }

    public function createNodelist() {
        return new DebugNodeList();
    }

    public function createVariableNode($contents) {
        return new DebugVariableNode($contents);
    }

    public function extendNodelist($nodelist, $node, $token) {
        $node->source = $token->source;
        parent::extendNodelist($nodelist, $node, $token);
    }

    public function unclosedBlockTag($parse_until) {
        list($command, $source) = py_arr_pop($this->command_stack);
        $msg = 'Unclosed tag \'' . $command . '\'. Looking for one of: ' . join(', ', $parse_until);
        throw $this->sourceError($source, $msg);
    }

    public function compileFunctionError($token, &$e) {
        if (!isset($e->source)) {
            $e->source = $token->source;
        }
    }
}


class DebugNodeList extends NodeList {

    public function renderNode($node, $context) {
        try {
            return $node->render($context);
        } catch (Exception $e) {
            if (!py_hasattr($e, 'django_template_source')) {
                $e->django_template_source = $node->source;
            }
            throw $e;
        }
    }
}


class DebugVariableNode extends VariableNode {

    public function render($context) {
        try {
            $output = $this->filter_expression->resolve($context);
            // TODO $output = localize($output, use_l10n=context.use_l10n);
        } catch (TemplateSyntaxError $e) {
            if (!isset($e->source)) {
                $e->source = $this->source;
            }
            throw $e;
        }

        if (($context->autoescape && !($output instanceof SafeData)) || ($output instanceof EscapeData)) {
            return escape($output);
        }
        return $output;
    }
}
