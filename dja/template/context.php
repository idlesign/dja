<?php


class DjaContext {

    // Actual callables cached.
    public static $_standard_context_processors = null;

    /*
     * We need the CSRF processor no matter what the user has in their settings,
     * because otherwise it is a security vulnerability, and we can't afford to leave
     * this to human error or failure to read migration instructions.
     */
    public static $_builtin_context_processors = array('django.core.context_processors.csrf');


    // TODO get_standard_processors()

}


/*
 * pop() has been called more times than push()
 */
class ContextPopException extends DjaException {

}


class BaseContext implements ArrayAccess {

    public $dicts = array();

    public function __construct($dict_ = null) {
        $this->resetDicts($dict_);
    }

    private function resetDicts($value = null) {
        $this->dicts = array(empty($value) ? array() : $value);
    }

    // TODO implement __copy__ if required

    public function __toString() {
        return print_r($this->dicts, True);
    }

    // TODO implement __iter__ if required

    public function push() {
        $d = array();
        $this->dicts[] = $d;
        return $d;
    }

    public function pop() {
        if (count($this->dicts) == 1) {
            throw new ContextPopException();
        }
        return py_arr_pop($this->dicts);
    }

    /**
     * Set a variable in the current context
     *
     * @param $key
     * @param $value
     */
    public function offsetSet($key, $value) {
        if (is_array($key)) {
            $this->dicts[count($this->dicts) - 1][$key[0]][$key[1]] = $value;
            return;
        }
        $this->dicts[count($this->dicts) - 1][$key] = $value;
    }

    /**
     * Get a variable's value, starting at the current context and going upward
     *
     * @param $key
     *
     * @return mixed
     * @throws KeyError
     */
    public function offsetGet($key) {
        $dicts = $this->dicts;
        krsort($dicts);
        foreach ($dicts as $d) {
            if (isset($d[$key]) || key_exists($key, $d)) {
                return $d[$key];
            }
        }
        throw new KeyError($key);
    }

    /**
     * Delete a variable from the current context
     *
     * @param $key
     */
    public function offsetUnset($key) {
        unset($this->dicts[count($this->dicts) - 1][$key]);
    }

    public function hasKey($key) {
        foreach ($this->dicts as $d) {
            if (isset($d[$key])) {
                return True;
            }
        }
        return False;
    }

    public function offsetExists($key) {
        return $this->hasKey($key);
    }

    public function get($key, $otherwise = null) {
        $dicts = $this->dicts;
        krsort($dicts);
        foreach ($dicts as $d) {
            if (isset($d[$key])) {
                return $d[$key];
            }
        }
        return $otherwise;
    }

    /**
     * Returns a new context with the same properties, but with only the
     * values given in 'values' stored.
     *
     * @param null $values
     *
     * @return mixed
     */
    public function new_($values = null) {
        $new_context = clone $this; // TODO copy should be much deeper?
        $new_context->resetDicts($values);
        return $new_context;
    }

}


/**
 * A stack container for variable context
 */
class Context extends BaseContext {

    public function __construct($dict_ = null, $autoescape = True, $current_app = null, $use_l10n = null, $use_tz = null) {
        $this->autoescape = $autoescape;
        $this->current_app = $current_app;
        $this->use_l10n = $use_l10n;
        $this->use_tz = $use_tz;
        $this->render_context = new RenderContext();
        parent::__construct($dict_);
    }

    // TODO implement __copy__ if required

    /**
     * Pushes other_dict to the stack of dictionaries in the Context
     *
     * @param $other_dict
     *
     * @return mixed
     */
    public function update($other_dict) {
        if (!is_array($other_dict)) {
            throw new TypeError('other_dict must be a mapping (dictionary-like) object.');
        }
        $this->dicts[] = $other_dict;
        return $other_dict;
    }
}


/**
 * A stack container for storing Template state.
 *
 * RenderContext simplifies the implementation of template Nodes by providing a
 * safe place to store state between invocations of a node's `render` method.
 *
 * The RenderContext also provides scoping rules that are more sensible for
 * 'template local' variables. The render context stack is pushed before each
 * template is rendered, creating a fresh scope with nothing in it. Name
 * resolution fails if a variable is not found at the top of the RequestContext
 * stack. Thus, variables are local to a specific template and don't affect the
 * rendering of other templates as they would if they were stored in the normal
 * template context.
 */
class RenderContext extends BaseContext {

    // TODO implement __iter__ if required

    public function hasKey($key) {
        return isset($this->dicts[count($this->dicts) - 1][$key]);
    }

    public function get($key, $otherwise = null) {
        $d = $this->dicts[count($this->dicts) - 1];
        if (isset($d[$key])) {
            return $d[$key];
        }
        return $otherwise;
    }
}


// TODO RequestContext
