<?php

/*
 * Python natives helpers.
 */


function py_str_starts_with($string, $bit) {
    $len = strlen($bit);
    if (!$len) {
        return False;
    }
    return (substr($string, 0, $len) === $bit);
}


function py_str_split($str, $max=PHP_INT_MAX, $delim=null) {
    // Beware, friend: `explode` expects LONG_MAX as non-limiting limit, we use PHP_INT_MAX instead.
    if ($delim===null) {
        $expl = explode(' ', trim($str), $max);
        return array_values(array_filter($expl, function($v) { return ($v!=''); }));
    }
    return explode($delim, $str, $max);
}


function py_str_ends_with($string, $bit) {
    $len = strlen($bit);
    if (!$len) {
        return False;
    }
    return (substr($string, $len * -1) === $bit);
}


function py_arr_pop(&$arr, $index = null) {
    if ($index === null) {
        return array_pop($arr);
    }
    $keys = array_keys($arr);
    if (!isset($keys[$index])) {
        throw new IndexError();
    }
    $index = $keys[$index];
    $item = $arr[$index];
    unset($arr[$index]);
    $arr = array_values($arr);
    return $item;
}


function py_arr_insert(&$arr, $pos, $item) {
    if (count($arr) == 0) {
        if (is_array($item)) {
            $arr = $item;
        } else {
            $arr[] = $item;
        }
        return;
    }
    $arr_res = array();
    foreach ($arr as $k => $v) {
        if ($k == $pos) {
            if (is_array($item)) {
                foreach ($item as $itm) {
                    $arr_res[] = $itm;
                }
            } else {
                $arr_res[] = $item;
            }
        }
        $arr_res[] = $arr[$k];
    }
    $arr = $arr_res;
}


function py_arr_get($arr, $key, $default = '<null>') {
    if (is_int($key) && $key < 0) {
        $key = count($arr) - abs($key);
    }
    if (!isset($arr[$key])) {
        if ($default !== '<null>') {
            return $default;
        }
        throw new AttributeError();  // TODO KeyError?
    }
    return $arr[$key];
}


function py_slice($subj, $from = null, $till = null, $step = 1) {

    if ($from === null) {
        $from = 0;
    }

    if (is_array($subj)) {
        if ($step == -1) {
            krsort($subj);
        }
        $len = count($subj);
        if ($till === null) {
            $till = $len;
        } elseif ($till < 0) {
            $till = $len - (abs($till));
        }
        $result = array();
        $bit_counter = 0;
        foreach ($subj as $bit) {
            if ($bit_counter >= $from && $bit_counter < $till) {
                $result[] = $bit;
            }
            $bit_counter++;
        }
        return $result;
    }

    if ($step == -1) {
        $subj = strrev($subj); // TODO Check unicode handling.
    }

    $len = mb_strlen($subj, 'utf-8');
    if ($till === null) {
        $till = $len;
    }
    $sub_len = $till - $from;
    if ($sub_len < 0) {
        $sub_len = $len - (abs($sub_len));
    }
    return mb_substr($subj, $from, $sub_len, 'utf-8');
}


/**
 * Replaces special characters in string using the %xx escape.
 *
 * @param $s
 * @param null|string $safe  Characters that should not be quoted.
 * @return mixed|string
 */
function py_urllib_quote($s, $safe=null) {
    $s = rawurlencode($s);
    if ($safe!==null) {
        $safe = str_split($safe);
        $chars = array('%2F'=>'/', '%23'=>'#', '%25'=>'%', '%5B'=>'[', '%5D'=>']',
            '%3D'=>'=', '%3A'=>':', '%3B'=>';', '%24'=>'$', '%26'=>'&',
            '%28'=>'(', '%29'=>')', '%2B'=>'+', '%2C'=>',', '%21'=>'!',
            '%3F'=>'?', '%2A'=>'*', '%40'=>'@', '%27'=>'\'', '%7E'=>'~',
        );
        if ($safe) {
            $f = function($i) use ($safe) {
                return in_array($i, $safe);
            };
            $chars = array_filter($chars, $f);
        }
        $s = str_replace(array_keys($chars), array_values($chars), $s);
    }
    return $s;
}


class PyReMatchObject {

    private $_match = array();

    public function __construct($match_arr, $match_all_mode = True) {
        $this->_match = $match_arr;
        $last = array_pop($match_arr);
        if (isset($match_arr[0][1])) {
            $this->_start = $match_arr[0][1];
        } else {
            $this->_start = $match_arr[0][1];
        }
        $this->_end = ($last[1] + strlen(utf8_decode($last[0])));  // TODO Check unicode handling.
    }

    public function start() {
        return $this->_start;
    }

    public function end() {
        return $this->_end;
    }

    public function span() {
        return array($this->_start, $this->_end);
    }

    public function group($ids) {
        if (is_array($ids)) {
            $results = array();
            foreach ($ids as $id) {
                $match = null;
                if (isset($this->_match[$id])) {
                    $match = $this->_match[$id][0];
                }
                $results[] = $match;
            }
            return $results;
        }
        $match = null;
        if (isset($this->_match[$ids])) {
            $match = $this->_match[$ids][0];
        }
        return $match;
    }

    public function groups() {
        $values = array();
        foreach ($this->_match as $key => $match) {
            if ($key > 0) {
                $values[] = $match[0];
            }
        }
        return $values;
    }

}


function py_re_match($re, $subj) {
    $match = array();
    preg_match($re, $subj, $match, PREG_OFFSET_CAPTURE);
    if (empty($match)) {
        return null;
    }
    return new PyReMatchObject($match, False);
}


class PyReFinditer implements Iterator, ArrayAccess {

    private $_matches = array();
    private $_iter_pos = 0;

    public function __construct($re, $subj) {
        $matches = array();
        preg_match_all($re, $subj, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->_matches[] = new PyReMatchObject($match);
        }
    }

    /* Array Access */
    public function offsetSet($idx, $val) {
        throw new BadMethodCallException('Not implemented');
    }

    public function offsetExists($idx) {
        return isset($this->_matches[$idx]);
    }

    public function offsetUnset($idx) {
        throw new BadMethodCallException('Not implemented');
    }

    public function offsetGet($idx) {
        return isset($this->_matches[$idx]) ? $this->_matches[$idx] : null;
    }

    /* Iterator */
    public function rewind() {
        $this->_iter_pos = 0;
    }

    public function next() {
        $this->_iter_pos++;
    }

    public function current() {
        return $this->_matches[$this->_iter_pos];
    }

    public function key() {
        return $this->_iter_pos;
    }

    public function valid() {
        return $this->offsetExists($this->_iter_pos);
    }

}


function py_inspect_getargspec($func) {
    if (is_array($func)) {
        $refl_f = new ReflectionMethod($func[0], $func[1]);
    } else {
        $refl_f = new ReflectionFunction($func);
    }
    $names = array();
    $defaults = array();
    /** @var $p ReflectionParameter */
    foreach ($refl_f->getParameters() as $p) {
        $name = $p->getName();
        $names[] = $name;
        if ($p->isOptional()) {
            $defaults[$name] = $p->getDefaultValue();
        }
    }
    return array($names, null, null, $defaults);
}


function py_functools_partial($func, array $args = null, array $kwargs = null) {
    $newf = function() use ($func, $args, $kwargs) {
        list($params_expected, $z, $z, $defaults) = py_inspect_getargspec($func);
        $fargs_ = array();
        foreach ($params_expected as $param) {
            $val = null;
            if (isset($kwargs[$param])) {
                $val = $kwargs[$param];
            }
            $fargs_[] = $val;
        }

        return call_user_func_array($func, $fargs_);
    };
    return $newf;
}


function py_getattr($object, $name, $default = '<null>') {
    $has_attr = (is_object($object) && !($object instanceof Closure));
    $has_prop = False;

    if ($has_attr) {
        $has_prop = property_exists($object, $name);
        // Try for magic __get().
        if (!$has_prop && method_exists($object, '__get')) {
            try {
                $has_prop = ($object->$name !== null);
            } catch (KeyError $e) {
            }
        }
        $has_attr = $has_prop;
        if (!$has_attr) {
            $has_attr = method_exists($object, $name);
        }
    }

    if (!$has_attr) {
        if ($default !== '<null>') {
            return $default;
        }
        throw new AttributeError();
    }

    if ($has_prop) {
        return $object->$name;
    }

    return new PyLazyMethod($object, $name);
}


class PyLazyMethod {

    private $_obj;
    private $_attr;

    public function __construct($obj, $attr) {
        $this->_obj = $obj;
        $this->_attr = $attr;
    }

    public function __invoke() {
        $args_exp_ = py_inspect_getargspec(array($this->_obj, $this->_attr));
        if (count($args_exp_[0]) > 0) {
            throw new TypeError(); // Simulate arguments required type error.
        }
        return call_user_func_array(array($this->_obj, $this->_attr), func_get_args());
    }

}


class PyLazyObj {

    private $_cl = null;
    private $_args = array();

    public function __construct($cl_name, $args = array()) {
        $this->_cl = $cl_name;
        $this->_args = $args;
    }

    public function __invoke() {
        $rc = new ReflectionClass($this->_cl);
        return $rc->newInstanceArgs($this->_args);
    }

}


class PyItertoolsCycle {

    private $_items;
    private $_pos = 0;

    public function __construct($items) {
        $this->_items = $items;
    }

    function next() {
        $res = $this->_items[$this->_pos];
        $next = $this->_pos + 1;
        if ($next > (count($this->_items) - 1)) {
            $next = 0;
        }
        $this->_pos = $next;
        return $res;
    }

}


function py_hasattr($obj, $attr) {
    return (property_exists($obj, $attr) || method_exists($obj, $attr));
}


function py_zip($a1, $a2) {
    if (!is_array($a1) || !is_array($a2)) {
        throw new TypeError();
    }
    $a1c = count($a1);
    $a2c = count($a2);
    if ($a1c < $a2c) {
        $a2 = py_slice($a2, null, $a1c);
    } else {
        $a1 = py_slice($a1, null, $a2c);
    }
    return array_combine($a1, $a2);
}
