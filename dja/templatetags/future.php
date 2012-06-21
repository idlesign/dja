<?php


// TODO ssi tag.

$lib = new Library();

$lib->tag('url', function($parser, $token) {
    /**
     * @var Parser $parser
     * @var Token $token
     */
    $bits = $token->splitContents();
    if (count($bits) < 2) {
        throw new TemplateSyntaxError('\'url\' takes at least one argument (path to a view)');
    }
    $viewname = $parser->compileFilter($bits[1]);
    $args = array();
    $kwargs = array();
    $asvar = null;
    $bits = py_slice($bits, 2);
    if (count($bits) >= 2 && py_arr_get($bits, -2) == 'as') {
        $asvar = py_arr_get($bits, -1);
        $bits = py_slice($bits, null, -2);
    }

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

    return new URLNode($viewname, $args, $kwargs, $asvar, False);
});


return $lib;