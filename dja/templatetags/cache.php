<?php


class CacheNode extends Node {

    public function __construct($nodelist, $expire_time_var, $fragment_name, $vary_on) {
        $this->nodelist = $nodelist;
        $this->expire_time_var = new Variable($expire_time_var);
        $this->fragment_name = $fragment_name;
        $this->vary_on = $vary_on;
    }

    public function render($context) {
        try {
            $expire_time = $this->expire_time_var->resolve($context);
        } catch (VariableDoesNotExist $e) {
            throw new TemplateSyntaxError('"cache" tag got an unknown variable: ' . $this->expire_time_var->var);
        }

        if (!is_numeric($expire_time)) {
            throw new TemplateSyntaxError('"cache" tag got a non-integer timeout value: ' . print_r($expire_time, true));
        }
        $expire_time = (int)$expire_time;

        // Build a unicode key for this fragment and all vary-on's.
        $vs_ = array();
        foreach ($this->vary_on as $var) {
            $v_ = new Variable($var);
            $vs_[] = urlencode($v_->resolve($context));
        }
        $args = join(':', $vs_);
        unset ($vs_);

        $cache_key = 'template.cache.' . $this->fragment_name . '.'. md5($args);
        $manager = Dja::getCacheManager();
        $value = $manager->get($cache_key);
        if ($value===null) {
            $value = $this->nodelist->render($context);
            $manager->set($cache_key, $value, $expire_time);
        }
        return $value;
    }
}


$lib = new Library();

$lib->tag('cache', function($parser, $token) {
   /**
    * @var Parser $parser
    * @var Token $token
    */
    $nodelist = $parser->parse(array('endcache'));
    $parser->deleteFirstToken();
    $tokens = py_str_split($token->contents);
    if (count($tokens) < 3) {
        throw new TemplateSyntaxError('\'cache\' tag requires at least 2 arguments.');
    }
    return new CacheNode($nodelist, $tokens[1], $tokens[2], py_slice($tokens, 3));
});


return $lib;