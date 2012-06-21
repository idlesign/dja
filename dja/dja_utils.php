<?php


/**
 * Template filter closures wrapper.
 *
 * Allows filter closures delayed execution, and serialization,
 * thus making Template objects serializable too.
 */
class DjaFilterClosure {

    public $closure = null;
    private $_flt_name = null;
    private $_clos = null;

    public function __construct($filter_name, $closure) {
        $this->closure = $closure;
        $this->_flt_name = $filter_name;
    }

    /**
     * NOTE that we do not actually serialize closures, but rather save
     * filter function identifier to get an appropriate closure
     * from imported modules on wakeup.
     *
     * @return array
     */
    public function __sleep() {
        $this->_clos = null;
        if ($this->closure instanceof Closure) {
            $this->_clos = $this->_flt_name;

        }
        return array('_clos');
    }

    public function __wakeup() {
        if ($this->_clos!==null) {
            $this->closure = dja_find_filter_closure($this->_clos);
        }
    }

}


/**
 * Interface for custom Dja URL Dispatcher class to implement.
 */
interface IDjaUrlDispatcher {
    /**
     * Resolvers URL from view alias and arguments.
     *
     * Used to reverse URLs with `url` tag in templates.
     * Should throw UrlNoReverseMatch if no match found.
     *
     * @abstract
     * @param string $viewname  View alias (corresponds to view name or path in Django).
     * @param null $urlconf
     * @param null|array $args  Positional arguments.
     * @param null|array $kwargs  Keyword arguments.
     * @param null $prefix
     * @param null $current_app
     * @return string
     * @throws UrlNoReverseMatch
     */
    public function reverse($viewname, $urlconf=null, $args=null, $kwargs=null, $prefix=null, $current_app=null);
}


/**
 * Default Dja URL Dispatcher.
 */
class DjaUrlDispatcher implements IDjaUrlDispatcher {

    /**
     * Array with  URL patter to alias relations.
     *
     * @var array
     */
    private $_rules = array();

    /**
     * This URL Dispatcher roughly mimics Django URL dispatcher.
     * It operates over a set of rules - URL Patterns (regular expressions) to URL alias mappings.
     *
     * @param array|null $rules
     */
    public function __construct(array $rules=null) {
        if ($rules!==null) {
            $this->setRules($rules);
        }
    }

    /**
     * Sets dispatcher rules: an array of URL Patterns (regular expressions) to URL alias mappings.
     * Example:
     *
     * array(
     *     '~^/article/(?P<user_id>\d+)/(?P<slug>[^/]+)/$~' => 'user_article',
     *     '~^/client/(\d+)/$~' => 'client_details',
     * );
     *
     *
     * @param array $rules
     */
    public function setRules(array $rules) {
        $this->_rules = $rules;
    }

    /**
     * Converts URL pattern (regular expression) into URL.
     *
     * @param string $url_pattern URL  Regexp.
     * @param array $args_from_pattern  Argument placeholders found in URL Regexp.
     * @param array $context  Data to populate url pattern with.
     * @return mixed|string
     */
    private function populatePattern($url_pattern, $args_from_pattern, $context) {
        $populated = trim($url_pattern, $url_pattern[0]);
        $populated = trim($populated, '^$');

        foreach ($args_from_pattern as $name=>$re_sub) {
            $populated = substr_replace($populated, $context[$name], strpos($populated, $re_sub), strlen($re_sub));
            $populated = str_replace($re_sub, $context[$name], $populated);
        }

        return $populated;
    }

    public function reverse($viewname, $urlconf=null, $args=null, $kwargs=null, $prefix=null, $current_app=null) {
        $indexes = array_keys($this->_rules, $viewname);
        $re_params = '~(?P<ngroup>\((\?P<(?P<name>[^>]+)>)?[^)]+\))~';
        foreach ($indexes as $url_pattern) {
            $matched = preg_match_all($re_params, $url_pattern, $matches, PREG_SET_ORDER);
            if ($matched!==false) {
                $args_from_pattern = array();
                foreach ($matches as $match) {
                    if (isset($match['name'])) {
                        $args_from_pattern[$match['name']] = $match['ngroup'];
                    } else {
                        $args_from_pattern[] = $match['ngroup'];
                    }
                }

                $unknown_args = array_diff(array_keys($args_from_pattern), array_keys($kwargs));
                if (count($kwargs)==$matched && empty($unknown_args)) {
                    return iri_to_uri($this->populatePattern($url_pattern, $args_from_pattern, $kwargs));
                } elseif (count($args)==$matched) {
                    return iri_to_uri($this->populatePattern($url_pattern, array_values($args_from_pattern), $args));
                }

            }
        }

        $kwargs_flat = array();
        foreach ($kwargs as $k=>$v) {
            $kwargs_flat[] = $k . '=' . $v;
        }
        throw new UrlNoReverseMatch(sprintf('Reverse for \'%s\' with arguments \'%s\' and keyword arguments \'%s\' not found.', $viewname, join(', ', $args), join(', ', $kwargs_flat)));
    }
}


/**
 * Returns filter closure from previously imported Library module
 * by filter name.
 *
 * @param string $name
 * @return mixed
 * @throws DjaException
 */
function dja_find_filter_closure($name) {
    foreach ($GLOBALS['DJA_IMPORTED_MODULES'] as $n=>$content) {
        if (isset($content->filters[$name])) {
            return $content->filters[$name]->closure;
        }
    }
    throw new DjaException('Unable to find \'' . $name .'\' filter required for unserialized template rendering.');
}


class EscapeData {

}

// A string that should be HTML-escaped when output.
class EscapeString extends EscapeData {

    public function __construct($obj) {
        $this->_obj = $obj;
    }

    public function __toString() {
        return (string)$this->_obj;
    }

}


class SafeData {

}


/*
 * A string subclass that has been specifically marked as "safe" (requires no
 * further escaping) for HTML output purposes.
 */
class SafeString extends SafeData {

    public function __construct($obj) {
        $this->_obj = $obj;
    }

    public function get() {
        return $this->_obj;
    }

    public function __toString() {
        return (string)$this->_obj;
    }

}


function activate($lang) {
    // TODO Implement generic language switching interface.
    return True;
}

function deactivate() {
    // TODO Implement generic language switching interface.
    return True;
}


function ugettext($message) {
    // TODO Implement generic translation interface.
    return $message;
}


function ugettext_lazy($message) {
    // TODO Implement generic translation interface.
    return ugettext($message);
}


function pgettext($context, $message) {
    // TODO Implement generic translation interface.
    return $message;
}


function pgettext_lazy($context, $message) {
    // TODO Implement generic translation interface.
    return pgettext($context, $message);
}


/**
 * Formats a datetime.date or datetime.datetime object using a
 * localizable format
 *
 * If use_l10n is provided and is not None, that will force the value to
 * be localized (or not), overriding the value of settings.USE_L10N.
 *
 * @param $value
 * @param null $format
 * @param null $use_l10n
 *
 * @return string
 */
function dja_date_format($value, $format = null, $use_l10n = null) {
    if ($format === null) {
        $format = 'DATE_FORMAT';
    }

    if ($use_l10n || ($use_l10n === null && Dja::getSetting('USE_L10N'))) {
        $lang = Dja::getSetting('LANGUAGE_CODE');
        $ft = new IntlDateFormatter($lang, IntlDateFormatter::FULL, IntlDateFormatter::FULL);
        return $ft->format($value);
    } elseif ($format=='DATE_FORMAT') {
        $format = Dja::getSetting('DATE_FORMAT');
    } elseif ($format=='SHORT_DATE_FORMAT') {
        $format = Dja::getSetting('SHORT_DATE_FORMAT');
    }

    // Mimic Django.
    $format = str_replace(array('N', 'e'), array('M.', 'O'), $format);

    return date($format, $value);
}


/**
 * Formats a date according to the given format.
 *
 * @param $value
 * @param null $arg
 *
 * @return string
 */
function dja_date($value, $arg = null) {

    if (!$value || !is_numeric($value)) {
        return '';
    }
    if ($arg === null) {
        $arg = Dja::getSetting('DATE_FORMAT');
    }

    return dja_date_format($value, $arg);
}


/**
 * Returns the given HTML with spaces between tags removed.
 *
 * @param $value
 *
 * @return string
 */
function strip_spaces_between_tags($value) {
    return preg_replace('~>\s+<~', '><', $value);
}


/*
 * Hex encodes characters for use in JavaScript strings.
 */
function escapejs($value) {
    $_base_js_escapes = array(
        array('\\', '\u005C'),
        array('\'', '\u0027'),
        array('"', '\u0022'),
        array('>', '\u003E'),
        array('<', '\u003C'),
        array('&', '\u0026'),
        array('=', '\u003D'),
        array('-', '\u002D'),
        array(';', '\u003B'),
        array('\u2028', '\u2028'),
        array('\u2029', '\u2029')
    );

    // Escape every ASCII character with a value less than 32.
    $_js_escapes = $_base_js_escapes;  // TODO Implement.
    foreach (range(0, 31) as $z) {
        $_js_escapes[] = array(sprintf('%c', $z), sprintf('\\u%04X', $z));
    }

    foreach ($_js_escapes as $itm_) {
        list($bad, $good) = $itm_;
        $value = mark_safe(str_replace($bad, $good, $value));
    }
    return $value;
}


function smart_split($text) {
    // TODO Heavy-duty function - optimize if possible.
    $smart_split_re = '~((?:[^\s\'"]*(?:(?:"(?:[^"]|\.)*"|\'(?:[^\']|\.)*\')[^\s\'"]*)+)|\S+)~';
    $matches = array();
    foreach (new PyReFinditer($smart_split_re, $text) as $match) {
        $matches[] = $match->group(0);
    }
    return $matches;
}


function get_text_list($list_, $last_word = 'or') {
    $length = count($list_);
    if ($length == 0) {
        return '';
    }
    if ($length == 1) {
        return $list_[0];
    }
    $list__ = $list_;
    unset($list__[$length - 1]);

    return join(', ', $list__) . ' ' . $last_word . ' ' . $list_[$length - 1];
}


/*
 * Explicitly mark a string as safe for (HTML) output purposes. The returned
 * object can be used everywhere a string or unicode object is appropriate.
 *
 * Can be called multiple times on a single string.
*/
function mark_safe($s) {

    if ($s instanceof SafeData) {
        return $s;
    }

    if (is_string($s) || (is_subclass_of($s, 'Promise') && $s->_delegate_str)) {
        return new SafeString($s);
    }

    return new SafeString((string)$s);
}


/**
 * Explicitly mark a string as requiring HTML escaping upon output. Has no
 * effect on SafeData subclasses.
 *
 * Can be called multiple times on a single string (the resulting escaping is
 * only applied once).
 *
 * @param $s
 * @return mixed
 */
function mark_for_escaping($s) {
    if (($s instanceof SafeData) || ($s instanceof EscapeData)) {
        return $s;
    }
    return new EscapeString((string)$s);
}


/*
 * Convert quoted string literals to unquoted strings with escaped quotes and
 * backslashes unquoted::
 *
 *      >>> unescape_string_literal('"abc"')
 *      'abc'
 *      >>> unescape_string_literal("'abc'")
 *      'abc'
 *      >>> unescape_string_literal('"a \"bc\""')
 *      'a "bc"'
 *      >>> unescape_string_literal("'\'ab\' c'")
 *      "'ab' c"
 */
function unescape_string_literal($s) {
    if (!in_array($s[0], array('"', "'")) || $s[strlen($s) - 1] != $s[0]) {
        throw new ValueError('Not a string literal: ' . $s);
    }
    $quote = $s[0];
    $s = py_slice($s, 1, -1);
    $s = str_replace('\\' . $quote, $quote, $s);
    $s = str_replace('\\\\', '\\', $s);
    return $s;
}


/**
 * Checks if value is a datetime and converts it to local time if necessary.
 *
 * If use_tz is provided and is not None, that will force the value to
 * be converted (or not), overriding the value of settings.USE_TZ.
 *
 * @param $value
 * @param null $use_tz
 */
function dja_localtime($value, $use_tz = null) {
    $convert = True;
    if (isset($value->convert_to_local_time) && !$value->convert_to_local_time) {
        $convert = False;
    }

    if (is_a($value, 'DateTime') && ($use_tz === null ? Dja::getSetting('USE_TZ') : $use_tz) && !is_naive($value) && $convert) {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $value->setTimezone($timezone);
    }
    return $value;
}


/*
 * Formats a numeric value using localization settings
 *
 * If use_l10n is provided and is not None, that will force the value to
 * be localized (or not), overriding the value of settings.USE_L10N.
 */
function dja_number_format($value, $decimal_pos = null, $use_l10n = null) {
    // TODO Implement.
    return $value;
}


/**
 * Checks if value is a localizable type (date, number...) and returns it
 * formatted as a string using current locale format.
 *
 * If use_l10n is provided and is not None, that will force the value to
 * be localized (or not), overriding the value of settings.USE_L10N.
 *
 * @param $value
 * @param null $use_l10n
 *
 * @return SafeString|string|void
 */
function localize($value, $use_l10n = null) {
    if (is_bool($value)) {
        return mark_safe($value);
    } elseif (is_float($value) || is_int($value) || is_long($value)) {
        return dja_number_format($value, null, $use_l10n);
    } elseif (is_a($value, 'DateTime')) {
        return dja_date_format($value, 'DATETIME_FORMAT', $use_l10n);
    } else {
        return $value;
    }
}


/*
 * Returns the given HTML with ampersands, quotes and angle brackets encoded.
 */
function escape($html) {
    $html = str_replace('&', '&amp;', $html);
    $html = str_replace('<', '&lt;', $html);
    $html = str_replace('>', '&gt;', $html);
    $html = str_replace('"', '&quot;', $html);
    $html = str_replace("'", '&#39;', $html);
    return mark_safe($html);
}


/**
 * Similar to escape(), except that it doesn't operate on pre-escaped strings.
 *
 * @param $html
 *
 * @return SafeString
 */
function conditional_escape($html) {
    if ($html instanceof SafeData) {
        return $html;
    } else {
        return escape($html);
    }
}


function import_module($name) {
    $name = str_replace('.', '/', $name);
    $name = str_replace('/php', '.php', $name);

    // Hmm, globals...

    // We keep loaded modules registry similar to Python's.
    if (isset($GLOBALS['DJA_IMPORTED_MODULES'][$name])) {
        return $GLOBALS['DJA_IMPORTED_MODULES'][$name];
    }

    if (is_dir($name)) {
        if (count(scandir($name)) > 2) {
            $GLOBALS['DJA_IMPORTED_MODULES'][$name] = '';
            return '';
        }
        throw new ImportError('Template tags directory "' . $name .'" is empty.');
    } else {
        if (!py_str_ends_with($name, '.php')) {
            $name .= '.php';
        }
        $res = @include($name);
        if ($res === False) {
            throw new ImportError('Unable to include template tags library from ' . $name);
        }
        $GLOBALS['DJA_IMPORTED_MODULES'][str_replace('.php', '', $name)] = $res;
        return $res;
    }
}


/**
 * Joins one or more path components to the base path component intelligently.
 * Returns a normalized, absolute version of the final path.
 *
 * The final path must be located inside of the base path component (otherwise
 * a ValueError is raised).
 *
 * @param $base
 * @param $paths
 *
 * @return mixed
 */
function safe_join($base, $paths) {
   /*
    * We need to use normcase to ensure we don't false-negative on case
    * insensitive operating systems (like Windows).
    */

    $final_path = $base . '/' . $paths;
    // TODO Implement.
    return $final_path;
}


/*
 * Converts newlines into <p> and <br />s.
 */
function linebreaks($value, $autoescape=False) {
   $value = normalize_newlines($value);
   $paras = preg_split("~\n{2,}~", $value);
   if ($autoescape) {
       $paras_ = array();
       foreach ($paras as $p) {
           $paras_[] = '<p>' . str_replace("\n", '<br />', escape($p)) . '</p>';
       }
   } else {
       $paras_ = array();
       foreach ($paras as $p) {
           $paras_[] = '<p>' . str_replace("\n", '<br />', $p) . '</p>';
       }
   }
   return join("\n\n", $paras_);
}


/**
 * Replaces newlines of different styles with \n.
 *
 * @param string $text
 * @return string|null
 */
function normalize_newlines($text) {
   return preg_replace("~\r\n|\r|\n~", "\n", $text);
}


/**
 * Converts an Internationalized Resource Identifier (IRI) portion to a URI
 * portion that is suitable for inclusion in a URL.
 *
 * @param string $iri
 * @return mixed|string
 */
function iri_to_uri($iri) {
    return py_urllib_quote($iri, "/#%[]=:;$&()+,!?*@'~");
}


// An object used to truncate text, either by characters or words.
class Truncator { //(SimpleLazyObject):

    public function __construct($text) {
        $this->text = $text;
        $this->_wrapped = $text;
    }

    public static function add_truncation_text($text, $truncate = null) {
        if ($truncate === null) {
            $truncate = pgettext('String to return when truncating text', '%(truncated_text)s...');
        }

        if (strpos($truncate, '%(truncated_text)s') !== False) {
            return str_replace('%(truncated_text)s', $text, $truncate);
        }

        /*
         * The truncation text didn't contain the %(truncated_text)s string
         * replacement argument so just append it to the text.
         */
        if (py_str_ends_with($text, $truncate)) {
            // But don't append the truncation text if the current text already ends in this.
            return $text;
        }
        return ($text . $truncate);
    }

    /**
     * Returns the text truncated to be no longer than the specified number
     * of characters.
     *
     * Takes an optional argument of what should be used to notify that the
     * string has been truncated, defaulting to a translatable string of an
     * ellipsis (...).
     *
     * @param $num
     * @param null $truncate
     * @return string
     */
    public function chars($num, $truncate=null) {
        $length = (int)$num;
        $text = $this->_wrapped;

        // Calculate the length to truncate to (max length - end_text length)
        $truncate_len = $length;

        foreach (str_split(self::add_truncation_text('', $truncate)) as $char) {
            $truncate_len -= 1;
            if ($truncate_len==0) {
                break;
            }
        }

        $s_len = 0;
        $end_index = null;

        $i = 0;
        foreach (str_split($text) as $char) {
            $s_len += 1;
            if ($end_index===null &&  $s_len > $truncate_len) {
                $end_index = $i;
            }

            if ($s_len > $length) {
                // Return the truncated string
                $e_ = $end_index;
                if (!$e_) {
                    $e_ = 0;
                }
                return self::add_truncation_text(py_slice($text, null, $e_), $truncate);
            }
            $i++;
        }

        // Return the original string since no truncation was necessary
        return $text;
    }

    /**
     * Truncates a string after a certain number of words. Takes an optional
     * argument of what should be used to notify that the string has been
     * truncated, defaulting to ellipsis (...).
     *
     * @param $num
     * @param null $truncate
     * @param bool $html
     *
     * @return string
     */
    public function words($num, $truncate = null, $html = False) {
        $length = (int)$num;
        if ($html) {
            // TODO Implement _html_words.
            return $this->_html_words($length, $truncate);
        }
        return $this->_text_words($length, $truncate);
    }

    /**
     * Truncates a string after a certain number of words.
     *
     * Newlines in the string will be stripped.
     *
     * @param $length
     * @param $truncate
     *
     * @return string
     */
    private function _text_words($length, $truncate) {
        $words = py_str_split($this->_wrapped);

        if (count($words) > $length) {
            $words = py_slice($words, null, $length);
            return self::add_truncation_text(join(' ', $words), $truncate);
        }
        return join(' ', $words);
    }
}
