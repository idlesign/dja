<?php



/**
 * Base class for operators and literals, mainly for debugging and for throwing syntax errors.
 */
class TokenBase {

    public $id = null;  // node/token type name
    public $value = null;  // used by literals
    public $first = null;  // used by tree nodes
    public $second = null;  // used by tree nodes

    public function nud($parser) {
        // Null denotation - called in prefix context
        throw new $parser->error_class('Not expecting \'' . $this->id . '\' in this position in if tag.');
    }

    public function led($left, $parser) {
        // Left denotation - called in infix context
        throw new $parser->error_class('Not expecting \'' . $this->id . '\' as infix operator in if tag.');
    }

    /**
     * Returns what to display in error messages for this node
     * @return null
     */
    public function display() {
        return $this->id;
    }

    public function __toString() {
        $out = array();
        foreach (array($this->id, $this->first, $this->second) as $x) {
            if ($x !== null) {
                $out = print_r($x, True);
            }
        }
        return '(' . join(' ', $out) . ')';
    }

}


/*
 * Creates an infix operator, given a binding power and a function that evaluates the node
 */
function infix($bp, $func) {
    return new PyLazyObj('OperatorInfix', array($bp, $func));
}


class OperatorInfix extends TokenBase {

    public function __construct($bp, $func) {
        $this->bp = $bp;
        $this->lbp = $bp;
        $this->func = $func;
    }

    /**
     * @param mixed $left
     * @param TemplateIfParser $parser
     * @return OperatorInfix
     */
    public function led($left, $parser) {
        $this->first = $left;
        $this->second = $parser->expression($this->bp);
        return $this;
    }

    public function eval_($context) {
        try {
            /** @var $func Closure */
            $func = $this->func;
            return $func($context, $this->first, $this->second);
        } catch (Exception $e) {
            /*
             * Templates shouldn't throw exceptions when rendering.  We are
             * most likely to get exceptions for things like {% if foo in bar %} where
             * 'bar' does not support 'in', so default to False
             */
            return False;
        }
    }
}


/*
 * Creates a prefix operator, given a binding power and a function that evaluates the node.
 */
function prefix($bp, $func) {
    return new PyLazyObj('OperatorPrefix', array($bp, $func));
}


class OperatorPrefix extends TokenBase {

    public function __construct($bp, $func) {
        $this->bp = $bp;
        $this->lbp = $bp;
        $this->func = $func;
    }

    /**
     * @param TemplateIfParser $parser
     * @return OperatorPrefix
     */
    public function nud($parser) {
        $this->first = $parser->expression($this->bp);
        $this->second = null;
        return $this;
    }

    public function eval_($context) {
        try {
            /** @var $func Closure */
            $func = $this->func;
            return $func($context, $this->first);
        } catch (Exception $e) {
            return False;
        }
    }
}


function dja_init_operators() {

    /*
     * Operator precedence follows Python.
     * NB - we can get slightly more accurate syntax error messages by not using the
     * same object for '==' and '='.
     * We defer variable evaluation to the lambda to ensure that terms are
     * lazily evaluated using Python's boolean parsing logic.
     */
    $operators = array(
        'or' => infix(6, function ($context, $x, $y) {
            return $x->eval_($context) || $y->eval_($context);
        }),
        'and' => infix(7, function ($context, $x, $y) {
            return $x->eval_($context) && $y->eval_($context);
        }),
        'not' => prefix(8, function ($context, $x) {
            return !$x->eval_($context);
        }),
        'in' => infix(9, function ($context, $x, $y) {
            return in_array($x->eval_($context), $y->eval_($context));
        }),
        'not in' => infix(9, function ($context, $x, $y) {
            return !in_array($x->eval_($context), $y->eval_($context));
        }),
        '=' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) == $y->eval_($context);
        }),
        '==' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) == $y->eval_($context);
        }),
        '!=' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) != $y->eval_($context);
        }),
        '>' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) > $y->eval_($context);
        }),
        '>=' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) >= $y->eval_($context);
        }),
        '<' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) < $y->eval_($context);
        }),
        '<=' => infix(10, function ($context, $x, $y) {
            return $x->eval_($context) <= $y->eval_($context);
        }),
    );
    // Assign 'id' to each:
    foreach ($operators as $key => $op) {
        $op->id = $key;
        $operators[$key] = $op;
    }

    // Hmm, globals again...
    $GLOBALS['DJA_OPERATORS'] = $operators;

}


dja_init_operators();


/**
 * A basic self-resolvable object similar to a Django template variable.
 */
class Literal extends TokenBase {

    /*
     * IfParser uses Literal in create_var, but TemplateIfParser overrides
     * create_var so that a proper implementation that actually resolves
     * variables, filters etc is used.
     */
    public $id = 'literal';
    public $lbp = 0;

    public function __construct($value) {
        $this->value = $value;
    }

    public function display() {
        return (string)$this->value;
    }

    public function nud($parser) {
        return $this;
    }

    public function eval_($context) {
        return $this->value;
    }

    public function __toString() {
        return '(' . $this->id . ' ' . $this->value . ')';
    }
}


class EndToken extends TokenBase {

    public $lbp = 0;

    public function nud($parser) {
        throw new $parser->error_class('Unexpected end of expression in if tag.');
    }
}

$GLOBALS['DJA_ENDTOKEN'] = new EndToken();


class IfParser {

    public $error_class = 'ValueError';

    public function __construct($tokens) {
        // pre-pass necessary to turn  'not','in' into single token
        $l = count($tokens);
        $mapped_tokens = array();
        $i = 0;

        while ($i < $l) {
            $token = $tokens[$i];
            if ($token == 'not' && ($i + 1 < $l) && $tokens[$i + 1] == 'in') {
                $token = 'not in';
                $i += 1;  // skip 'in'
            }
            $mapped_tokens[] = $this->translateToken($token);
            $i += 1;
        }

        $this->tokens = $mapped_tokens;
        $this->pos = 0;
        $this->current_token = $this->next();
    }

    public function translateToken($token) {
        try {
            if (!isset($GLOBALS['DJA_OPERATORS'][$token])) {
                throw new KeyError();
            }
            $op = $GLOBALS['DJA_OPERATORS'][$token];
        } catch (KeyError $e) {  // KeyError, TypeError
            return $this->createVar($token);
        }
        /** @var $op Closure */
        return $op();
    }

    public function next() {
        if ($this->pos >= count($this->tokens)) {
            return $GLOBALS['DJA_ENDTOKEN'];
        } else {
            $retval = $this->tokens[$this->pos];
            $this->pos += 1;
            return $retval;
        }
    }

    public function parse() {
        $retval = $this->expression();
        // Check that we have exhausted all the tokens
        if (!($this->current_token instanceof EndToken)) {
            throw new $this->error_class('Unused \'' . $this->current_token->display() . '\' at end of if expression.');
        }
        return $retval;
    }


    public function expression($rbp = 0) {
        $t = $this->current_token;
        $this->current_token = $this->next();
        $left = $t->nud($this);
        while ($rbp < $this->current_token->lbp) {
            $t = $this->current_token;
            $this->current_token = $this->next();
            $left = $t->led($left, $this);
        }
        return $left;
    }

    public function createVar($value) {
        return new Literal($value);
    }
}
