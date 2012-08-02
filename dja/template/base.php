<?php


class DjaBase {

    // Template syntax constants.
    const FILTER_SEPARATOR = '|';
    const FILTER_ARGUMENT_SEPARATOR = ':';
    const VARIABLE_ATTRIBUTE_SEPARATOR = '.';
    const BLOCK_TAG_START = '{%';
    const BLOCK_TAG_END = '%}';
    const VARIABLE_TAG_START = '{{';
    const VARIABLE_TAG_END = '}}';
    const COMMENT_TAG_START = '{#';
    const COMMENT_TAG_END = '#}';
    const TRANSLATOR_COMMENT_MARK = 'Translators';
    const SINGLE_BRACE_START = '{';
    const SINGLE_BRACE_END = '}';

    const ALLOWED_VARIABLE_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.';

    const TOKEN_TEXT = 0;
    const TOKEN_VAR = 1;
    const TOKEN_BLOCK = 2;
    const TOKEN_COMMENT = 3;

    public static $TOKEN_MAPPING = array(
        self::TOKEN_TEXT => 'Text',
        self::TOKEN_VAR => 'Var',
        self::TOKEN_BLOCK => 'Block',
        self::TOKEN_COMMENT => 'Comment',
    );

    // What to report as the origin for templates that come from non-loader sources (e.g. strings).
    const UNKNOWN_SOURCE = '<unknown source>';

    // True if TEMPLATE_STRING_IF_INVALID contains a format string (%s). None means uninitialised.
    public static $invalid_var_format_string = null;

    // Global dictionary of libraries that have been loaded using get_library.
    public static $libraries = array();

    // Global list of libraries to load by default for a new parser.
    public static $builtins = array();


    private static $templatetags_modules = array();

    // Regex for token keyword arguments
    public static $re_kwarg = '~(?:(\w+)=)?(.+)~';
    // Regex desribing tags.
    private static $_re_tag = null;
    // Regex describing filter.
    private static $_re_filter = null;

    /**
     * @static
     * @return string
     */
    public static function getReTag() {
        if (self::$_re_tag === null) {
            // Match a variable or block tag and capture the entire tag, including start/end delimiters.
            self::$_re_tag = sprintf('~(%s.*?%s|%s.*?%s|%s.*?%s)~',
                preg_quote(self::BLOCK_TAG_START), preg_quote(self::BLOCK_TAG_END),
                preg_quote(self::VARIABLE_TAG_START), preg_quote(self::VARIABLE_TAG_END),
                preg_quote(self::COMMENT_TAG_START), preg_quote(self::COMMENT_TAG_END)
            );
        }
        return self::$_re_tag;
    }

    /**
     * This only matches constant *strings* (things in quotes or marked for
     * translation). Numbers are treated as variables for implementation reasons (so that
     * they retain their type when passed to filters).
     *
     * @static
     * @return string
     */
    public static function getReFilter() {
        if (self::$_re_filter === null) {
            $strdq = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"'; // double-quoted string
            $strsq = "\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'"; // single-quoted string
            $i18n_open = '_\(';
            $i18n_close = '\)';
            $constant_string = '(?:' . $i18n_open . $strdq . $i18n_close . '|' . $i18n_open . $strsq . $i18n_close . '|' . $strdq . '|' . $strsq . ')';
            $num = '[-+\.]?\d[\d\.e]*';
            $var_chars = '\w\.';
            $filter_sep = preg_quote(self::FILTER_SEPARATOR);
            $arg_sep = preg_quote(self::FILTER_ARGUMENT_SEPARATOR);
            self::$_re_filter = '~^(?P<constant>' . $constant_string . ')|^(?P<var>[' . $var_chars . ']+|' . $num . ')|(?:' . $filter_sep . '(?P<filter_name>\w+)(?:' . $arg_sep . '(?:(?P<constant_arg>' . $constant_string . ')|(?P<var_arg>[' . $var_chars . ']+|' . $num . ')))?)~';
        }
        return self::$_re_filter;
    }

    /**
     * Compiles template_string into NodeList ready for rendering.
     *
     * @static
     *
     * @param string $template_string
     * @param Origin $origin
     *
     * @return NodeList
     */
    public static function compileString($template_string, $origin) {
        if (Dja::getSetting('TEMPLATE_DEBUG')) {
            $lexer_class = 'DebugLexer';
            $parser_class = 'DebugParser';
        } else {
            $lexer_class = 'Lexer';
            $parser_class = 'Parser';
        }
        /** @var $lexer Lexer */
        $lexer = new $lexer_class($template_string, $origin);
        /** @var $parser Parser */
        $parser = new $parser_class($lexer->tokenize());
        return $parser->parse();
    }

    /**
     * Converts any value to a string to become part of a rendered template. This
     * means escaping, if required, and conversion to a unicode object. If value
     * is a string, it is expected to have already been translated.
     *
     * @static
     *
     * @param $value
     * @param $context
     *
     * @return SafeString|string
     */
    public static function renderValueInContext($value, $context) {
        $value = dja_localtime($value, $context->use_tz);
        $value = localize($value, $context->use_l10n);
        if (($context->autoescape && !($value instanceof SafeData)) || ($value instanceof EscapeData)) {
            return escape($value);
        } else {
            return $value;
        }
    }

    /**
     * Load a template tag library module.
     *
     * Verifies that the library file returns Library object.
     *
     * @static
     *
     * @param string $taglib_module
     *
     * @return string
     * @throws InvalidTemplateLibrary
     */
    public static function importLibrary($taglib_module) {
        try {
            $mod = import_module($taglib_module);
        } catch (ImportError $e) {
            /*
             * If the ImportError is because the taglib submodule does not exist,
             * that's not an error that should be raised. If the submodule exists
             * and raised an ImportError on the attempt to load it, that we want
             * to raise.
             */
            if (!file_exists(str_replace('.', '/', $taglib_module))) {
                return null;
            }
            throw new InvalidTemplateLibrary('ImportError raised loading ' . $taglib_module . ': ' . $e->getMessage());
        }
        if ($mod !== False && !($mod instanceof Library)) {
            throw new InvalidTemplateLibrary('Template library `' . $taglib_module . '` does not return `Library` object.');
        }
        return $mod;
    }

    /**
     * Load the template library module with the given name.
     *
     * If library is not already loaded loop over all templatetags modules to locate it.
     *
     * {% load somelib %} and {% load someotherlib %} loops twice.
     *
     * Subsequent loads eg. {% load somelib %} in the same process will grab the cached module from libraries.
     *
     * @static
     *
     * @param string $library_name
     *
     * @return Library
     * @throws InvalidTemplateLibrary
     */
    public static function getLibrary($library_name) {
        $lib = py_arr_get(self::$libraries, $library_name, null);
        if (!$lib) {
            $templatetags_modules = self::getTemplatetagsModules();
            $tried_modules = array();
            foreach ($templatetags_modules as $module) {
                $taglib_module = $module . '.' . $library_name;
                $tried_modules[] = $taglib_module;
                $lib = self::importLibrary($taglib_module);
                if ($lib) {
                    self::$libraries[$library_name] = $lib;
                    break;
                }
            }
            if (!$lib) {
                throw new InvalidTemplateLibrary('Template library ' . $library_name . ' not found, tried `' . join(',', $tried_modules) . '`');
            }
        }
        return $lib;
    }

    /**
     * Loads library into dja for further usage in templates.
     *
     * @static
     * @param string $path Path to the library file.
     * @param string $name Name alias the library from templates.
     */
    public static function addLibraryFrom($path, $name) {
        $lib = self::importLibrary($path);
        if ($lib) {
            self::$libraries[$name] = $lib;
        }
    }

    /**
     * Return the list of all available template tag modules.
     * Caches the result for faster access.
     *
     * @static
     * @return array
     */
    public static function getTemplatetagsModules() {

        if (!self::$templatetags_modules) {
            $_templatetags_modules = array();
            // Populate list once per thread.
            $app_modules = array(rtrim(DJA_ROOT, DIRECTORY_SEPARATOR));
            $app_modules = array_merge($app_modules, Dja::getSetting('INSTALLED_APPS'));
            foreach ($app_modules as $app_module) {
                try {
                    $templatetag_module = $app_module . '.templatetags';
                    import_module($templatetag_module);
                    $_templatetags_modules[] = $templatetag_module;
                } catch (ImportError $e) {
                    continue;
                }
            }
            self::$templatetags_modules = $_templatetags_modules;
        }
        return self::$templatetags_modules;
    }

    /**
     * @static
     *
     * @param string $module
     */
    public static function addToBuiltins($module) {
        self::$builtins[$module] = self::importLibrary($module);
    }

    /**
     * A utility method for parsing token keyword arguments.
     *
     * @param array $bits A list containing remainder of the token (split by spaces)
     *         that is to be checked for arguments. Valid arguments will be removed
     *         from this list.
     * @param Parser $parser
     * @param bool $support_legacy If set to true ``True``, the legacy format
     *         ``1 as foo`` will be accepted. Otherwise, only the standard ``foo=1``
     *         format is allowed.
     *
     * @return array A dictionary of the arguments retrieved from the ``bits`` token
     *         list.
     *
     * There is no requirement for all remaining token ``bits`` to be keyword
     * arguments, so the dictionary will be returned as soon as an invalid
     * argument format is reached.
     */
    public static function tokenKwargs(&$bits, $parser, $support_legacy = False) {
        if (!$bits) {
            return array();
        }
        $match = py_re_match(DjaBase::$re_kwarg, $bits[0]);
        $kwarg_format = ($match && $match->group(1));
        if (!$kwarg_format) {
            if (!$support_legacy) {
                return array();
            }
            if (count($bits) < 3 || $bits[1] != 'as') {
                return array();
            }
        }

        $kwargs = array();
        while ($bits) {
            if ($kwarg_format) {
                $match = py_re_match(DjaBase::$re_kwarg, $bits[0]);
                if (!$match || !$match->group(1)) {
                    return $kwargs;
                }
                list ($key, $value) = $match->groups();
                $bits = py_slice($bits, 1);
            } else {
                if (count($bits) < 3 || $bits[1] != 'as') {
                    return $kwargs;
                }
                $key = $bits[2];
                $value = $bits[0];
                $bits = py_slice($bits, 3);
            }
            $kwargs[$key] = $parser->compileFilter($value);
            if ($bits && !$kwarg_format) {
                if ($bits[0] != 'and') {
                    return $kwargs;
                }
                $bits = py_slice($bits, 1);
            }
        }
        return $kwargs;
    }

    /**
     * Parses bits for template tag helpers (simple_tag, include_tag and
     * assignment_tag), in particular by detecting syntax errors and by
     * extracting positional and keyword arguments.
     *
     * @static
     *
     * @param Parser $parser
     * @param array $bits
     * @param array $params
     * @param $varargs
     * @param $varkw
     * @param $defaults
     * @param bool $takes_context
     * @param string $name
     *
     * @return array
     * @throws TemplateSyntaxError
     */
    public static function parseBits($parser, $bits, $params, $varargs, $varkw, $defaults, $takes_context, $name) {

        if ($takes_context) {
            if ($params[0] == 'context') {
                $params = py_slice($params, 1);
            } else {
                throw new TemplateSyntaxError('\'' . $name . '\' is decorated with takes_context=True so it must have a first argument of \'context\'');
            }
        }
        $args = $kwargs = array();
        $unhandled_params = $params;
        foreach ($bits as $bit) {
            // First we try to extract a potential kwarg from the bit
            $bit_ = array($bit);
            $kwarg = DjaBase::tokenKwargs($bit_, $parser);
            unset ($bit_);
            if ($kwarg) {
                // Not until PHP supports keyword arguments %)
            } else {
                if ($kwargs) {
                    throw new TemplateSyntaxError('\'' . $name . '\' received some positional argument(s) after some keyword argument(s)');
                } else {
                    // Record the positional argument
                    $args[] = $parser->compileFilter($bit);
                    try {
                        // Consume from the list of expected positional arguments
                        py_arr_pop($unhandled_params, 0);
                    } catch (IndexError $e) {
                        if ($varargs === null) {
                            throw new TemplateSyntaxError('\'' . $name . '\' received too many positional arguments');
                        }
                    }
                }
            }
        }
        if ($defaults !== null) {
            // Consider the last n params handled, where n is the number of defaults.
            $unhandled_params = py_slice($unhandled_params, null, -count($defaults));
        }
        if ($unhandled_params) {
            // Some positional arguments were not supplied
            $args_ = array();
            foreach ($unhandled_params as $p) {
                $args_ = "'$p'";
            }
            throw new TemplateSyntaxError('\'' . $name . '\' did not receive value(s) for the argument(s): ' . join(', ', $args_));
        }
        return array($args, $kwargs);
    }

    /**
     * Returns a template.Node subclass.
     *
     * @static
     *
     * @param Parser $parser
     * @param Token $token
     * @param array $params
     * @param null $varargs
     * @param null $varkw
     * @param array $defaults
     * @param string $name
     * @param bool|null $takes_context
     * @param string $node_class
     * @param array $node_opts_
     *
     * @return Node|object
     */
    public static function genericTagCompiler($parser, $token, $params, $varargs, $varkw, $defaults, $name, $takes_context, $node_class, $node_opts_) {
        $bits = py_slice($token->splitContents(), 1);
        list($args, $kwargs) = DjaBase::parseBits($parser, $bits, $params, $varargs, $varkw, $defaults, $takes_context, $name);
        py_arr_insert($args, 0, $takes_context);
        /** @var $node_class Node */
        return $node_class::spawn_($args, $node_opts_);
    }

}


class Node implements Iterator {

    // Set this to True for nodes that must be first in the template (although they can be preceded by text nodes.
    public $must_be_first = False;
    public $child_nodelists = array('nodelist');

    /**
     * Spawns a new instance of a Node class.
     *
     * @static
     *
     * @param array $args Arguments to pass to new instance.
     * @param array $fields_opts Fields to set in new instance.
     *
     * @return Node|object
     */
    public static function spawn_($args, $fields_opts) {
        $ro = new ReflectionClass(get_called_class());
        $n = $ro->newInstanceArgs($args);
        foreach ($fields_opts as $k => $v) {
            $n->$k = $v;
        }
        return $n;
    }

    /**
     * @param Context $context
     *
     * @return SafeString|string
     */
    public function render($context) {
        // Return the node rendered as a string.
    }

    /**
     * @param string $nodetype
     *
     * @return array
     */
    public function getNodesByType($nodetype) {
        // Return a list of all nodes (within this node and its nodelist) of the given type
        $nodes = array();
        if ($this instanceof $nodetype) {
            $nodes[] = $this;
        }
        foreach ($this->child_nodelists as $attr) {
            /** @var $nodelist NodeList */
            $nodelist = py_getattr($this, $attr, null);
            if ($nodelist) {
                $nodes = array_merge($nodes, $nodelist->getNodesByType($nodetype));
            }
        }
        return $nodes;
    }

    /* Iterator */
    function rewind() {
    }

    function next() {
    }

    function current() {
        return $this;
    }

    function key() {
        return;
    }

    function valid() {
        return True;
    }

}


/**
 * Base class for tag helper nodes such as SimpleNode, InclusionNode and
 * AssignmentNode. Manages the positional and keyword arguments to be passed
 * to the decorated function.
 */
class TagHelperNode extends Node {

    /**
     * @param bool $takes_context
     */
    public function __construct($takes_context) {
        $this->takes_context = $takes_context;
        $args = func_get_args();
        $this->args = py_slice($args, 1);
    }

    /**
     * @param Context|array $context
     *
     * @return array
     */
    public function getResolvedArguments($context) {
        $resolved_args = array();

        /** @var $var Variable */
        foreach ($this->args as $var) {
            $resolved_args[] = $var->resolve($context);
        }
        if ($this->takes_context) {
            $resolved_args = array_merge($context, $resolved_args);
        }
        $resolved_kwargs = array();
        return array($resolved_args, $resolved_kwargs);
    }

}


class SimpleNode extends TagHelperNode {

    public $vars_to_resolve = array();

    /**
     * @param Context $context
     *
     * @return mixed
     */
    public function render($context) {
        list ($resolved_args, $resolved_kwargs) = $this->getResolvedArguments($context);
        return call_user_func_array($this->func, $resolved_args);
    }

}


class Library {

    public function __construct() {
        $this->filters = array();
        $this->tags = array();
    }

    /**
     * @param string $name
     * @param Closure $compile_function
     *
     * @return Closure
     */
    public function tag($name, $compile_function) {
        $this->tags[$name] = $compile_function;
        return $compile_function;
    }

    /**
     * @param string $name
     * @param Closure $filter_func
     * @param array $flags
     *
     * @return DjaFilterClosure
     */
    public function filter($name, $filter_func, array $flags = array()) {
        $filter_func = new DjaFilterClosure($name, $filter_func);
        $this->filters[$name] = $filter_func;
        foreach (array('expects_localtime', 'is_safe', 'needs_autoescape') as $attr) {
            if (isset($flags[$attr])) {
                $value = $flags[$attr];
                // set the flag on the filter for FilterExpression.resolve
                $filter_func->$attr = $value;
            }
        }
        return $filter_func;
    }

    /**
     * @param string $name
     * @param Closure $func
     * @param null|bool $takes_context
     *
     * @return Closure
     */
    public function simpleTag($name, $func, $takes_context = null) {
        $self_ = $this;
        $dec = function($func) use ($self_, $name, $takes_context) {
            list($params, $varargs, $varkw, $defaults) = py_inspect_getargspec($func);
            py_arr_insert($params, 0, array('parser', 'token'));
            $node_opts_ = array('func' => $func, 'takes_context' => $takes_context);
            $compile_func = function($parser, $token) use ($params, $varargs, $varkw, $defaults, $name, $takes_context, $node_opts_) {
                return DjaBase::genericTagCompiler($parser, $token, $params, $varargs, $varkw, $defaults, $name, $takes_context, 'SimpleNode', $node_opts_);
            };
            $self_->tag($name, $compile_func);
            return $func;
        };
        return $dec($func);
    }


    // TODO assignment_tag()

    // TODO inclusion_tag()

}


class Origin {

    /**
     * @param string $name
     */
    public function __construct($name) {
        $this->name = $name;
    }

    /**
     * @throws BadMethodCallException
     */
    public function reload() {
        throw new BadMethodCallException('Not implemented');
    }

    public function __toString() {
        return $this->name;
    }

}


class StringOrigin extends Origin {

    /**
     * @param array $source
     */
    public function __construct($source) {
        parent::__construct(DjaBase::UNKNOWN_SOURCE);
        $this->source = $source;
    }

    public function reload() {
        return $this->source;
    }

}


class Template implements Iterator {

    private $pos_node = 0;
    private $pos_subnode = 0;
    /** @var NodeList */
    public $nodelist;

    /**
     * @param string $template_string
     * @param null|Origin $origin
     * @param string $name
     */
    public function __construct($template_string, $origin = null, $name = '<Unknown Template>') {
        if (Dja::getSetting('TEMPLATE_DEBUG') && $origin === null) {
            $origin = new StringOrigin($template_string);
        }
        $this->nodelist = DjaBase::compileString($template_string, $origin);
        $this->name = $name;
    }

    /**
     * @param Context $context
     *
     * @return SafeString
     */
    public function render_($context) {
        return $this->nodelist->render($context);
    }

    /**
     * Render can be called many times.
     *
     * @param Context $context
     *
     * @return string
     * @throws Exception
     */
    public function render($context) {
        $context->render_context->push();

        // $r_ is a workaround for PHP missing `finally`.
        try {
            $r_ = $this->render_($context);
        } catch (Exception $e) {
            $r_ = $e;
        }
        $context->render_context->pop();

        if ($r_ instanceof Exception) {
            throw $r_;
        }
        return (string)$r_;
    }


    // Iterator implementation: go through all subnodes in all nodes.

    function rewind() {
        $this->pos_node = 0;
        $this->pos_subnode = 0;
    }

    function current() {
        return $this->nodelist[$this->pos_node][$this->pos_subnode];
    }

    function key() {
        return array($this->pos_node, $this->pos_subnode);
    }

    function next() {
        $next_node_exists = isset($this->nodelist[$this->pos_node + 1]);
        $next_subnode_exists = isset($this->nodelist[$this->pos_node][$this->pos_subnode]);

        if ($next_subnode_exists) {
            $this->pos_subnode++;
            return;
        } else {
            if ($next_node_exists) {
                $this->pos_node++;
                $this->pos_subnode = 0;
                return;
            }
            $this->pos_node++;
            return;
        }
    }

    function valid() {
        return (isset($this->nodelist[$this->pos_node]) && isset($this->nodelist[$this->pos_node][$this->pos_subnode]));
    }

}


class Token {

    public $lineno;

    /**
     * @param string $token_type
     * @param string $contents
     */
    public function __construct($token_type, $contents) {
        // token_type must be TOKEN_TEXT, TOKEN_VAR, TOKEN_BLOCK or TOKEN_COMMENT.
        $this->token_type = $token_type;
        $this->contents = $contents;
        $this->lineno = null;
    }

    public function __toString() {
        $token_name = DjaBase::$TOKEN_MAPPING[$this->token_type];
        return sprintf('<%s token: "%s...">', array($token_name, str_replace("\n", '', substr($this->contents, 0, 20))));
    }

    /**
     * @return array
     */
    public function splitContents() {
        $split = array();
        $bits = smart_split($this->contents);

        foreach ($bits as $bit) {
            // Handle translation-marked template pieces.
            if (py_str_starts_with($bit, '_("') || py_str_starts_with($bit, "_('")) {
                $sentinal = $bit[2] . ')';
                $trans_bit = array($bit);
                while (!py_str_ends_with($bit, $sentinal)) {
                    $bit = next($bits);
                    $trans_bit[] = $bit;
                }
                $bit = join(' ', $trans_bit);
            }
            $split[] = $bit;
        }
        return $split;
    }

}


class Lexer {

    /**
     * @param string $template_string
     * @param Origin $origin
     */
    public function __construct($template_string, $origin) {
        $this->template_string = $template_string;
        $this->origin = $origin;
        $this->lineno = 1;
    }

    /**
     * Return a list of tokens from a given template_string.
     *
     * @return array
     */
    public function tokenize() {
        $in_tag = False;
        $result = array();
        foreach (preg_split(DjaBase::getReTag(), $this->template_string, -1, PREG_SPLIT_DELIM_CAPTURE) as $bit) {
            if ($bit) {
                $result[] = $this->createToken($bit, $in_tag);
            }
            $in_tag = !$in_tag;
        }
        return $result;
    }

    /**
     * Convert the given token string into a new Token object and return it.
     * If in_tag is True, we are processing something that matched a tag,
     * otherwise it should be treated as a literal string.
     *
     * @param string $token_string
     * @param bool $in_tag
     *
     * @return Token
     */
    public function createToken($token_string, $in_tag) {
        if ($in_tag) {
            if (py_str_starts_with($token_string, DjaBase::VARIABLE_TAG_START)) {
                $token = new Token(DjaBase::TOKEN_VAR, trim(py_slice($token_string, 2, -2)));
            } elseif (py_str_starts_with($token_string, DjaBase::BLOCK_TAG_START)) {
                $token = new Token(DjaBase::TOKEN_BLOCK, trim(py_slice($token_string, 2, -2)));
            } elseif (py_str_starts_with($token_string, DjaBase::COMMENT_TAG_START)) {
                $content = '';
                if (strpos($token_string, DjaBase::TRANSLATOR_COMMENT_MARK) !== False) {
                    $content = trim(py_slice($token_string, 2, -2));
                }
                $token = new Token(DjaBase::TOKEN_COMMENT, $content);
            }
        } else {
            $token = new Token(DjaBase::TOKEN_TEXT, $token_string);
        }
        /** @var $token Token  */
        $token->lineno = $this->lineno;
        $this->lineno += substr_count($token_string, "\n");
        return $token;

    }

}


class Parser {

    /**
     * @param array $tokens
     */
    public function __construct($tokens) {
        $this->tokens = $tokens;
        $this->tags = array();
        $this->filters = array();
        foreach (DjaBase::$builtins as $lib) {
            $this->addLibrary($lib);
        }
    }

    /**
     * @param null|array $parse_until
     *
     * @return NodeList
     * @throws TemplateSyntaxError
     */
    public function parse($parse_until = null) {
        if ($parse_until === null) {
            $parse_until = array();
        }
        $nodelist = $this->createNodelist();

        while ($this->tokens) {
            $token = $this->nextToken();

            // Use the raw values here for TOKEN_* for a tiny performance boost.
            if ($token->token_type == 0) {
                // TOKEN_TEXT
                $this->extendNodelist($nodelist, new TextNode($token->contents), $token);
            } elseif ($token->token_type == 1) {
                // TOKEN_VAR
                if (!$token->contents) {
                    $this->emptyVariable($token);
                }
                $filter_expression = $this->compileFilter($token->contents);
                $var_node = $this->createVariableNode($filter_expression);
                $this->extendNodelist($nodelist, $var_node, $token);
            } elseif ($token->token_type == 2) {
                // TOKEN_BLOCK
                $command = null;
                $command_ = py_str_split($token->contents);
                if (isset($command_[0])) {
                    $command = $command_[0];
                } else {
                    $this->emptyBlockTag($token);
                }
                unset($command_);

                if (in_array($command, $parse_until)) {
                    // Put token back on token list so calling code knows why it terminated.
                    $this->prependToken($token);
                    return $nodelist;
                }
                // Execute callback function for this tag and append resulting node.
                $this->enterCommand($command, $token);

                if (isset($this->tags[$command])) {
                    /** @var $compile_func Closure */
                    $compile_func = $this->tags[$command];
                } else {
                    $this->invalidBlockTag($token, $command, $parse_until);
                }

                try {
                    /** @var $compiled_result Node */
                    $compiled_result = $compile_func($this, $token);
                } catch (TemplateSyntaxError $e) {
                    if (!$this->compileFunctionError($token, $e)) {
                        throw $e;
                    }
                }
                $this->extendNodelist($nodelist, $compiled_result, $token);
                $this->exitCommand();
            }

        }

        if ($parse_until) {
            $this->unclosedBlockTag($parse_until);
        }
        return $nodelist;
    }

    /**
     * @param string $endtag
     */
    public function skipPast($endtag) {

        while ($this->tokens) {
            $token = $this->nextToken();
            if ($token->token_type == DjaBase::TOKEN_BLOCK and $token->contents == $endtag) {
                return;
            }
        }
        $this->unclosedBlockTag(array($endtag));
    }

    /**
     * @param FilterExpression $filter_expression
     *
     * @return VariableNode
     */
    public function createVariableNode($filter_expression) {
        return new VariableNode($filter_expression);
    }

    /**
     * @return NodeList
     */
    public function createNodelist() {
        return new NodeList();
    }

    /**
     * @param NodeList $nodelist
     * @param Node $node
     * @param Token $token
     *
     * @throws TemplateSyntaxError
     */
    public function extendNodelist(&$nodelist, $node, $token) {
        if ($node->must_be_first && $nodelist) {
            if ($nodelist->contains_nontext) {
                throw new TemplateSyntaxError(sprintf('%r must be the first tag in the template.', array($node)));
            }
        }
        if (($nodelist instanceof NodeList) && !($node instanceof TextNode)) {
            $nodelist->contains_nontext = True;
        }
        $nodelist[] = $node;
    }

    /**
     * @param string $command
     * @param Token $token
     */
    public function enterCommand($command, $token) {
    }

    public function exitCommand() {
    }

    /**
     * @param Token|null $token
     * @param string $msg
     *
     * @return TemplateSyntaxError
     */
    public function error($token, $msg) {
        return new TemplateSyntaxError($msg);
    }

    /**
     * @param Token $token
     *
     * @throws TemplateSyntaxError
     */
    public function emptyVariable($token) {
        $error = $this->error($token, 'Empty variable tag');
        throw $error;
    }

    /**
     * @param Token $token
     *
     * @throws TemplateSyntaxError
     */
    public function emptyBlockTag($token) {
        $error = $this->error($token, 'Empty block tag');
        throw $error;
    }

    /**
     * @param Token $token
     * @param string $command
     * @param null|array $parse_until
     *
     * @throws TemplateSyntaxError
     */
    public function invalidBlockTag($token, $command, $parse_until = null) {
        if ($parse_until) {
            $quote = function ($item) {
                return "'$item'";
            };
            $error = $this->error($token, 'Invalid block tag: \'' . $command . '\', expected ' . get_text_list(array_map($quote, $parse_until)));
            throw $error;
        }
        $error = $this->error($token, 'Invalid block tag: \'' . $command . '\'');
        throw $error;
    }

    /**
     * @param null|array $parse_until
     *
     * @throws TemplateSyntaxError
     */
    public function unclosedBlockTag($parse_until) {
        $error = $this->error(null, 'Unclosed tags: ' . join(', ', $parse_until));
        throw $error;
    }

    /**
     * @param Token $token
     * @param DjaException $e
     */
    public function compileFunctionError($token, $e) {
    }

    public function nextToken() {
        return py_arr_pop($this->tokens, 0);
    }

    /**
     * @param Token $token
     */
    public function prependToken($token) {
        py_arr_insert($this->tokens, 0, $token);
    }

    public function deleteFirstToken() {
        unset($this->tokens[0]);
    }

    /**
     * @param Library $lib
     */
    public function addLibrary($lib) {
        $this->tags = array_merge($this->tags, $lib->tags);
        $this->filters = array_merge($this->filters, $lib->filters);
    }

    /**
     * @param Token|string $token
     *
     * @return FilterExpression
     */
    public function compileFilter($token) {
        // Convenient wrapper for FilterExpression
        return new FilterExpression($token, $this);
    }

    /**
     * @param string $filter_name
     *
     * @return DjaFilterClosure
     * @throws TemplateSyntaxError
     */
    public function findFilter($filter_name) {
        if (isset($this->filters[$filter_name])) {
            return $this->filters[$filter_name];
        } else {
            throw new TemplateSyntaxError('Invalid filter: \'' . $filter_name . '\'');
        }
    }

}


/**
 * Subclass this and implement the top() method to parse a template line.
 * When instantiating the parser, pass in the line from the Django template parser.
 *
 * The parser's "tagname" instance-variable stores the name of the tag that
 * the filter was called with.
 */
class TokenParser {

    /**
     * @param string $subject
     */
    public function __construct($subject) {
        $this->subject = $subject;
        $this->pointer = 0;
        $this->backout = array();
        $this->tagname = $this->tag();
    }

    /**
     * @throws BadMethodCallException
     */
    public function top() {
        // Overload this method to do the actual parsing and return the result.
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @return bool
     */
    public function more() {
        // Returns True if there is more stuff in the tag.
        return ($this->pointer < strlen($this->subject));
    }

    /**
     * @throws TemplateSyntaxError
     */
    public function back() {
        // Undoes the last microparser. Use this for lookahead and backtracking.
        if (!count($this->backout)) {
            throw new TemplateSyntaxError('back called without some previous parsing');
        }
        $this->pointer = py_arr_pop($this->backout);
    }

    /**
     * @return array|string
     * @throws TemplateSyntaxError
     */
    public function tag() {
        // A microparser that just returns the next tag from the line.
        $subject = $this->subject;
        $subject_length = strlen($subject);
        $i = $this->pointer;

        if ($i >= $subject_length) {
            throw new TemplateSyntaxError('expected another tag, found end of string: ' . $subject);
        }
        $p = $i;

        while ($i < $subject_length && !in_array($subject[$i], array(' ', "\t"))) {
            $i += 1;
        }

        $s = py_slice($subject, $p, $i);
        while ($i < $subject_length && in_array($subject[$i], array(' ', "\t"))) {
            $i += 1;
        }
        $this->backout[] = $this->pointer;
        $this->pointer = $i;
        return $s;
    }

    /**
     * @param string $subject
     * @param int $i
     *
     * @return int
     * @throws TemplateSyntaxError
     */
    private function nextSpaceIndex($subject, $i) {
        // Increment pointer until a real space (i.e. a space not within quotes) is encountered
        $subject_length = strlen($subject);
        while ($i < $subject_length && !in_array($subject[$i], array(' ', "\t"))) {
            if (in_array($subject[$i], array('"', "'"))) {
                $c = $subject[$i];
                $i += 1;
                while ($i < $subject_length && $subject[$i] != $c) {
                    $i += 1;
                }
                if ($i >= $subject_length) {
                    throw new TemplateSyntaxError('Searching for value. Unexpected end of string in column ' . $i . ': ' . $subject);
                }
            }
            $i += 1;
        }
        return $i;
    }

    /**
     * @return array|string
     * @throws TemplateSyntaxError
     */
    public function value() {
        // A microparser that parses for a value: some string constant or variable name.
        $subject = $this->subject;
        $subject_length = strlen($subject);
        $i = $this->pointer;

        if ($i >= $subject_length) {
            throw new TemplateSyntaxError('Searching for value. Expected another value but found end of string: ' . $subject);
        }

        if (in_array($subject[$i], array('"', "'"))) {
            $p = $i;
            $i += 1;
            while ($i < $subject_length && $subject[$i] != $subject[$p]) {
                $i += 1;
            }

            if ($i >= $subject_length) {
                throw new TemplateSyntaxError('Searching for value. Unexpected end of string in column ' . $i . ': ' . $subject);
            }

            $i += 1;

            // Continue parsing until next "real" space, so that filters are also included.
            $i = $this->nextSpaceIndex($subject, $i);

            $res = py_slice($subject, $p, $i);
            while ($i < $subject_length && in_array($subject[$i], array(' ', "\t"))) {
                $i += 1;
            }
            $this->backout[] = $this->pointer;
            $this->pointer = $i;
            return $res;
        } else {
            $p = $i;
            $i = $this->nextSpaceIndex($subject, $i);
            $s = py_slice($subject, $p, $i);
            while ($i < $subject_length && in_array($subject[$i], array(' ', "\t"))) {
                $i += 1;
            }
            $this->backout[] = $this->pointer;
            $this->pointer = $i;
            return $s;
        }
    }

}


class NodeList implements Iterator, ArrayAccess {

    // Set to True the first time a non-TextNode is inserted by extend_nodelist().
    public $contains_nontext = False;
    private $nodes = array();
    private $iter_pos = 0;

    /**
     * @param null|array $nodes
     */
    public function __construct($nodes = null) {
        if ($nodes !== null) {
            $this->nodes = $nodes;
        }
    }

    /**
     * @param Context $context
     *
     * @return SafeString
     */
    public function render($context) {
        $bits = array();
        foreach ($this->nodes as $node) {
            if ($node instanceof Node) {
                $bit = $this->renderNode($node, $context);
            } else {
                $bit = $node;
            }
            $bits[] = $bit;
        }
        return mark_safe(join('', $bits));
    }

    /**
     * @param string $nodetype
     *
     * @return array
     */
    public function getNodesByType($nodetype) {
        // Return a list of all nodes of the given type.
        $nodes = array();
        /** @var $node Node */
        foreach ($this->nodes as $node) {
            $nodes = array_merge_recursive($nodes, $node->getNodesByType($nodetype));
        }
        return $nodes;
    }

    /**
     * @param Node $node
     * @param Context $context
     *
     * @return SafeString|string
     */
    public function renderNode($node, $context) {
        return $node->render($context);
    }


    /* Array Access */

    public function offsetSet($idx, $val) {
        if ($idx === null) {
            $this->nodes[] = $val;
        } else {
            $this->nodes[$idx] = $val;
        }
    }

    public function offsetExists($idx) {
        return isset($this->nodes[$this->iter_pos]);
    }

    public function offsetUnset($idx) {
        unset($this->nodes[$idx]);
    }

    public function offsetGet($idx) {
        return isset($this->nodes[$idx]) ? $this->nodes[$idx] : null;
    }

    /* Iterators */
    function rewind() {
        $this->iter_pos = 0;
    }

    function next() {
        $this->iter_pos++;
    }

    function current() {
        return $this->nodes[$this->iter_pos];
    }

    function key() {
        return $this->iter_pos;
    }

    function valid() {
        return $this->offsetExists($this->iter_pos);
    }

}


class TextNode extends Node {

    /**
     * @param string $s
     */
    public function __construct($s) {
        $this->s = $s;
    }

    public function __toString() {
        return "<Text Node: '" . substr($this->s, 0, 25) . "'>";
    }

    /**
     * @param Context $context
     *
     * @return SafeString|string
     */
    public function render($context) {
        return $this->s;
    }
}


class VariableNode extends Node {

    /**
     * @var FilterExpression
     */
    public $filter_expression = null;

    /**
     * @param FilterExpression $filter_expression
     */
    public function __construct($filter_expression) {
        $this->filter_expression = $filter_expression;
    }

    public function __toString() {
        return "<Variable Node: '" . $this->filter_expression . "'>";
    }

    /**
     * @param Context $context
     *
     * @return SafeString|string
     */
    public function render($context) {
        $output = $this->filter_expression->resolve($context);
        return DjaBase::renderValueInContext($output, $context);
    }

}


/**
 * A template variable, resolvable against a given context. The variable may
 * be a hard-coded string (if it begins and ends with single or double quote
 * marks)::
 *
 * >>> c = {'article': {'section':u'News'}}
 * >>> Variable('article.section').resolve(c)
 * u'News'
 * >>> Variable('article').resolve(c)
 * {'section': u'News'}
 * >>> class AClass: pass
 * >>> c = AClass()
 * >>> c.article = AClass()
 * >>> c.article.section = u'News'
 *
 * (The example assumes VARIABLE_ATTRIBUTE_SEPARATOR is '.')
 */
class Variable {

    /**
     * @param string $var
     * @throws TemplateSyntaxError
     */
    public function __construct($var) {
        $this->var = $var;
        $this->literal = null;
        $this->lookups = null;
        $this->translate = False;
        $this->message_context = null;

        try {
            // First try to treat this variable as a number.
            if (!is_numeric($var)) {
                throw new ValueError();
            }

            $this->literal = (float)$var;

            /*
             * So it's a float... is it an int? If the original value contained a
             * dot or an "e" then it was a float, not an int.
             */
            if (strpos($var, '.') === False && strpos(strtolower($var), 'e') === False) {
                $this->literal = (int)$this->literal;
            }

            // "2." is invalid
            if (py_str_ends_with($var, '.')) {
                throw new ValueError();
            }

        } catch (ValueError $e) {
            // A ValueError means that the variable isn't a number.
            if (py_str_starts_with($var, '_(') && py_str_ends_with($var, ')')) {
                // The result of the lookup should be translated at rendering time.
                $this->translate = True;
                $var = py_slice($var, 2, -1);
            }
            // If it's wrapped with quotes (single or double), then we're also dealing with a literal.
            try {
                $this->literal = mark_safe(unescape_string_literal($var));
            } catch (ValueError $e) {
                // Otherwise we'll set self.lookups so that resolve() knows we're dealing with a bonafide variable
                if (strpos($var, DjaBase::VARIABLE_ATTRIBUTE_SEPARATOR . '_') !== False || $var[0] == '_') {
                    throw new TemplateSyntaxError('Variables and attributes may not begin with underscores: \'' . $var . '\'');
                }
                $this->lookups = explode(DjaBase::VARIABLE_ATTRIBUTE_SEPARATOR, $var);
            }
        }
    }

    /**
     * Resolve this variable against a given context.
     *
     * @param Context|array $context
     *
     * @return mixed
     */
    public function resolve($context) {
        if ($this->lookups !== null) {
            // We're dealing with a variable that needs to be resolved
            $value = $this->resolveLookup($context);
        } else {
            // We're dealing with a literal, so it's already been "resolved"
            $value = $this->literal;
        }
        if ($this->translate) {
            if ($this->message_context) {
                return Dja::getI18n()->pgettext($this->message_context, $value);
            } else {
                return Dja::getI18n()->ugettext($value);
            }
        }
        return $value;
    }

    public function __toString() {
        return $this->var;
    }

    /**
     * Performs resolution of a real variable (i.e. not a literal) against the
     * given context.
     *
     * As indicated by the method's name, this method is an implementation
     * detail and shouldn't be called by external code. Use Variable.resolve() instead.
     *
     * @param Context $context
     *
     * @return PyLazyMethod|string
     * @throws AttributeError|Exception|TypeError|KeyError|VariableDoesNotExist
     */
    private function resolveLookup($context) {
        $current = $context;
        try { // catch-all for silent variable failures

            foreach ($this->lookups as $bit) {
                try { // dictionary lookup
                    if (!($current instanceof ArrayAccess) && !is_array($current) && !is_string($current)) {
                        throw new TypeError(); // unsubscriptable object
                    }
                    if (isset($current[$bit]) || array_key_exists($bit, $current)) {
                        $current = $current[$bit];
                    } else {
                        throw new KeyError();
                    }
                } catch (Exception $e) { // TypeError, AttributeError, KeyError
                    try { // attribute lookup
                        $current = py_getattr($current, $bit);
                    } catch (AttributeError $e) { // TypeError, AttributeError
                        try { // list-index lookup
                            if (!($current instanceof ArrayAccess)) {
                                throw new TypeError(); // unsubscriptable object
                            }
                            $current = $current[(int)$bit];
                        } catch (Exception $e) { // unsubscriptable object
                            /*
                             * IndexError - list index out of range
                             * ValueError - invalid literal for int()
                             * KeyError - current is a dict without `int(bit)` key
                             * TypeError - unsubscriptable object
                             */
                            throw new VariableDoesNotExist('Failed lookup for key [' . $bit . '] in ' . print_r($current, True)); // missing attribute
                        }
                    }
                }

                if (is_callable($current)) {
                    if (py_getattr($current, 'do_not_call_in_templates', False)) {
                    } elseif (py_getattr($current, 'alters_data', False)) {
                        $current = Dja::getSetting('TEMPLATE_STRING_IF_INVALID');
                    } else {
                        try { // method call (assuming no args required)
                            $current = $current();
                        } catch (TypeError $e) { // arguments *were* required  // TypeError
                            // GOTCHA: This will also catch any TypeError raised in the function itself.
                            $current = Dja::getSetting('TEMPLATE_STRING_IF_INVALID'); // invalid method call
                        }
                    }
                }
            }

        } catch (Exception $e) {
            if (py_getattr($e, 'silent_variable_failure', False)) {
                $current = Dja::getSetting('TEMPLATE_STRING_IF_INVALID');
            } else {
                throw $e;
            }
        }

        return $current;
    }

}


class FilterExpression {

    /**
     * @var null|Variable
     */
    public $var = null;

    /**
     * Parses a variable token and its optional filters (all as a single string),
     * and return a list of tuples of the filter name and arguments.
     * Sample::
     *
     * >>> token = 'variable|default:"Default value"|date:"Y-m-d"'
     * >>> p = Parser('')
     * >>> fe = FilterExpression(token, p)
     * >>> len(fe.filters)
     * 2
     * >>> fe.var
     * <Variable: 'variable'>
     *
     * This class should never be instantiated outside of the
     * get_filters_from_token helper function.
     *
     * @param Token $token
     * @param Parser $parser
     * @throws TemplateSyntaxError
     */
    public function __construct($token, $parser) {
        $this->token = $token;

        $matches = new PyReFinditer(DjaBase::getReFilter(), $token);
        $var_obj = null;
        $filters = array();
        $upto = 0;
        /** @var $match pyReMatchObject */
        foreach ($matches as $match) {
            $start = $match->start();

            if ($upto != $start) {
                throw new TemplateSyntaxError('Could not parse some characters: ' . py_slice($token, null, $upto) . '|' . py_slice($token, $upto, $start) . '|' . py_slice($token, $start) . '');
            }

            if ($var_obj === null) {
                list($var, $constant) = $match->group(array('var', 'constant'));
                if ($constant) {
                    try {
                        $var_obj = new Variable($constant);
                        $var_obj = $var_obj->resolve(array());
                    } catch (VariableDoesNotExist $e) {
                        $var_obj = null;
                    }
                } elseif ($var === null) {
                    throw new TemplateSyntaxError('Could not find variable at start of ' . $token . '.');
                } else {
                    $var_obj = new Variable($var);
                }
            } else {
                $filter_name = $match->group('filter_name');
                $args = array();
                list($constant_arg, $var_arg) = $match->group(array('constant_arg', 'var_arg'));
                if (trim($constant_arg) != '') {
                    $tmp = new Variable($constant_arg);
                    $tmp = $tmp->resolve(array());
                    $args[] = array(False, $tmp);
                    unset($tmp);
                } elseif (trim($var_arg) != '') {
                    $args[] = array(True, new Variable($var_arg));
                }
                $filter_func = $parser->findFilter($filter_name);
                self::argsCheck($filter_name, $filter_func->closure, $args);
                $filters[] = array($filter_func, $args, $filter_name); // Deliberately add filter name as the third element (used in `filter` tag).
            }
            $upto = $match->end();
        }

        if ($upto != mb_strlen($token, 'utf-8')) {
            throw new TemplateSyntaxError("Could not parse the remainder: '" . py_slice($token, $upto) . "' from '" . $token . "'");
        }

        $this->filters = $filters;
        $this->var = $var_obj;
    }

    /**
     * @param Context $context
     * @param bool $ignore_failures
     *
     * @return mixed
     */
    public function resolve($context, $ignore_failures = False) {
        if ($this->var instanceof Variable) {
            try {
                $obj = $this->var->resolve($context);
            } catch (VariableDoesNotExist $e) {
                if ($ignore_failures) {
                    $obj = null;
                } else {
                    if (Dja::getSetting('TEMPLATE_STRING_IF_INVALID')) {
                        if (DjaBase::$invalid_var_format_string === null) {
                            DjaBase::$invalid_var_format_string = (strpos(Dja::getSetting('TEMPLATE_STRING_IF_INVALID'), '%s') !== False);
                        }
                        if (DjaBase::$invalid_var_format_string) {
                            return sprintf(Dja::getSetting('TEMPLATE_STRING_IF_INVALID'), $this->var);
                        }
                        return Dja::getSetting('TEMPLATE_STRING_IF_INVALID');
                    } else {
                        $obj = Dja::getSetting('TEMPLATE_STRING_IF_INVALID');
                    }
                }
            }
        } else {
            $obj = $this->var;
        }
        foreach ($this->filters as $filter) {
            list($func, $args, $n_) = $filter;
            $arg_vals = array();
            foreach ($args as $arg_data) {
                /** @var $arg Variable|string */
                list($lookup, $arg) = $arg_data;
                if (!$lookup) {
                    $arg_vals[] = mark_safe($arg);
                } else {
                    $arg_vals[] = $arg->resolve($context);
                }
            }

            if (py_getattr($func, 'expects_localtime', False)) {
                $obj = localtime($obj, $context->use_tz);
            }

            $func_ = $func->closure;
            if (py_getattr($func, 'needs_autoescape', False)) {
                $new_obj = call_user_func_array($func_, array_merge(array($obj, $context->autoescape), $arg_vals));
            } else {
                $new_obj = call_user_func_array($func_, array_merge(array($obj), $arg_vals));
            }

            if (py_getattr($func, 'is_safe', False) && ($obj instanceof SafeData)) {
                $obj = mark_safe($new_obj);
            } else {
                if ($obj instanceof EscapeData) {
                    $obj = mark_for_escaping($new_obj);
                } else {
                    $obj = $new_obj;
                }
            }
        }
        return $obj;
    }

    /**
     * @static
     *
     * @param string $name
     * @param Closure $func
     * @param null|array $provided
     *
     * @return bool
     * @throws TemplateSyntaxError
     */
    public static function argsCheck($name, $func, $provided) {
        if (!is_array($provided)) {
            $provided = array($provided);
        }
        $plen = count($provided);

        list($args, $varargs, $varkw, $defaults) = py_inspect_getargspec($func);

        // First argument is filter input.
        py_arr_pop($args, 0);

        if ($defaults) {
            $nondefs = py_slice($args, null, -(count($defaults)));
        } else {
            $nondefs = $args;
        }
        // Args without defaults must be provided.
        try {
            foreach ($nondefs as $arg) {
                py_arr_pop($provided, 0);
            }
        } catch (IndexError $e) {
            // Not enough
            throw new TemplateSyntaxError($name . ' requires ' . count($nondefs) . ' arguments, ' . $plen . ' provided');
        }

        // Defaults can be overridden.
        try {
            foreach ($provided as $parg) {
                py_arr_pop($defaults, 0);
            }
        } catch (IndexError $e) {
            // Too many.
            throw new TemplateSyntaxError($name . ' requires ' . count($nondefs) . ' arguments, ' . $plen . ' provided');
        }
        return True;
    }

    public function __toString() {
        return $this->token;
    }
}
