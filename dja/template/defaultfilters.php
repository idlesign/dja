<?php

$lib = new Library();


// TODO implement stringfilter() if required.


/*
 * STRINGS
 */

//stringfilter
$lib->filter('addslashes', function($value) {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\\"', $value);
    $value = str_replace("'", "\\'", $value);
    return $value;
}, array('is_safe' => True));


//stringfilter
$lib->filter('capfirst', function($value) {
    return ucfirst($value);
}, array('is_safe' => True));


//stringfilter
$lib->filter('escapejs', function($value) {
    return escapejs($value);
});


//stringfilter
$lib->filter('fix_ampersands', function($value) {
    return preg_replace('~&(?!(\w+|#\d+);)~', '&amp;', $value);
}, array('is_safe' => True));


//stringfilter
$lib->filter('floatformat', function($text, $arg=-1) {
    $input_val = (string)$text;
    $input_val = round($input_val, 1);
    return $input_val;
}, array('is_safe' => True));


//stringfilter
$lib->filter('iriencode', function($value) {
    $safe = get_urlencode_safe();
    $value = urlencode($value);
    $value = str_replace(array_keys($safe), array_values($safe), $value);
    return $value;
}, array('is_safe' => True));


//stringfilter
$lib->filter('linenumbers', function($value, $autoescape=null) {
    $lines = explode("\n", $value);
    // Find the maximum width of the line count, for use with zero padding string format command
    $width = strlen(count($lines));
    if (!$autoescape || ($value instanceof SafeData)) {
        $i = 0;
        foreach ($lines as $line) {
            $lines[$i] = sprintf('%0' . $width  . 'd. ' . $line, $i+1);
            $i++;
        }
    } else {
        $i = 0;
        foreach ($lines as $line) {
            $lines[$i] = sprintf('%0' . $width  . 'd. ' . escape($line), $i+1);
            $i++;
        }
    }
    return mark_safe(join("\n", $lines));
}, array('is_safe' => True, 'needs_autoescape'=>True));


//stringfilter
$lib->filter('lower', function($value) {
    return strtolower($value);
}, array('is_safe' => True));


//stringfilter
$lib->filter('make_list', function($value) {
    $value = array($value);
    $v_ = array();
    foreach ($value as $val) {
        $v_[] = 'u\'' . $val . '\'';  // Mimic python's repr
    }
    return '[' . join(', ', $v_) . ']';
}, array('is_safe' => False));


//stringfilter
$lib->filter('slugify', function($value) {
    $value = strtolower(trim(preg_replace('~[^\w\s-]~', '', $value)));
    return mark_safe(preg_replace('~[-\s]+~', '-', $value));

}, array('is_safe' => True));


$lib->filter('stringformat', function($value, $arg) {
    return sprintf('%' . (string)$arg, $value);
}, array('is_safe' => True));


//stringfilter
$lib->filter('title', function($value) {
    return ucwords(strtolower($value));
}, array('is_safe' => True));


//stringfilter
$lib->filter('truncatechars', function($value, $arg) {

    if (!is_int($arg)) {  // Invalid literal for int().
        return $value;  // Fail silently.
    }
    $length = $arg;

    $tr_ = new Truncator($value);
    return $tr_->chars($length);
}, array('is_safe' => True));


//stringfilter
$lib->filter('truncatewords', function($value, $arg) {
    $arg = (string)$arg;
    if (!is_numeric($arg)) {  // Invalid literal for int().
        return $value;  // Fail silently.
    }
    $length = $arg;

    $tr_ = new Truncator($value);
    return $tr_->words($length, ' ...');
}, array('is_safe' => True));


// TODO truncatewords_html


//stringfilter
$lib->filter('upper', function($value) {
    return strtoupper($value);
}, array('is_safe' => False));


function get_urlencode_safe($filter=null) {
    $safe = array('%2F'=>'/', '%23'=>'#', '%25'=>'%', '%5B'=>'[', '%5D'=>']',
        '%3D'=>'=', '%3A'=>':', '%3B'=>';', '%24'=>'$', '%26'=>'&',
        '%28'=>'(', '%29'=>')', '%2B'=>'+', '%2C'=>',', '%21'=>'!',
        '%3F'=>'?', '%2A'=>'*', '%40'=>'@', '%27'=>'\'', '%7E'=>'~',
    );
    if ($filter) {
        $f = function($i) use ($filter) {
            return in_array($i, $filter);
        };
        $safe = array_filter($safe, $f);
    }
    return $safe;
}


//stringfilter
$lib->filter('urlencode', function($value, $safe=null) {
    if ($safe!==null) {
        $safe = str_split($safe);
    } else {
        $safe = array('/');
    }
    $safe = get_urlencode_safe($safe);
    $value = urlencode($value);
    $value = str_replace(array_keys($safe), array_values($safe), $value);
    return $value;

}, array('is_safe' => False));


//stringfilter
$lib->filter('urlize', function($value, $autoescape=null) {
    $urlize_impl = ''; // TODO implement
    return mark_safe($urlize_impl($value, True, $autoescape));

}, array('is_safe' => True, 'needs_autoescape'=>True));


// TODO urlizetrunc


//stringfilter
$lib->filter('wordcount', function($value) {
    return count(py_str_split($value));
}, array('is_safe' => False));


//stringfilter
$lib->filter('wordwrap', function($value, $arg) {
    return wordwrap($value, (string)$arg);
}, array('is_safe' => True));


//stringfilter
$lib->filter('ljust', function($value, $arg) {
    return str_pad($value, $arg->get(), ' ', STR_PAD_RIGHT);
}, array('is_safe' => True));


//stringfilter
$lib->filter('rjust', function($value, $arg) {
    return str_pad($value, $arg->get(), ' ', STR_PAD_LEFT);
}, array('is_safe' => True));


//stringfilter
$lib->filter('center', function($value, $arg) {
    return str_pad($value, $arg->get(), ' ', STR_PAD_BOTH);
}, array('is_safe' => True));


//stringfilter
$lib->filter('cut', function($value, $arg) {
    $safe = ($value instanceof SafeData);
    $value = str_replace($arg, '', $value);
    if ($safe && (string)$arg != ';') {
        return mark_safe($value);
    }
    return $value;
});



/*
 * HTML STRINGS
 */


//stringfilter
$lib->filter('escape', function($value) {
    return mark_for_escaping($value);
}, array('is_safe' => True));


//stringfilter
$lib->filter('force_escape', function($value) {
    return mark_safe(escape($value));
}, array('is_safe' => True));


//stringfilter
$lib->filter('linebreaks', function($value, $autoescape=null) {
    $autoescape = ($autoescape && !($value instanceof SafeData));
    return mark_safe(linebreaks($value, $autoescape));
}, array('is_safe' => True, 'needs_autoescape'=>True));


//stringfilter
$lib->filter('linebreaksbr', function($value, $autoescape=null) {
    $autoescape = ($autoescape && !($value instanceof SafeData));
    $value = normalize_newlines($value);
    if ($autoescape) {
        $value = escape($value);
    }
    return mark_safe(str_replace("\n", '<br />', $value));
}, array('is_safe' => True, 'needs_autoescape'=>True));


//stringfilter
$lib->filter('safe', function($value) {
    return mark_safe($value);
}, array('is_safe' => True));


$lib->filter('safeseq', function($value) {
    $s_ = array();
    foreach ($value as $i_) {
        $s_[] = mark_safe($i_);
    }
    return $s_;
}, array('is_safe' => True));


//stringfilter
$lib->filter('removetags', function($value, $tags) {
    $tags_ = py_str_split( $tags);
    $tags = array();
    foreach ($tags_ as $tag) {
        $tags[] = preg_quote($tag);
    }
    $tags = $tags_;
    unset($tags_);
    $tags_re = '(' . join('|', $tags) . ')';
    $starttag_re = '~<' . $tags_re . '(/?>|(\s+[^>]*>))~u';
    $endtag_re = '~</' . $tags_re . '>~';
    $value = preg_replace($starttag_re, '', $value);
    $value = preg_replace($endtag_re, '', $value);
    return $value;
}, array('is_safe' => True));


//stringfilter
$lib->filter('striptags', function($value) {
    return strip_tags($value);
}, array('is_safe' => True));



/*
 * LISTS
 */


// TODO dictsort


// TODO dictsortreversed


$lib->filter('first', function($value) {

    try {
        return py_arr_get($value, 0);
    } catch (AttributeError $e) {  // IndexError
        return '';
    }

}, array('is_safe' => False));


$lib->filter('join', function($value, $autoescape = null, $arg=null) {

    if ($autoescape) {
        $value_ = array();
        foreach ($value as $v) {
            $value_[] = conditional_escape($v);
        }
        $value = $value_;
        unset($value_);
    }

    try {
        $data = join(conditional_escape($arg), $value);
    } catch (AttributeError $e) {  // fail silently but nicely  // TODO catching needs a review
        return $value;
    }

    return mark_safe($data);
}, array('is_safe' => True, 'needs_autoescape' => True));


$lib->filter('last', function($value) {

    try {
        return py_arr_get($value, -1);
    } catch (AttributeError $e) {  // IndexError
        return '';
    }

}, array('is_safe' => True));


$lib->filter('length', function($value) {

    if (($value instanceof ArrayAccess) || is_array($value)) {
        return count($value);
    } elseif (is_string($value)) {
        return strlen($value);
    } else {
        return '';
    }

}, array('is_safe' => True));


$lib->filter('length_is', function($value, $arg) {
    if (is_object($value)) {
        $value = $value->get();
    }
    if (is_object($arg)) {
        $arg = $arg->get();
    }

    if ((!is_string($value) && !is_array($value)) || !is_numeric($arg)) {
        return '';
    }
    if (is_string($value)) {
        return strlen($value)==$arg;  // TODO Check unicode handling.
    }
    return count($value)==$arg;
}, array('is_safe' => False));


$lib->filter('random', function($value) {
    return $value[rand(0, count(array_keys($value))-1)];
}, array('is_safe' => True));


$lib->filter('slice', function($value, $arg) {
    $bits = array();
    foreach (explode(':', $arg) as $x) {
        if (strlen($x)==0) {  // TODO Check unicode handling.
            $bits[] = null;
        } else {
            $bits[] = (int)$x;
        }
    }
    return py_slice($value, $bits[0], $bits[1]);


}, array('is_safe' => True));


$lib->filter('unordered_list', function($value, $autoescape=null) {

    if ($autoescape) {
        $escaper = function ($x) { return conditional_escape($x); };
    } else {
        $escaper = function ($x) { return $x; };
    }

    /*
     * Converts old style lists to the new easier to understand format.
     *
     * The old list format looked like:
     *   ['Item 1', [['Item 1.1', []], ['Item 1.2', []]]
     *
     * And it is converted to:
     *   ['Item 1', ['Item 1.1', 'Item 1.2]]
     */
    $convert_old_style_list = function ($list_) use (&$convert_old_style_list) {

        if (!is_array($list_) || count($list_)!=2) {
            return array($list_, False);
        }

        list ($first_item, $second_item) = $list_;
        if (empty($second_item)) {
            return array(array($first_item), True);
        }

        // see if second item is iterable
        if (!is_array($second_item)) {
            return array($list_, False);
        }

        $old_style_list = True;
        $new_second_item = array();

        foreach ($second_item as $sublist) {
            list ($item, $old_style_list) = $convert_old_style_list($sublist);
            if (!$old_style_list) {
                break;
            }
            $new_second_item = array_merge($new_second_item, $item);
        }
        if ($old_style_list) {
            $second_item = $new_second_item;
        }
        return array(array($first_item, $second_item), $old_style_list);
    };

    $_helper = function ($list_, $tabs=1) use ($escaper, &$_helper) {
        $indent = str_repeat("\t", $tabs);
        $output = array();

        $list_length = count($list_);
        $i = 0;
        while ($i < $list_length) {
            $title = $list_[$i];
            $sublist = '';
            $sublist_item = null;
            if (is_array($title)) {
                $sublist_item = $title;
                $title = '';
            } elseif ($i < ($list_length - 1)) {
                $next_item = $list_[$i+1];
                if ($next_item && is_array($next_item)) {
                    // The next item is a sub-list.
                    $sublist_item = $next_item;
                    // We've processed the next item now too.
                    $i += 1;
                }
            }
            if ($sublist_item) {
                $sublist = $_helper($sublist_item, $tabs+1);
                $sublist = sprintf("\n%s<ul>\n%s\n%s</ul>\n%s", $indent, $sublist, $indent, $indent);
            }
            $output[] = $indent . '<li>' . $escaper($title) . $sublist . '</li>';
            $i += 1;
        }
        return join("\n", $output);
    };

    list ($value, $converted) = $convert_old_style_list($value);
    return mark_safe($_helper($value, 1, $escaper));

}, array('is_safe' => True, 'needs_autoescape'=>True));



/*
 * INTEGERS
 */


$lib->filter('add', function($value, $arg) {

    if ($arg instanceof SafeData) {
        $arg = $arg->get();
    }

    if (is_string($value) && is_string($arg)) {
        return $value . $arg;
    }
    if (is_numeric($value) && is_numeric($arg)) {
        return $value + $arg;
    }
    if (is_array($value) && is_array($arg)) {
        return  '[' . join(', ', array_merge($value, $arg)) . ']';
    }
    return '';

}, array('is_safe' => False));


// TODO get_digit



/*
 * DATES
 */


$lib->filter('date', function($value, $arg=null) {
    return dja_date($value, $arg);
}, array('is_safe' => False, $expects_localtime = True));


// TODO time


// TODO timesince


// TODO timeuntil



/*
 * LOGIC
 */


$lib->filter('default', function($value, $arg) {
    return $value ? $value : $arg;
}, array('is_safe' => False));


$lib->filter('default_if_none', function($value, $arg) {
    if ($value === null) {
        return $arg;
    }
    return $value;
}, array('is_safe' => False));


// TODO divisibleby


$lib->filter('yesno', function($value, $arg = null) {
    if ($arg === null) {
        $arg = ugettext('yes,no,maybe');
    }
    $bits = explode(',', $arg);
    if (count($bits) < 2) {
        return $value;  // Invalid arg.
    }

    @list($yes, $no, $maybe) = $bits;
    if (!$maybe) {  // Unpack list of wrong size (no "maybe" value provided).
        $maybe = $bits[1];
    }
    if ($value === null) {
        return $maybe;
    }
    if ($value) {
        return $yes;
    }
    return $no;
}, array('is_safe' => False));



/*
 * MISC
 */


// TODO filesizeformat


// TODO pluralize


$lib->filter('phone2numeric', function($value) {
    $char2number = array('a'=>'2', 'b'=>'2', 'c'=>'2', 'd'=>'3', 'e'=>'3',
        'f'=>'3', 'g'=>'4', 'h'=>'4', 'i'=>'4', 'j'=>'5', 'k'=>'5', 'l'=>'5',
        'm'=>'6', 'n'=>'6', 'o'=>'6', 'p'=>'7', 'q'=>'7', 'r'=>'7', 's'=>'7',
        't'=>'8', 'u'=>'8', 'v'=>'8', 'w'=>'9', 'x'=>'9', 'y'=>'9', 'z'=>'9',
    );
    foreach ($char2number as $k=>$v) {
        $value = str_ireplace($k, $v, $value);
    }
    return $value;

}, array('is_safe' => True));


$lib->filter('pprint', function($value) {
    return print_r($value, true);

}, array('is_safe' => True));



return $lib;