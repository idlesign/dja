<?php

require_once 'dja_bootstrap.php';
require_once 'utils.php';
require_once 'filters.php';


// We import loader modules beforehand to warm up.
import_module('loaders/filesystem');
import_module('loaders/app_directories');


/*
 * Custom template tag for tests
 */

class EchoNode extends Node {

    public function __construct($contents) {
        $this->contents = $contents;
    }

    public function render($context) {
        return join(' ', $this->contents);
    }
}


function do_echo($token) {
    return new EchoNode(py_slice(explode(' ', $token->contents), 1));
}


$lib = new Library();
$lib->tag('echo', function($parser, $token) { return do_echo($token); });
$lib->tag('other_echo', function($parser, $token) { return do_echo($token); });
$lib->filter('upper', function($value) { return strtoupper($value); });
DjaBase::$libraries['testtags'] = $lib;


/*
 * Helper objects for template tests
 */

class SomeException extends Exception {
    public $silent_variable_failure = True;
}


class SomeOtherException extends Exception {}


class ContextStackException extends Exception {}


class ShouldNotExecuteException extends Exception {}


class SomeClass {

    public function __construct() {
        $this->otherclass = new OtherClass();
    }

    public function method() {
        return 'SomeClass.method';
    }

    public function method2($o) {
        return $o;
    }

    public function method3() {
        throw new SomeException();
    }

    public function method4() {
        throw new SomeOtherException();
    }

    public function __get($key) {
        if ($key=='silent_fail_attribute') {
            return $this->silent_fail_attribute();
        } elseif ($key=='noisy_fail_attribute') {
            return $this->noisy_fail_attribute();
        } elseif ($key=='silent_fail_key') {
            throw new SomeException();
        } elseif ($key=='noisy_fail_key') {
            throw new SomeOtherException();
        }
        throw new KeyError();
    }

    public function silent_fail_attribute() {
        throw new SomeException();
    }

    public function noisy_fail_attribute() {
        throw new SomeOtherException();
    }
}


class OtherClass {
    public function method() {
        return 'OtherClass.method';
    }
}


class TestObj {

    public function is_true() {
        return True;
    }

    public function is_false() {
        return False;
    }

    public function is_bad() {
        throw new ShouldNotExecuteException();
    }
}


class SilentGetItemClass {
    public function __get($key) {
        throw new SomeException();
    }
}


class SilentAttrClass {
    public function b() {
        throw new SomeException();
    }
    public function __get($key) {
        if ($key=='b') {
            return $this->b();
        }
        throw new KeyError();
    }
}


class TemplatesTests extends PHPUnit_Framework_TestCase {

    protected $backupGlobals = false;

    public function setUp() {
        $this->old_static_url = Dja::getSetting('STATIC_URL');
        $this->old_media_url = Dja::getSetting('MEDIA_URL');
        Dja::setSetting('STATIC_URL', '/static/');
        Dja::setSetting('MEDIA_URL', '/media/');
    }

    public function tearDown() {
        Dja::setSetting('STATIC_URL', $this->old_static_url);
        Dja::setSetting('MEDIA_URL', $this->old_media_url);
    }

    public function testLoaderDebugOrigin() {
        // Turn TEMPLATE_DEBUG on, so that the origin file name will be kept with the compiled templates.
        $old_debug = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);

        $old_loaders = DjaLoader::$template_source_loaders;

        DjaLoader::$template_source_loaders = array(new FilesystemLoader());

        /*
         * We rely on the fact that runtests.py sets up TEMPLATE_DIRS to
         * point to a directory containing a 404.html file. Also that
         * the file system and app directories loaders both inherit the
         * load_template method from the BaseLoader class, so we only need
         * to test one of them.
         */
        $load_name = '404.html';
        $template = DjaLoader::getTemplate($load_name);
        $template_name = $template->nodelist[0]->source[0]->name;
        $this->assertTrue(py_str_ends_with($template_name, $load_name),
            'Template loaded by filesystem loader has incorrect name for debug page: ' . $template_name);

        // Aso test the cached loader, since it overrides load_template
        $cache_loader = new CachedLoader(array(''));
        $cache_loader->_cached_loaders = DjaLoader::$template_source_loaders;
        DjaLoader::$template_source_loaders = array($cache_loader);

        $template = DjaLoader::getTemplate($load_name);
        $template_name = $template->nodelist[0]->source[0]->name;
        $this->assertTrue(py_str_ends_with($template_name, $load_name),
            'Template loaded through cached loader has incorrect name for debug page: ' . $template_name);

        $template = DjaLoader::getTemplate($load_name);
        $template_name = $template->nodelist[0]->source[0]->name;
        $this->assertTrue(py_str_ends_with($template_name, $load_name),
            'Cached template loaded through cached loader has incorrect name for debug page: ' . $template_name);

        DjaLoader::$template_source_loaders = $old_loaders;
        Dja::setSetting('TEMPLATE_DEBUG', $old_debug);
    }

    /**
     * Tests that the correct template is identified as not existing
     * when {% include %} specifies a template that does not exist.
     */
    public function testIncludeMissingTemplate() {
        /*
         * TEMPLATE_DEBUG must be true, otherwise the exception raised
         * during {% include %} processing will be suppressed.
         */
        $old_td = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);

        $old_loaders = DjaLoader::$template_source_loaders;

        /*
         * Test the base loader class via the app loader. load_template
         * from base is used by all shipped loaders excepting cached,
         * which has its own test.
         */
        DjaLoader::$template_source_loaders = array(new AppDirectoriesLoader());

        $load_name = 'test_include_error.html';
        $r = null;
        try {
            $tmpl = DjaLoader::selectTemplate(array($load_name));
            $r = $tmpl->render(new Context(array()));
        } catch (TemplateDoesNotExist $e) {
            Dja::setSetting('TEMPLATE_DEBUG', $old_td);
            $this->assertEquals('missing.html', $e->getMessage());
        }
        $this->assertEquals(null, $r, 'Template rendering unexpectedly succeeded, produced: ->' . $r . '<-');

        DjaLoader::$template_source_loaders = $old_loaders;
        Dja::setSetting('TEMPLATE_DEBUG', $old_td);
    }


    /**
     * Tests that the correct template is identified as not existing
     * when {% extends %} specifies a template that does exist, but
     * that template has an {% include %} of something that does not
     * exist. See #12787.
     */
    public function testExtendsIncludeMissingBaseloader() {
        /*
         * TEMPLATE_DEBUG must be true, otherwise the exception raised
         * during {% include %} processing will be suppressed.
         */
        $old_td = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);

        $old_loaders = DjaLoader::$template_source_loaders;

        /*
         * Test the base loader class via the app loader. load_template
         * from base is used by all shipped loaders excepting cached,
         * which has its own test.
         */
        DjaLoader::$template_source_loaders = array(new AppDirectoriesLoader());


        $load_name = 'test_extends_error.html';
        $tmpl = DjaLoader::getTemplate($load_name);
        $r = null;
        try {
            $r = $tmpl->render(new Context(array()));
        } catch (TemplateDoesNotExist $e) {
            Dja::setSetting('TEMPLATE_DEBUG', $old_td);
            $this->assertEquals('missing.html', $e->getMessage());
        }
        $this->assertEquals(null, $r, 'Template rendering unexpectedly succeeded, produced: ->' . $r . '<-');

        DjaLoader::$template_source_loaders = $old_loaders;
        Dja::setSetting('TEMPLATE_DEBUG', $old_td);
    }

    /**
     * Same as test_extends_include_missing_baseloader, only tests
     * behavior of the cached loader instead of BaseLoader.
     */
    public function testExtendsIncludeMissingCachedloader() {
        $old_td = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);

        $old_loaders = DjaLoader::$template_source_loaders;

        $cache_loader = new CachedLoader(array(''));
        $cache_loader->_cached_loaders = array(new AppDirectoriesLoader());

        DjaLoader::$template_source_loaders = array($cache_loader);

        $load_name = 'test_extends_error.html';
        $tmpl = DjaLoader::getTemplate($load_name);
        $r = null;
        try {
            $r = $tmpl->render(new Context(array()));
        } catch (TemplateDoesNotExist $e) {
            $this->assertEquals('missing.html', $e->getMessage());
        }
        $this->assertEquals(null, $r, 'Template rendering unexpectedly succeeded, produced: ->' . $r . '<-');

        /*
         * For the cached loader, repeat the test, to ensure the first attempt did not cache a
         * result that behaves incorrectly on subsequent attempts.
         */
        $tmpl = DjaLoader::getTemplate($load_name);
        try {
            $tmpl->render(new Context(array()));
        } catch (TemplateDoesNotExist $e) {
            $this->assertEquals('missing.html', $e->getMessage());
        }
        $this->assertEquals(null, $r, 'Template rendering unexpectedly succeeded, produced: ->' . $r . '<-');


        DjaLoader::$template_source_loaders = $old_loaders;
        Dja::setSetting('TEMPLATE_DEBUG', $old_td);
    }

    public function testTokenSmartSplit() {
        // Regression test for #7027
        $token = new Token(DjaBase::TOKEN_BLOCK, 'sometag _("Page not found") value|yesno:_("yes,no")');
        $split = $token->splitContents();
        $this->assertEquals(array("sometag", '_("Page not found")', 'value|yesno:_("yes,no")'), $split);
    }

    /*
     * The template system doesn't wrap exceptions, but annotates them.
     * Refs #16770
     */
    public function testNoWrappedException() {
        $old_td = Dja::getSetting('TEMPLATE_DEBUG');;
        Dja::setSetting('TEMPLATE_DEBUG', True);

        $f_ = function() { 42 / 0; };
        $c = new Context(array("coconuts"=> $f_));
        $t = new Template("{{ coconuts }}");
        try {
            $t->render($c);
        } catch (Exception $e) {

        }
        $this->assertEquals(array(0, 14), $e->django_template_source[1]);
        Dja::setSetting('TEMPLATE_DEBUG', $old_td);
    }

    public function testInvalidBlockSuggestion() {
        try {
            $t = new Template('{% if 1 %}lala{% endblock %}{% endif %}');
        } catch (TemplateSyntaxError $e) {
            $this->assertEquals($e->getMessage(), "Invalid block tag: 'endblock', expected 'elif', 'else' or 'endif'");
        }
    }

    public function testTemplates() {
        $template_tests = self::getTemplateTests();
        $filter_tests = get_filter_tests();

        /*
         * Quickly check that we aren't accidentally using a name in both
         * template and filter tests.
         */
        $overlapping_names = array();
        $tkeys_ = array_keys($template_tests);
        foreach ($filter_tests as $name=>$v) {
            if (key_exists($name, $tkeys_)) {
                $overlapping_names[] = $name;
            }
        }

        if (!empty($overlapping_names)) {
            throw new Exception ('Duplicate test name(s): ' . join(', ', $overlapping_names));
        }

        $template_tests = array_merge($template_tests, $filter_tests);

        $tpls_= array();
        foreach ($template_tests as $name=>$t) {
            $tpls_[$name] = $t[0];
        }
        $cache_loader = setup_test_template_loader($tpls_, True);

        $failures = array();
        $tests = $template_tests;
        ksort($tests);

        // Turn TEMPLATE_DEBUG off, because tests assume that.
        $old_debug = Dja::getSetting('TEMPLATE_DEBUG');
        Dja::setSetting('TEMPLATE_DEBUG', True);

        // Set TEMPLATE_STRING_IF_INVALID to a known string.
        $old_invalid = Dja::getSetting('TEMPLATE_STRING_IF_INVALID');
        $expected_invalid_str = 'INVALID';

        // Set ALLOWED_INCLUDE_ROOTS so that ssi works.
        $old_allowed_include_roots = Dja::getSetting('ALLOWED_INCLUDE_ROOTS');
        Dja::setSetting('ALLOWED_INCLUDE_ROOTS', array(realpath(dirname(__FILE__))));

        // Warm the URL reversing cache. This ensures we don't pay the cost
        // warming the cache during one of the tests.
        // TODO Dja::url_reverse('regressiontests.templates.views.client_action', null, array('id'=>0,'action'=>"update"));

        foreach ($tests as $name=>$vals) {
            if (is_array($vals[2])) {
                $normal_string_result = $vals[2][0];
                $invalid_string_result = $vals[2][1];

                if (is_array($invalid_string_result)) {
                    $expected_invalid_str = 'INVALID %s';
                    $invalid_string_result = sprintf($invalid_string_result[0], $invalid_string_result[1]);
                    DjaBase::$invalid_var_format_string = True;
                }

                if (isset($vals[2][2])) {
                    $template_debug_result = $vals[2][2];
                } else {
                    $template_debug_result = $normal_string_result;
                }
            } else {
                $normal_string_result = $vals[2];
                $invalid_string_result = $vals[2];
                $template_debug_result = $vals[2];
            }

            if (key_exists('LANGUAGE_CODE', $vals[1])) {
                activate($vals[1]['LANGUAGE_CODE']);
            } else {
                activate('en-us');
            }

            foreach (array(
                         array('', False, $normal_string_result),
                         array($expected_invalid_str, False, $invalid_string_result),
                         array('', True, $template_debug_result))
                     as $itm) {
                list ($invalid_str, $template_debug, $result) = $itm;
                Dja::setSetting('TEMPLATE_STRING_IF_INVALID', $invalid_str);
                Dja::setSetting('TEMPLATE_DEBUG', $template_debug);

                foreach (array(False, True) as $is_cached) {
                    $fail_str_ = 'Template test (Cached=' . ($is_cached ? 'TRUE' : 'FALSE') . ', TEMPLATE_STRING_IF_INVALID=\'' . $invalid_str . '\', TEMPLATE_DEBUG=' . ($template_debug ? 'TRUE' : 'FALSE') . '): ' . $name . ' -- FAILED. ';

                    try {
                        try {
                            $test_template = DjaLoader::getTemplate($name);
                        } catch (ShouldNotExecuteException $e) {
                            $failures[] = $fail_str_ . 'Template loading invoked method that shouldn\'t have been invoked.';
                        }
                        try {
                            $output = self::render($test_template, $vals);
                        } catch (ShouldNotExecuteException $e) {
                            $failures[] = $fail_str_ . 'Template loading invoked method that shouldn\'t have been invoked.';
                        }
                    } catch (ContextStackException $e) {
                        $failures[] = $fail_str_ . 'Context stack was left imbalanced';
                        continue;
                    } catch (Exception $e) {
                        $exc_type = get_class($e);
                        $exc_value = $e->getMessage();
                        $exc_tb = $e->getTraceAsString();
                        if ($exc_type!=$result) {
                            $tb = $exc_tb;
                            $failures[] = $fail_str_ . 'Got ' . $exc_type . ', exception: ' . $exc_value . "\n" . $tb;
                        }
                        continue;
                    }

                    if ($output!=$result) {
                        $failures[] = $fail_str_ . 'Expected ||' . $result . '||, got ||' . $output . '||';
                    }
                }
                $cache_loader->reset();
            }
            if (key_exists('LANGUAGE_CODE', $vals[1])) {
                deactivate();
            }
            if (DjaBase::$invalid_var_format_string) {
                $expected_invalid_str = 'INVALID';
                DjaBase::$invalid_var_format_string = False;
            }

        }

        restore_template_loaders();
        deactivate();
        Dja::setSetting('TEMPLATE_STRING_IF_INVALID', $old_invalid);
        Dja::setSetting('TEMPLATE_DEBUG', $old_debug);
        Dja::setSetting('ALLOWED_INCLUDE_ROOTS', $old_allowed_include_roots);

        $sep_ = str_pad('', 70, '-');
        $this->assertEquals(array(), $failures, "Tests failed:\n{$sep_}\n" . join("\n{$sep_}\n", $failures));
    }

    private static function render($test_template, $vals) {
        $context = new Context($vals[1]);
        $before_stack_size = count($context->dicts);
        $output = $test_template->render($context);
        if (count($context->dicts)!=$before_stack_size) {
            throw new ContextStackException();
        }
        return $output;
    }

    private static function getTemplateTests() {
        // SYNTAX --
        // 'template_name': ('template contents', 'context dict', 'expected string output' or Exception class)

        // TODO For ssi: $basedir = dirname(__FILE__);

        $tests = array (
            // ## BASIC SYNTAX ################################################

            // Plain text should go through the template parser untouched
            'basic-syntax01'=>array('something cool', array(), 'something cool'),

            // Variables should be replaced with their value in the current context
            'basic-syntax02'=>array('{{ headline }}', array('headline'=>'Success'), 'Success'),

            // More than one replacement variable is allowed in a template
            'basic-syntax03'=>array("{{ first }} --- {{ second }}", array("first" =>1, "second" =>2), "1 --- 2"),

            // Fail silently when a variable is not found in the current context
            'basic-syntax04'=>array("as{{ missing }}df", array(), array("asdf","asINVALIDdf")),

            // A variable may not contain more than one word
            'basic-syntax06'=>array("{{ multi word variable }}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError for empty variable tags
            'basic-syntax07'=>array("{{ }}",       array(),  'TemplateSyntaxError'),
            'basic-syntax08'=>array("{{        }}",array(), 'TemplateSyntaxError'),

            // Attribute syntax allows a template to call an object's attribute
            'basic-syntax09'=>array("{{ var.method }}", array("var"=>new SomeClass()), "SomeClass.method"),

            // Multiple levels of attribute access are allowed
            'basic-syntax10'=>array("{{ var.otherclass.method }}", array("var"=>new SomeClass()), "OtherClass.method"),

            // Fail silently when a variable's attribute isn't found
            'basic-syntax11'=>array("{{ var.blech }}", array("var"=>new SomeClass()),  array("","INVALID")),

            // Raise TemplateSyntaxError when trying to access a variable beginning with an underscore
            'basic-syntax12'=>array("{{ var.__dict__ }}", array("var"=>new SomeClass()),  'TemplateSyntaxError'),

            // Raise TemplateSyntaxError when trying to access a variable containing an illegal character
            'basic-syntax13'=>array("{{ va>r }}", array(),  'TemplateSyntaxError'),
            'basic-syntax14'=>array("{{ (var.r) }}", array(),  'TemplateSyntaxError'),
            'basic-syntax15'=>array("{{ sp%am }}", array(),  'TemplateSyntaxError'),
            'basic-syntax16'=>array("{{ eggs! }}", array(),  'TemplateSyntaxError'),
            'basic-syntax17'=>array("{{ moo? }}", array(),  'TemplateSyntaxError'),

            // Attribute syntax allows a template to call a dictionary key's value
            'basic-syntax18'=>array("{{ foo.bar }}", array("foo"=>array("bar" =>"baz")), "baz"),

            // Fail silently when a variable's dictionary key isn't found
            'basic-syntax19'=>array("{{ foo.spam }}", array("foo"=>array("bar" =>"baz")), array("","INVALID")),

            // Fail silently when accessing a non-simple method
            'basic-syntax20'=>array("{{ var.method2 }}", array("var"=>new SomeClass()), array("","INVALID")),

            // Don't get confused when parsing something that is almost, but not quite, a template tag.
            'basic-syntax21'=>array("a {{ moo %} b", array(),  "a {{ moo %} b"),
            'basic-syntax22'=>array("{{ moo #}", array(),  "{{ moo #}"),

            // Will try to treat "moo #} {{ cow" as the variable. Not ideal, but costly to work around, so this triggers an error.
            'basic-syntax23'=>array("{{ moo #} {{ cow }}", array("cow"=>"cow"), 'TemplateSyntaxError'),

            // Embedded newlines make it not-a-tag.
            'basic-syntax24'=>array("{{ moo\n }}", array(),  "{{ moo\n }}"),

            // Literal strings are permitted inside variables, mostly for i18n purposes.
            'basic-syntax25'=>array('{{ "fred" }}', array(),  "fred"),
            'basic-syntax26'=>array('{{ "\"fred\"" }}', array(),  "\"fred\""),
            'basic-syntax27'=>array('{{ _("\"fred\"") }}', array(),  "\"fred\""),

            /* regression test for ticket #12554
             * make sure a silent_variable_failure Exception is supressed
             * on dictionary and attribute lookup
             */
            'basic-syntax28'=>array("{{ a.b }}", array('a'=>new SilentGetItemClass()), array('', 'INVALID')),
            'basic-syntax29'=>array("{{ a.b }}", array('a'=>new SilentAttrClass()), array('', 'INVALID')),

            // Something that starts like a number but has an extra lookup works as a lookup.
            'basic-syntax30'=>array("{{ 1.2.3 }}", array("1"=>array("2"=>array("3"=>"d"))), "d"),
            'basic-syntax31'=>array("{{ 1.2.3 }}", array("1"=>array("2"=>array("a", "b", "c", "d"))), "d"),
            'basic-syntax32'=>array("{{ 1.2.3 }}", array("1"=>array(array("x", "x", "x", "x"), array("y", "y", "y", "y"), array("a", "b", "c", "d"))), "d"),
            'basic-syntax33'=>array("{{ 1.2.3 }}", array("1"=>array("xxxx", "yyyy", "abcd")), "d"),
            'basic-syntax34'=>array("{{ 1.2.3 }}", array("1"=>array(array("x"=>"x"), array("y"=>"y"), array("z"=>"z", "3"=>"d"))), "d"),

            // Numbers are numbers even if their digits are in the context.
            'basic-syntax35'=>array("{{ 1 }}", array("1"=>"abc"), "1"),
            'basic-syntax36'=>array("{{ 1.2 }}", array("1"=>"abc"), "1.2"),

            // Call methods in the top level of the context
            'basic-syntax37'=>array('{{ callable }}', array("callable"=>function(){ return "foo bar"; }), "foo bar"),

            // Call methods returned from dictionary lookups
            'basic-syntax38'=>array('{{ var.callable }}', array("var"=>array("callable"=> function() { return "foo bar"; })), "foo bar"),

            // List-index syntax allows a template to access a certain item of a subscriptable object.
            'list-index01'=>array("{{ var.1 }}", array("var"=>array("first item", "second item")), "second item"),

            // Fail silently when the list index is out of range.
            'list-index02'=>array("{{ var.5 }}", array("var"=>array("first item", "second item")), array("", "INVALID")),

            // Fail silently when the variable is not a subscriptable object.
            'list-index03'=>array("{{ var.1 }}", array("var"=>null), array("", "INVALID")),

            // Fail silently when variable is a dict without the specified key.
            'list-index04'=>array("{{ var.1 }}", array("var"=>array()), array("", "INVALID")),

            // Dictionary lookup wins out when dict's key is a string.
            'list-index05'=>array("{{ var.1 }}", array("var"=>array('1'=>"hello")), "hello"),

            // But list-index lookup wins out when dict's key is an int, which
            // behind the scenes is really a dictionary lookup (for a dict) after converting the key to an int.
            'list-index06'=>array("{{ var.1 }}", array("var"=>array(1=>"hello")), "hello"),

            // Basic filter usage
            'filter-syntax01'=>array("{{ var|upper }}", array("var"=>"Django is the greatest!"), "DJANGO IS THE GREATEST!"),

            // Chained filters
            'filter-syntax02'=>array("{{ var|upper|lower }}", array("var"=>"Django is the greatest!"), "django is the greatest!"),

            // Raise TemplateSyntaxError for space between a variable and filter pipe
            'filter-syntax03'=>array("{{ var |upper }}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError for space after a filter pipe
            'filter-syntax04'=>array("{{ var| upper }}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError for a nonexistent filter
            'filter-syntax05'=>array("{{ var|does_not_exist }}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError when trying to access a filter containing an illegal character
            'filter-syntax06'=>array("{{ var|fil(ter) }}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError for invalid block tags
            'filter-syntax07'=>array("{% nothing_to_see_here %}", array(), 'TemplateSyntaxError'),

            // Raise TemplateSyntaxError for empty block tags
            'filter-syntax08'=>array("{% %}", array(), 'TemplateSyntaxError'),

            // Chained filters, with an argument to the first one
            'filter-syntax09'=>array('{{ var|removetags:"b i"|upper|lower }}', array("var"=>"<b><i>Yes</i></b>"), "yes"),

            // Literal string as argument is always "safe" from auto-escaping..
            'filter-syntax10'=>array('{{ var|default_if_none:" endquote\" hah" }}', array("var"=>null), ' endquote" hah'),

            // Variable as argument
            'filter-syntax11'=>array('{{ var|default_if_none:var2 }}', array("var"=>null, "var2"=>"happy"), 'happy'),

            // Default argument testing
            'filter-syntax12'=>array('{{ var|yesno:"yup,nup,mup" }} {{ var|yesno }}', array("var"=>True), 'yup yes'),

            // Fail silently for methods that raise an exception with a "silent_variable_failure" attribute
            'filter-syntax13'=>array('1{{ var.method3 }}2', array("var"=>new SomeClass()), array("12", "1INVALID2")),

            // In methods that raise an exception without a "silent_variable_attribute" set to True, the exception propagates
            'filter-syntax14'=>array('1{{ var.method4 }}2', array("var"=>new SomeClass()), array('SomeOtherException', 'SomeOtherException')),

            // Escaped backslash in argument
            'filter-syntax15'=>array('{{ var|default_if_none:"foo\bar" }}', array("var"=>null), 'foo\bar'),

            // Escaped backslash using known escape char
            'filter-syntax16'=>array('{{ var|default_if_none:"foo\now" }}', array("var"=>null), 'foo\now'),

            // Empty strings can be passed as arguments to filters
            'filter-syntax17'=>array('{{ var|join:"" }}', array('var'=>array('a', 'b', 'c')), 'abc'),

            // Numbers as filter arguments should work
            'filter-syntax19'=>array('{{ var|truncatewords:1 }}', array("var"=>"hello world"), "hello ..."),

            // filters should accept empty string constants
            'filter-syntax20'=>array('{{ ""|default_if_none:"was none" }}', array(), ""),

            // Fail silently for non-callable attribute and dict lookups which raise an exception with a "silent_variable_failure" attribute
            'filter-syntax21'=>array('1{{ var.silent_fail_key }}2', array("var"=>new SomeClass()), array("12", "1INVALID2")),
            'filter-syntax22'=>array('1{{ var.silent_fail_attribute }}2', array("var"=>new SomeClass()), array("12", "1INVALID2")),

            // In attribute and dict lookups that raise an unexpected exception without a "silent_variable_attribute" set to True, the exception propagates
            'filter-syntax23'=>array('1{{ var.noisy_fail_key }}2', array("var"=>new SomeClass()), array('SomeOtherException', 'SomeOtherException')),
            'filter-syntax24'=>array('1{{ var.noisy_fail_attribute }}2', array("var"=>new SomeClass()), array('SomeOtherException', 'SomeOtherException')),

            // ### COMMENT SYNTAX ########################################################
            'comment-syntax01'=>array("{# this is hidden #}hello", array(), "hello"),
            'comment-syntax02'=>array("{# this is hidden #}hello{# foo #}", array(), "hello"),

            // Comments can contain invalid stuff.
            'comment-syntax03'=>array("foo{#  {% if %}  #}", array(), "foo"),
            'comment-syntax04'=>array("foo{#  {% endblock %}  #}", array(), "foo"),
            'comment-syntax05'=>array("foo{#  {% somerandomtag %}  #}", array(), "foo"),
            'comment-syntax06'=>array("foo{# {% #}", array(), "foo"),
            'comment-syntax07'=>array("foo{# %} #}", array(), "foo"),
            'comment-syntax08'=>array("foo{# %} #}bar", array(), "foobar"),
            'comment-syntax09'=>array("foo{# {{ #}", array(), "foo"),
            'comment-syntax10'=>array("foo{# }} #}", array(), "foo"),
            'comment-syntax11'=>array("foo{# { #}", array(), "foo"),
            'comment-syntax12'=>array("foo{# } #}", array(), "foo"),

            // ### COMMENT TAG ###########################################################
            'comment-tag01'=>array("{% comment %}this is hidden{% endcomment %}hello", array(), "hello"),
            'comment-tag02'=>array("{% comment %}this is hidden{% endcomment %}hello{% comment %}foo{% endcomment %}", array(), "hello"),

            // Comment tag can contain invalid stuff.
            'comment-tag03'=>array("foo{% comment %} {% if %} {% endcomment %}", array(), "foo"),
            'comment-tag04'=>array("foo{% comment %} {% endblock %} {% endcomment %}", array(), "foo"),
            'comment-tag05'=>array("foo{% comment %} {% somerandomtag %} {% endcomment %}", array(), "foo"),


             // ### CYCLE TAG #############################################################
            'cycle01'=>array('{% cycle a %}', array(), 'TemplateSyntaxError'),
            'cycle02'=>array('{% cycle a,b,c as abc %}{% cycle abc %}', array(), 'ab'),
            'cycle03'=>array('{% cycle a,b,c as abc %}{% cycle abc %}{% cycle abc %}', array(), 'abc'),
            'cycle04'=>array('{% cycle a,b,c as abc %}{% cycle abc %}{% cycle abc %}{% cycle abc %}', array(), 'abca'),
            'cycle05'=>array('{% cycle %}', array(), 'TemplateSyntaxError'),
            'cycle06'=>array('{% cycle a %}', array(), 'TemplateSyntaxError'),
            'cycle07'=>array('{% cycle a,b,c as foo %}{% cycle bar %}', array(), 'TemplateSyntaxError'),
            'cycle08'=>array('{% cycle a,b,c as foo %}{% cycle foo %}{{ foo }}{{ foo }}{% cycle foo %}{{ foo }}', array(), 'abbbcc'),
            'cycle09'=>array("{% for i in test %}{% cycle a,b %}{{ i }},{% endfor %}", array('test'=>array(0,1,2,3,4)), 'a0,b1,a2,b3,a4,'),
            'cycle10'=>array("{% cycle 'a' 'b' 'c' as abc %}{% cycle abc %}", array(), 'ab'),
            'cycle11'=>array("{% cycle 'a' 'b' 'c' as abc %}{% cycle abc %}{% cycle abc %}", array(), 'abc'),
            'cycle12'=>array("{% cycle 'a' 'b' 'c' as abc %}{% cycle abc %}{% cycle abc %}{% cycle abc %}", array(), 'abca'),
            'cycle13'=>array("{% for i in test %}{% cycle 'a' 'b' %}{{ i }},{% endfor %}", array('test'=>array(0,1,2,3,4)), 'a0,b1,a2,b3,a4,'),
            'cycle14'=>array("{% cycle one two as foo %}{% cycle foo %}", array('one'=>'1','two'=>'2'), '12'),
            'cycle15'=>array("{% for i in test %}{% cycle aye bee %}{{ i }},{% endfor %}", array('test'=>array(0,1,2,3,4), 'aye'=>'a', 'bee'=>'b'), 'a0,b1,a2,b3,a4,'),
            'cycle16'=>array("{% cycle one|lower two as foo %}{% cycle foo %}", array('one'=>'A','two'=>'2'), 'a2'),
            'cycle17'=>array("{% cycle 'a' 'b' 'c' as abc silent %}{% cycle abc %}{% cycle abc %}{% cycle abc %}{% cycle abc %}", array(), ""),
            'cycle18'=>array("{% cycle 'a' 'b' 'c' as foo invalid_flag %}", array(), 'TemplateSyntaxError'),
            'cycle19'=>array("{% cycle 'a' 'b' as silent %}{% cycle silent %}", array(), "ab"),
            'cycle20'=>array("{% cycle one two as foo %} &amp; {% cycle foo %}", array('one' =>'A & B', 'two' =>'C & D'), "A & B &amp; C & D"),
            'cycle21'=>array("{% filter force_escape %}{% cycle one two as foo %} & {% cycle foo %}{% endfilter %}", array('one' =>'A & B', 'two' =>'C & D'), "A &amp; B &amp; C &amp; D"),
            'cycle22'=>array("{% for x in values %}{% cycle 'a' 'b' 'c' as abc silent %}{{ x }}{% endfor %}", array('values'=>array(1,2,3,4)), "1234"),
            'cycle23'=>array("{% for x in values %}{% cycle 'a' 'b' 'c' as abc silent %}{{ abc }}{{ x }}{% endfor %}", array('values'=>array(1,2,3,4)), "a1b2c3a4"),
            'included-cycle'=>array('{{ abc }}', array('abc'=>'xxx'), 'xxx'),
            'cycle24'=>array("{% for x in values %}{% cycle 'a' 'b' 'c' as abc silent %}{% include 'included-cycle' %}{% endfor %}", array('values'=>array(1,2,3,4)), "abca"),

            // ### EXCEPTIONS ############################################################

            // Raise exception for invalid template name
            'exception01'=>array("{% extends 'nonexistent' %}", array(), array('TemplateDoesNotExist', 'TemplateDoesNotExist')),

            // Raise exception for invalid template name (in variable)
            'exception02'=>array("{% extends nonexistent %}", array(), array('TemplateSyntaxError', 'TemplateDoesNotExist')),

            // Raise exception for extra {% extends %} tags
            'exception03'=>array("{% extends 'inheritance01' %}{% block first %}2{% endblock %}{% extends 'inheritance16' %}", array(), 'TemplateSyntaxError'),

            // Raise exception for custom tags used in child with {% load %} tag in parent, not in child
            'exception04'=>array("{% extends 'inheritance17' %}{% block first %}{% echo 400 %}5678{% endblock %}", array(), 'TemplateSyntaxError'),

            // ### FILTER TAG ############################################################
            'filter01'=>array('{% filter upper %}{% endfilter %}', array(), ''),
            'filter02'=>array('{% filter upper %}django{% endfilter %}', array(), 'DJANGO'),
            'filter03'=>array('{% filter upper|lower %}django{% endfilter %}', array(), 'django'),
            'filter04'=>array('{% filter cut:remove %}djangospam{% endfilter %}', array('remove'=>'spam'), 'django'),

            // ### FIRSTOF TAG ###########################################################
            'firstof01'=>array('{% firstof a b c %}', array('a'=>0,'b'=>0,'c'=>0), ''),
            'firstof02'=>array('{% firstof a b c %}', array('a'=>1,'b'=>0,'c'=>0), '1'),
            'firstof03'=>array('{% firstof a b c %}', array('a'=>0,'b'=>2,'c'=>0), '2'),
            'firstof04'=>array('{% firstof a b c %}', array('a'=>0,'b'=>0,'c'=>3), '3'),
            'firstof05'=>array('{% firstof a b c %}', array('a'=>1,'b'=>2,'c'=>3), '1'),
            'firstof06'=>array('{% firstof a b c %}', array('b'=>0,'c'=>3), '3'),
            'firstof07'=>array('{% firstof a b "c" %}', array('a'=>0), 'c'),
            'firstof08'=>array('{% firstof a b "c and d" %}', array('a'=>0,'b'=>0), 'c and d'),
            'firstof09'=>array('{% firstof %}', array(), 'TemplateSyntaxError'),
            'firstof10'=>array('{% firstof a %}', array('a'=> '<'), '<'), # Variables are NOT auto-escaped.

            // ### FOR TAG ###############################################################
            'for-tag01'=>array("{% for val in values %}{{ val }}{% endfor %}", array("values"=>array(1, 2, 3)), "123"),
            'for-tag02'=>array("{% for val in values reversed %}{{ val }}{% endfor %}", array("values"=>array(1, 2, 3)), "321"),
            'for-tag-vars01'=>array("{% for val in values %}{{ forloop.counter }}{% endfor %}", array("values"=>array(6, 6, 6)), "123"),
            'for-tag-vars02'=>array("{% for val in values %}{{ forloop.counter0 }}{% endfor %}", array("values"=>array(6, 6, 6)), "012"),
            'for-tag-vars03'=>array("{% for val in values %}{{ forloop.revcounter }}{% endfor %}", array("values"=>array(6, 6, 6)), "321"),
            'for-tag-vars04'=>array("{% for val in values %}{{ forloop.revcounter0 }}{% endfor %}", array("values"=>array(6, 6, 6)), "210"),
            'for-tag-vars05'=>array("{% for val in values %}{% if forloop.first %}f{% else %}x{% endif %}{% endfor %}", array("values"=>array(6, 6, 6)), "fxx"),
            'for-tag-vars06'=>array("{% for val in values %}{% if forloop.last %}l{% else %}x{% endif %}{% endfor %}", array("values"=>array(6, 6, 6)), "xxl"),
            'for-tag-unpack01'=>array("{% for key,value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), "one:1/two:2/"),
            'for-tag-unpack03'=>array("{% for key, value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), "one:1/two:2/"),
            'for-tag-unpack04'=>array("{% for key , value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), "one:1/two:2/"),
            'for-tag-unpack05'=>array("{% for key ,value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), "one:1/two:2/"),
            'for-tag-unpack06'=>array("{% for key value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), 'TemplateSyntaxError'),
            'for-tag-unpack07'=>array("{% for key,,value in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), 'TemplateSyntaxError'),
            'for-tag-unpack08'=>array("{% for key,value, in items %}{{ key }}:{{ value }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), 'TemplateSyntaxError'),
            // Ensure that a single loopvar doesn't truncate the list in val.
            'for-tag-unpack09'=>array("{% for val in items %}{{ val.0 }}:{{ val.1 }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), "one:1/two:2/"),
            // Otherwise, silently truncate if the length of loopvars differs to the length of each set of items.
            'for-tag-unpack10'=>array("{% for x,y in items %}{{ x }}:{{ y }}/{% endfor %}", array("items"=>array(array('one', 1, 'carrot'), array('two', 2, 'orange'))), "one:1/two:2/"),
            'for-tag-unpack11'=>array("{% for x,y,z in items %}{{ x }}:{{ y }},{{ z }}/{% endfor %}", array("items"=>array(array('one', 1), array('two', 2))), array("one:1,/two:2,/", "one:1,INVALID/two:2,INVALID/")),
            'for-tag-unpack12'=>array("{% for x,y,z in items %}{{ x }}:{{ y }},{{ z }}/{% endfor %}", array("items"=>array(array('one', 1, 'carrot'), array('two', 2))), array("one:1,carrot/two:2,/", "one:1,carrot/two:2,INVALID/")),
            'for-tag-unpack13'=>array("{% for x,y,z in items %}{{ x }}:{{ y }},{{ z }}/{% endfor %}", array("items"=>array(array('one', 1, 'carrot'), array('two', 2, 'cheese'))), array("one:1,carrot/two:2,cheese/", "one:1,carrot/two:2,cheese/")),
            'for-tag-unpack14'=>array("{% for x,y in items %}{{ x }}:{{ y }}/{% endfor %}", array("items"=>array(1, 2)), array(":/:/", "INVALID:INVALID/INVALID:INVALID/")),
            'for-tag-empty01'=>array("{% for val in values %}{{ val }}{% empty %}empty text{% endfor %}", array("values"=>array(1, 2, 3)), "123"),
            'for-tag-empty02'=>array("{% for val in values %}{{ val }}{% empty %}values array empty{% endfor %}", array("values"=>array()), "values array empty"),
            'for-tag-empty03'=>array("{% for val in values %}{{ val }}{% empty %}values array not found{% endfor %}", array(), "values array not found"),

            // ### IF TAG ################################################################
            'if-tag01'=>array("{% if foo %}yes{% else %}no{% endif %}", array("foo"=>True), "yes"),
            'if-tag02'=>array("{% if foo %}yes{% else %}no{% endif %}", array("foo"=>False), "no"),
            'if-tag03'=>array("{% if foo %}yes{% else %}no{% endif %}", array(), "no"),

            'if-tag04'=>array("{% if foo %}foo{% elif bar %}bar{% endif %}", array('foo'=>True), "foo"),
            'if-tag05'=>array("{% if foo %}foo{% elif bar %}bar{% endif %}", array('bar'=>True), "bar"),
            'if-tag06'=>array("{% if foo %}foo{% elif bar %}bar{% endif %}", array(), ""),
            'if-tag07'=>array("{% if foo %}foo{% elif bar %}bar{% else %}nothing{% endif %}", array('foo'=>True), "foo"),
            'if-tag08'=>array("{% if foo %}foo{% elif bar %}bar{% else %}nothing{% endif %}", array('bar'=>True), "bar"),
            'if-tag09'=>array("{% if foo %}foo{% elif bar %}bar{% else %}nothing{% endif %}", array(), "nothing"),
            'if-tag10'=>array("{% if foo %}foo{% elif bar %}bar{% elif baz %}baz{% else %}nothing{% endif %}", array('foo'=>True), "foo"),
            'if-tag11'=>array("{% if foo %}foo{% elif bar %}bar{% elif baz %}baz{% else %}nothing{% endif %}", array('bar'=>True), "bar"),
            'if-tag12'=>array("{% if foo %}foo{% elif bar %}bar{% elif baz %}baz{% else %}nothing{% endif %}", array('baz'=>True), "baz"),
            'if-tag13'=>array("{% if foo %}foo{% elif bar %}bar{% elif baz %}baz{% else %}nothing{% endif %}", array(), "nothing"),

            // Filters
            'if-tag-filter01'=>array("{% if foo|length == 5 %}yes{% else %}no{% endif %}", array('foo'=>'abcde'), "yes"),
            'if-tag-filter02'=>array("{% if foo|upper == 'ABC' %}yes{% else %}no{% endif %}", array(), "no"),

            // Equality
            'if-tag-eq01'=>array("{% if foo == bar %}yes{% else %}no{% endif %}", array(), "yes"),
            'if-tag-eq02'=>array("{% if foo == bar %}yes{% else %}no{% endif %}", array('foo'=>1), "no"),
            'if-tag-eq03'=>array("{% if foo == bar %}yes{% else %}no{% endif %}", array('foo'=>1, 'bar'=>1), "yes"),
            'if-tag-eq04'=>array("{% if foo == bar %}yes{% else %}no{% endif %}", array('foo'=>1, 'bar'=>2), "no"),
            'if-tag-eq05'=>array("{% if foo == '' %}yes{% else %}no{% endif %}", array(), "no"),

            // Comparison
            'if-tag-gt-01'=>array("{% if 2 > 1 %}yes{% else %}no{% endif %}", array(), "yes"),
            'if-tag-gt-02'=>array("{% if 1 > 1 %}yes{% else %}no{% endif %}", array(), "no"),
            'if-tag-gte-01'=>array("{% if 1 >= 1 %}yes{% else %}no{% endif %}", array(), "yes"),
            'if-tag-gte-02'=>array("{% if 1 >= 2 %}yes{% else %}no{% endif %}", array(), "no"),
            'if-tag-lt-01'=>array("{% if 1 < 2 %}yes{% else %}no{% endif %}", array(), "yes"),
            'if-tag-lt-02'=>array("{% if 1 < 1 %}yes{% else %}no{% endif %}", array(), "no"),
            'if-tag-lte-01'=>array("{% if 1 <= 1 %}yes{% else %}no{% endif %}", array(), "yes"),
            'if-tag-lte-02'=>array("{% if 2 <= 1 %}yes{% else %}no{% endif %}", array(), "no"),

            // Contains
            'if-tag-in-01'=>array("{% if 1 in x %}yes{% else %}no{% endif %}", array('x'=>array(1)), "yes"),
            'if-tag-in-02'=>array("{% if 2 in x %}yes{% else %}no{% endif %}", array('x'=>array(1)), "no"),
            'if-tag-not-in-01'=>array("{% if 1 not in x %}yes{% else %}no{% endif %}", array('x'=>array(1)), "no"),
            'if-tag-not-in-02'=>array("{% if 2 not in x %}yes{% else %}no{% endif %}", array('x'=>array(1)), "yes"),

            // AND
            'if-tag-and01'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'yes'),
            'if-tag-and02'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'no'),
            'if-tag-and03'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'no'),
            'if-tag-and04'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'no'),
            'if-tag-and05'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>False), 'no'),
            'if-tag-and06'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('bar'=>False), 'no'),
            'if-tag-and07'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('foo'=>True), 'no'),
            'if-tag-and08'=>array("{% if foo and bar %}yes{% else %}no{% endif %}", array('bar'=>True), 'no'),

            // OR
            'if-tag-or01'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'yes'),
            'if-tag-or02'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'yes'),
            'if-tag-or03'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'yes'),
            'if-tag-or04'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'no'),
            'if-tag-or05'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>False), 'no'),
            'if-tag-or06'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('bar'=>False), 'no'),
            'if-tag-or07'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('foo'=>True), 'yes'),
            'if-tag-or08'=>array("{% if foo or bar %}yes{% else %}no{% endif %}", array('bar'=>True), 'yes'),

            // multiple ORs
            'if-tag-or09'=>array("{% if foo or bar or baz %}yes{% else %}no{% endif %}", array('baz'=>True), 'yes'),

            // NOT
            'if-tag-not01'=>array("{% if not foo %}no{% else %}yes{% endif %}", array('foo'=>True), 'yes'),
            'if-tag-not02'=>array("{% if not not foo %}no{% else %}yes{% endif %}", array('foo'=>True), 'no'),
             // not03 to not05 removed, now TemplateSyntaxErrors

            'if-tag-not06'=>array("{% if foo and not bar %}yes{% else %}no{% endif %}", array(), 'no'),
            'if-tag-not07'=>array("{% if foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'no'),
            'if-tag-not08'=>array("{% if foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'yes'),
            'if-tag-not09'=>array("{% if foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'no'),
            'if-tag-not10'=>array("{% if foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'no'),

            'if-tag-not11'=>array("{% if not foo and bar %}yes{% else %}no{% endif %}", array(), 'no'),
            'if-tag-not12'=>array("{% if not foo and bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'no'),
            'if-tag-not13'=>array("{% if not foo and bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'no'),
            'if-tag-not14'=>array("{% if not foo and bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'yes'),
            'if-tag-not15'=>array("{% if not foo and bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'no'),

            'if-tag-not16'=>array("{% if foo or not bar %}yes{% else %}no{% endif %}", array(), 'yes'),
            'if-tag-not17'=>array("{% if foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'yes'),
            'if-tag-not18'=>array("{% if foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'yes'),
            'if-tag-not19'=>array("{% if foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'no'),
            'if-tag-not20'=>array("{% if foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'yes'),

            'if-tag-not21'=>array("{% if not foo or bar %}yes{% else %}no{% endif %}", array(), 'yes'),
            'if-tag-not22'=>array("{% if not foo or bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'yes'),
            'if-tag-not23'=>array("{% if not foo or bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'no'),
            'if-tag-not24'=>array("{% if not foo or bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'yes'),
            'if-tag-not25'=>array("{% if not foo or bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'yes'),

            'if-tag-not26'=>array("{% if not foo and not bar %}yes{% else %}no{% endif %}", array(), 'yes'),
            'if-tag-not27'=>array("{% if not foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'no'),
            'if-tag-not28'=>array("{% if not foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'no'),
            'if-tag-not29'=>array("{% if not foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'no'),
            'if-tag-not30'=>array("{% if not foo and not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'yes'),

            'if-tag-not31'=>array("{% if not foo or not bar %}yes{% else %}no{% endif %}", array(), 'yes'),
            'if-tag-not32'=>array("{% if not foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>True), 'no'),
            'if-tag-not33'=>array("{% if not foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>True, 'bar'=>False), 'yes'),
            'if-tag-not34'=>array("{% if not foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>True), 'yes'),
            'if-tag-not35'=>array("{% if not foo or not bar %}yes{% else %}no{% endif %}", array('foo'=>False, 'bar'=>False), 'yes'),

            // Various syntax errors
            'if-tag-error01'=>array("{% if %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error02'=>array("{% if foo and %}yes{% else %}no{% endif %}", array('foo'=>True), 'TemplateSyntaxError'),
            'if-tag-error03'=>array("{% if foo or %}yes{% else %}no{% endif %}", array('foo'=>True), 'TemplateSyntaxError'),
            'if-tag-error04'=>array("{% if not foo and %}yes{% else %}no{% endif %}", array('foo'=>True), 'TemplateSyntaxError'),
            'if-tag-error05'=>array("{% if not foo or %}yes{% else %}no{% endif %}", array('foo'=>True), 'TemplateSyntaxError'),
            'if-tag-error06'=>array("{% if abc def %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error07'=>array("{% if not %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error08'=>array("{% if and %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error09'=>array("{% if or %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error10'=>array("{% if == %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error11'=>array("{% if 1 == %}yes{% endif %}", array(), 'TemplateSyntaxError'),
            'if-tag-error12'=>array("{% if a not b %}yes{% endif %}", array(), 'TemplateSyntaxError'),

            // If evaluations are shortcircuited where possible
            // If is_bad is invoked, it will raise a ShouldNotExecuteException
            'if-tag-shortcircuit01'=>array('{% if x.is_true or x.is_bad %}yes{% else %}no{% endif %}', array('x'=>new TestObj()), "yes"),
            'if-tag-shortcircuit02'=>array('{% if x.is_false and x.is_bad %}yes{% else %}no{% endif %}', array('x'=>new TestObj()), "no"),

            // Non-existent args
            'if-tag-badarg01'=>array("{% if x|default_if_none:y %}yes{% endif %}", array(), ''),
            'if-tag-badarg02'=>array("{% if x|default_if_none:y %}yes{% endif %}", array('y'=>0), ''),
            'if-tag-badarg03'=>array("{% if x|default_if_none:y %}yes{% endif %}", array('y'=>1), 'yes'),
            'if-tag-badarg04'=>array("{% if x|default_if_none:y %}yes{% else %}no{% endif %}", array(), 'no'),

            // Additional, more precise parsing tests are in SmartIfTests

            // ### IFCHANGED TAG #########################################################
            'ifchanged01'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% endfor %}', array('num'=>array(1,2,3)), '123'),
            'ifchanged02'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% endfor %}', array('num'=>array(1,1,3)), '13'),
            'ifchanged03'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% endfor %}', array('num'=>array(1,1,1)), '1'),
            'ifchanged04'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% for x in numx %}{% ifchanged %}{{ x }}{% endifchanged %}{% endfor %}{% endfor %}', array('num'=>array(1, 2, 3), 'numx'=>array(2, 2, 2)), '122232'),
            'ifchanged05'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% for x in numx %}{% ifchanged %}{{ x }}{% endifchanged %}{% endfor %}{% endfor %}', array('num'=>array(1, 1, 1), 'numx'=>array(1, 2, 3)), '1123123123'),
            'ifchanged06'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% for x in numx %}{% ifchanged %}{{ x }}{% endifchanged %}{% endfor %}{% endfor %}', array('num'=>array(1, 1, 1), 'numx'=>array(2, 2, 2)), '1222'),
            'ifchanged07'=>array('{% for n in num %}{% ifchanged %}{{ n }}{% endifchanged %}{% for x in numx %}{% ifchanged %}{{ x }}{% endifchanged %}{% for y in numy %}{% ifchanged %}{{ y }}{% endifchanged %}{% endfor %}{% endfor %}{% endfor %}', array('num'=>array(1, 1, 1), 'numx'=>array(2, 2, 2), 'numy'=>array(3, 3, 3)), '1233323332333'),
            //'ifchanged08'=>array('{% for data in datalist %}{% for c,d in data %}{% if c %}{% ifchanged %}{{ d }}{% endifchanged %}{% endif %}{% endfor %}{% endfor %}', array('datalist'=>array(array(array(1, 'a'), array(1, 'a'), array(0, 'b'), array(1, 'c')), array(array(0, 'a'), array(1, 'c'), array(1, 'd'), array(1, 'd'), array(0, 'e')))), 'accd'),

            // Test one parameter given to ifchanged.
            'ifchanged-param01'=>array('{% for n in num %}{% ifchanged n %}..{% endifchanged %}{{ n }}{% endfor %}', array('num'=>array(1,2,3)), '..1..2..3'),
            'ifchanged-param02'=>array('{% for n in num %}{% for x in numx %}{% ifchanged n %}..{% endifchanged %}{{ x }}{% endfor %}{% endfor %}', array('num'=>array(1,2,3), 'numx'=>array(5,6,7)), '..567..567..567'),

            // Test multiple parameters to ifchanged.
            'ifchanged-param03'=>array('{% for n in num %}{{ n }}{% for x in numx %}{% ifchanged x n %}{{ x }}{% endifchanged %}{% endfor %}{% endfor %}', array('num'=>array(1,1,2), 'numx'=>array(5,6,6) ), '156156256'),

            // Test a date+hour like construct, where the hour of the last day is the same but the date had changed, so print the hour anyway.
            'ifchanged-param04'=>array('{% for d in days %}{% ifchanged %}{{ d.day }}{% endifchanged %}{% for h in d.hours %}{% ifchanged d h %}{{ h }}{% endifchanged %}{% endfor %}{% endfor %}', array('days'=>array(array('day'=>1, 'hours'=>array(1,2,3)),array('day'=>2, 'hours'=>array(3)))), '112323'),

            // Logically the same as above, just written with explicit ifchanged for the day.
            'ifchanged-param05'=>array('{% for d in days %}{% ifchanged d.day %}{{ d.day }}{% endifchanged %}{% for h in d.hours %}{% ifchanged d.day h %}{{ h }}{% endifchanged %}{% endfor %}{% endfor %}', array('days'=>array(array('day'=>1, 'hours'=>array(1,2,3)),array('day'=>2, 'hours'=>array(3))) ), '112323'),

            // Test the else clause of ifchanged.
            'ifchanged-else01'=>array('{% for id in ids %}{{ id }}{% ifchanged id %}-first{% else %}-other{% endifchanged %},{% endfor %}', array('ids'=>array(1,1,2,2,2,3)), '1-first,1-other,2-first,2-other,2-other,3-first,'),

            'ifchanged-else02'=>array('{% for id in ids %}{{ id }}-{% ifchanged id %}{% cycle red,blue %}{% else %}grey{% endifchanged %},{% endfor %}', array('ids'=>array(1,1,2,2,2,3)), '1-red,1-grey,2-blue,2-grey,2-grey,3-red,'),
            'ifchanged-else03'=>array('{% for id in ids %}{{ id }}{% ifchanged id %}-{% cycle red,blue %}{% else %}{% endifchanged %},{% endfor %}', array('ids'=>array(1,1,2,2,2,3)), '1-red,1,2-blue,2,2,3-red,'),

            'ifchanged-else04'=>array('{% for id in ids %}{% ifchanged %}***{{ id }}*{% else %}...{% endifchanged %}{{ forloop.counter }}{% endfor %}', array('ids'=>array(1,1,2,2,2,3,4)), '***1*1...2***2*3...4...5***3*6***4*7'),

            // ### IFNOTEQUAL TAG ########################################################
            'ifnotequal01'=>array("{% ifnotequal a b %}yes{% endifnotequal %}", array("a"=>1, "b"=>2), "yes"),
            'ifnotequal02'=>array("{% ifnotequal a b %}yes{% endifnotequal %}", array("a"=>1, "b"=>1), ""),
            'ifnotequal03'=>array("{% ifnotequal a b %}yes{% else %}no{% endifnotequal %}", array("a"=>1, "b"=>2), "yes"),
            'ifnotequal04'=>array("{% ifnotequal a b %}yes{% else %}no{% endifnotequal %}", array("a"=>1, "b"=>1), "no"),

            // ## INCLUDE TAG ###########################################################
            'include01'=>array('{% include "basic-syntax01" %}', array(), "something cool"),
            'include02'=>array('{% include "basic-syntax02" %}', array('headline'=>'Included'), "Included"),
            'include03'=>array('{% include template_name %}', array('template_name'=>'basic-syntax02', 'headline'=>'Included'), "Included"),
            'include04'=>array('a{% include "nonexistent" %}b', array(), array("ab", "ab", 'TemplateDoesNotExist')),
            'include 05'=>array('template with a space', array(), 'template with a space'),
            'include06'=>array('{% include "include 05"%}', array(), 'template with a space'),

            // extra inline context
            'include07'=>array('{% include "basic-syntax02" with headline="Inline" %}', array('headline'=>'Included'), 'Inline'),
            'include08'=>array('{% include headline with headline="Dynamic" %}', array('headline'=>'basic-syntax02'), 'Dynamic'),
            'include09'=>array('{{ first }}--{% include "basic-syntax03" with first=second|lower|upper second=first|upper %}--{{ second }}', array('first'=>'Ul', 'second'=>'lU'), 'Ul--LU --- UL--lU'),

            // isolated context
            'include10'=>array('{% include "basic-syntax03" only %}', array('first'=>'1'), array(' --- ', 'INVALID --- INVALID')),
            'include11'=>array('{% include "basic-syntax03" only with second=2 %}', array('first'=>'1'), array(' --- 2', 'INVALID --- 2')),
            'include12'=>array('{% include "basic-syntax03" with first=1 only %}', array('second'=>'2'), array('1 --- ', '1 --- INVALID')),

            // autoescape context
            'include13'=>array('{% autoescape off %}{% include "basic-syntax03" %}{% endautoescape %}', array('first'=>'&'), array('& --- ', '& --- INVALID')),
            'include14'=>array('{% autoescape off %}{% include "basic-syntax03" with first=var1 only %}{% endautoescape %}', array('var1'=>'&'), array('& --- ', '& --- INVALID')),

            'include-error01'=>array('{% include "basic-syntax01" with %}', array(), 'TemplateSyntaxError'),
            'include-error02'=>array('{% include "basic-syntax01" with "no key" %}', array(), 'TemplateSyntaxError'),
            'include-error03'=>array('{% include "basic-syntax01" with dotted.arg="error" %}', array(), 'TemplateSyntaxError'),
            'include-error04'=>array('{% include "basic-syntax01" something_random %}', array(), 'TemplateSyntaxError'),
            'include-error05'=>array('{% include "basic-syntax01" foo="duplicate" foo="key" %}', array(), 'TemplateSyntaxError'),
            'include-error06'=>array('{% include "basic-syntax01" only only %}', array(), 'TemplateSyntaxError'),
            
            // ### INCLUSION ERROR REPORTING #############################################
            'include-fail1'=>array('{% load bad_tag %}{% badtag %}', array(), 'RuntimeError'),
            'include-fail2'=>array('{% load broken_tag %}', array(), 'TemplateSyntaxError'),
            'include-error07'=>array('{% include "include-fail1" %}', array(), array('', '', 'RuntimeError')),
            'include-error08'=>array('{% include "include-fail2" %}', array(), array('', '', 'TemplateSyntaxError')),
            'include-error09'=>array('{% include failed_include %}', array('failed_include'=>'include-fail1'), array('', '', 'RuntimeError')),
            'include-error10'=>array('{% include failed_include %}', array('failed_include'=>'include-fail2'), array('', '', 'TemplateSyntaxError')),

            // ### NAMED ENDBLOCKS #######################################################

            // Basic test
            'namedendblocks01'=>array("1{% block first %}_{% block second %}2{% endblock second %}_{% endblock first %}3", array(), '1_2_3'),

            // Unbalanced blocks
            'namedendblocks02'=>array("1{% block first %}_{% block second %}2{% endblock first %}_{% endblock second %}3", array(), 'TemplateSyntaxError'),
            'namedendblocks03'=>array("1{% block first %}_{% block second %}2{% endblock %}_{% endblock second %}3", array(), 'TemplateSyntaxError'),
            'namedendblocks04'=>array("1{% block first %}_{% block second %}2{% endblock second %}_{% endblock third %}3", array(), 'TemplateSyntaxError'),
            'namedendblocks05'=>array("1{% block first %}_{% block second %}2{% endblock first %}", array(), 'TemplateSyntaxError'),

            // Mixed named and unnamed endblocks
            'namedendblocks06'=>array("1{% block first %}_{% block second %}2{% endblock %}_{% endblock first %}3", array(), '1_2_3'),
            'namedendblocks07'=>array("1{% block first %}_{% block second %}2{% endblock second %}_{% endblock %}3", array(), '1_2_3'),

            ### INHERITANCE ###########################################################

            // Standard template with no inheritance
            'inheritance01'=>array("1{% block first %}&{% endblock %}3{% block second %}_{% endblock %}", array(), '1&3_'),

            // Standard two-level inheritance
            'inheritance02'=>array("{% extends 'inheritance01' %}{% block first %}2{% endblock %}{% block second %}4{% endblock %}", array(), '1234'),

            // Three-level with no redefinitions on third level
            'inheritance03'=>array("{% extends 'inheritance02' %}", array(), '1234'),

            // Two-level with no redefinitions on second level
            'inheritance04'=>array("{% extends 'inheritance01' %}", array(), '1&3_'),

            // Two-level with double quotes instead of single quotes
            'inheritance05'=>array('{% extends "inheritance02" %}', array(), '1234'),

            // Three-level with variable parent-template name
            'inheritance06'=>array("{% extends foo %}", array('foo'=>'inheritance02'), '1234'),

            // Two-level with one block defined, one block not defined
            'inheritance07'=>array("{% extends 'inheritance01' %}{% block second %}5{% endblock %}", array(), '1&35'),

            // Three-level with one block defined on this level, two blocks defined next level
            'inheritance08'=>array("{% extends 'inheritance02' %}{% block second %}5{% endblock %}", array(), '1235'),

            // Three-level with second and third levels blank
            'inheritance09'=>array("{% extends 'inheritance04' %}", array(), '1&3_'),

            // Three-level with space NOT in a block -- should be ignored
            'inheritance10'=>array("{% extends 'inheritance04' %}      ", array(), '1&3_'),

            // Three-level with both blocks defined on this level, but none on second level
            'inheritance11'=>array("{% extends 'inheritance04' %}{% block first %}2{% endblock %}{% block second %}4{% endblock %}", array(), '1234'),

            // Three-level with this level providing one and second level providing the other
            'inheritance12'=>array("{% extends 'inheritance07' %}{% block first %}2{% endblock %}", array(), '1235'),

            // Three-level with this level overriding second level
            'inheritance13'=>array("{% extends 'inheritance02' %}{% block first %}a{% endblock %}{% block second %}b{% endblock %}", array(), '1a3b'),

            // A block defined only in a child template shouldn't be displayed
            'inheritance14'=>array("{% extends 'inheritance01' %}{% block newblock %}NO DISPLAY{% endblock %}", array(), '1&3_'),

            // A block within another block
            'inheritance15'=>array("{% extends 'inheritance01' %}{% block first %}2{% block inner %}inner{% endblock %}{% endblock %}", array(), '12inner3_'),

            // A block within another block (level 2)
            'inheritance16'=>array("{% extends 'inheritance15' %}{% block inner %}out{% endblock %}", array(), '12out3_'),

            // {% load %} tag (parent -- setup for exception04)
            'inheritance17'=>array("{% load testtags %}{% block first %}1234{% endblock %}", array(), '1234'),

            // {% load %} tag (standard usage, without inheritance)
            'inheritance18'=>array("{% load testtags %}{% echo this that theother %}5678", array(), 'this that theother5678'),

            // {% load %} tag (within a child template)
            'inheritance19'=>array("{% extends 'inheritance01' %}{% block first %}{% load testtags %}{% echo 400 %}5678{% endblock %}", array(), '140056783_'),

            // Two-level inheritance with {{ block.super }}
            'inheritance20'=>array("{% extends 'inheritance01' %}{% block first %}{{ block.super }}a{% endblock %}", array(), '1&a3_'),

            // Three-level inheritance with {{ block.super }} from parent
            'inheritance21'=>array("{% extends 'inheritance02' %}{% block first %}{{ block.super }}a{% endblock %}", array(), '12a34'),

            // Three-level inheritance with {{ block.super }} from grandparent
            'inheritance22'=>array("{% extends 'inheritance04' %}{% block first %}{{ block.super }}a{% endblock %}", array(), '1&a3_'),

            // Three-level inheritance with {{ block.super }} from parent and grandparent
            'inheritance23'=>array("{% extends 'inheritance20' %}{% block first %}{{ block.super }}b{% endblock %}", array(), '1&ab3_'),

            // Inheritance from local context without use of template loader
            'inheritance24'=>array("{% extends context_template %}{% block first %}2{% endblock %}{% block second %}4{% endblock %}", array('context_template'=>new Template("1{% block first %}_{% endblock %}3{% block second %}_{% endblock %}")), '1234'),

            // Inheritance from local context with variable parent template
            'inheritance25'=>array("{% extends context_template.1 %}{% block first %}2{% endblock %}{% block second %}4{% endblock %}", array('context_template'=>array(new Template("Wrong"), new Template("1{% block first %}_{% endblock %}3{% block second %}_{% endblock %}"))), '1234'),

            // Set up a base template to extend
            'inheritance26'=>array("no tags", array(), 'no tags'),

            // Inheritance from a template that doesn't have any blocks
            'inheritance27'=>array("{% extends 'inheritance26' %}", array(), 'no tags'),

            // Set up a base template with a space in it.
            'inheritance 28'=>array("{% block first %}!{% endblock %}", array(), '!'),

            // Inheritance from a template with a space in its name should work.
            'inheritance29'=>array("{% extends 'inheritance 28' %}", array(), '!'),

            // Base template, putting block in a conditional {% if %} tag
            'inheritance30'=>array("1{% if optional %}{% block opt %}2{% endblock %}{% endif %}3", array('optional'=>True), '123'),

            // Inherit from a template with block wrapped in an {% if %} tag (in parent), still gets overridden
            'inheritance31'=>array("{% extends 'inheritance30' %}{% block opt %}two{% endblock %}", array('optional'=>True), '1two3'),
            'inheritance32'=>array("{% extends 'inheritance30' %}{% block opt %}two{% endblock %}", array(), '13'),

            // Base template, putting block in a conditional {% ifequal %} tag
            'inheritance33'=>array("1{% ifequal optional 1 %}{% block opt %}2{% endblock %}{% endifequal %}3", array('optional'=>1), '123'),

            // Inherit from a template with block wrapped in an {% ifequal %} tag (in parent), still gets overridden
            'inheritance34'=>array("{% extends 'inheritance33' %}{% block opt %}two{% endblock %}", array('optional'=>1), '1two3'),
            'inheritance35'=>array("{% extends 'inheritance33' %}{% block opt %}two{% endblock %}", array('optional'=>2), '13'),

            // Base template, putting block in a {% for %} tag
            'inheritance36'=>array("{% for n in numbers %}_{% block opt %}{{ n }}{% endblock %}{% endfor %}_", array('numbers'=>'123'), '_1_2_3_'),

            // Inherit from a template with block wrapped in an {% for %} tag (in parent), still gets overridden
            'inheritance37'=>array("{% extends 'inheritance36' %}{% block opt %}X{% endblock %}", array('numbers'=>'123'), '_X_X_X_'),
            'inheritance38'=>array("{% extends 'inheritance36' %}{% block opt %}X{% endblock %}", array(), '_'),

            // The super block will still be found.
            'inheritance39'=>array("{% extends 'inheritance30' %}{% block opt %}new{{ block.super }}{% endblock %}", array('optional'=>True), '1new23'),
            'inheritance40'=>array("{% extends 'inheritance33' %}{% block opt %}new{{ block.super }}{% endblock %}", array('optional'=>1), '1new23'),
            'inheritance41'=>array("{% extends 'inheritance36' %}{% block opt %}new{{ block.super }}{% endblock %}", array('numbers'=>'123'), '_new1_new2_new3_'),

            // ### LOADING TAG LIBRARIES #################################################
            'load01'=>array("{% load testtags subpackage.echo %}{% echo test %} {% echo2 \"test\" %}", array(), "test test"),
            'load02'=>array("{% load subpackage.echo %}{% echo2 \"test\" %}", array(), "test"),

            // {% load %} tag, importing individual tags
            'load03'=>array("{% load echo from testtags %}{% echo this that theother %}", array(), 'this that theother'),
            'load04'=>array("{% load echo other_echo from testtags %}{% echo this that theother %} {% other_echo and another thing %}", array(), 'this that theother and another thing'),
            'load05'=>array("{% load echo upper from testtags %}{% echo this that theother %} {{ statement|upper }}", array('statement'=>'not shouting'), 'this that theother NOT SHOUTING'),
            'load06'=>array("{% load echo2 from subpackage.echo %}{% echo2 \"test\" %}", array(), "test"),

            // {% load %} tag errors
            'load07'=>array("{% load echo other_echo bad_tag from testtags %}", array(), 'TemplateSyntaxError'),
            'load08'=>array("{% load echo other_echo bad_tag from %}", array(), 'TemplateSyntaxError'),
            'load09'=>array("{% load from testtags %}", array(), 'TemplateSyntaxError'),
            'load10'=>array("{% load echo from bad_library %}", array(), 'TemplateSyntaxError'),
            'load11'=>array("{% load subpackage.echo_invalid %}", array(), 'TemplateSyntaxError'),
            'load12'=>array("{% load subpackage.missing %}", array(), 'TemplateSyntaxError'),
            
            // {% spaceless %} tag
            'spaceless01'=>array("{% spaceless %} <b>    <i> text </i>    </b> {% endspaceless %}", array(), "<b><i> text </i></b>"),
            'spaceless02'=>array("{% spaceless %} <b> \n <i> text </i> \n </b> {% endspaceless %}", array(), "<b><i> text </i></b>"),
            'spaceless03'=>array("{% spaceless %}<b><i>text</i></b>{% endspaceless %}", array(), "<b><i>text</i></b>"),
            'spaceless04'=>array("{% spaceless %}<b>   <i>{{ text }}</i>  </b>{% endspaceless %}", array('text'=>'This & that'), "<b><i>This &amp; that</i></b>"),
            'spaceless05'=>array("{% autoescape off %}{% spaceless %}<b>   <i>{{ text }}</i>  </b>{% endspaceless %}{% endautoescape %}", array('text'=>'This & that'), "<b><i>This & that</i></b>"),
            'spaceless06'=>array("{% spaceless %}<b>   <i>{{ text|safe }}</i>  </b>{% endspaceless %}", array('text'=>'This & that'), "<b><i>This & that</i></b>"),
            
            // ------------------------------------------ I18N
            
            // ### HANDLING OF TEMPLATE_STRING_IF_INVALID ###################################

            'invalidstr01'=>array('{{ var|default:"Foo" }}', array(), array('Foo','INVALID')),
            'invalidstr02'=>array('{{ var|default_if_none:"Foo" }}', array(), array('','INVALID')),
            'invalidstr03'=>array('{% for v in var %}({{ v }}){% endfor %}', array(), ''),
            'invalidstr04'=>array('{% if var %}Yes{% else %}No{% endif %}', array(), 'No'),
            'invalidstr04_2'=>array('{% if var|default:"Foo" %}Yes{% else %}No{% endif %}', array(), 'Yes'),
            'invalidstr05'=>array('{{ var }}', array(), array('', array('INVALID %s', 'var'))),
            'invalidstr06'=>array('{{ var.prop }}', array('var'=>array()), array('', array('INVALID %s', 'var.prop'))),

            // ### MULTILINE #############################################################

            'multiline01'=>array('
                            Hello,
                            boys.
                            How
                            are
                            you
                            gentlemen.
                            ',
                            array(),
                            '
                            Hello,
                            boys.
                            How
                            are
                            you
                            gentlemen.
                            '),

            // ### REGROUP TAG ###########################################################
            'regroup01'=>array('{% regroup data by bar as grouped %}' .
                          '{% for group in grouped %}' .
                          '{{ group.grouper }}:' .
                          '{% for item in group.list %}' .
                          '{{ item.foo }}' .
                          '{% endfor %},' .
                          '{% endfor %}',
                          array('data'=>array(
                             array('foo'=>'c', 'bar'=>1),
                             array('foo'=>'d', 'bar'=>1),
                             array('foo'=>'a', 'bar'=>2),
                             array('foo'=>'b', 'bar'=>2),
                             array('foo'=>'x', 'bar'=>3)
                          )),
                          '1:cd,2:ab,3:x,'),

            // Test for silent failure when target variable isn't found
            'regroup02'=>array('{% regroup data by bar as grouped %}' .
                          '{% for group in grouped %}' .
                          '{{ group.grouper }}:' .
                          '{% for item in group.list %}' .
                          '{{ item.foo }}' .
                          '{% endfor %},' .
                          '{% endfor %}',
                          array(), ''),

            // ### SSI TAG ########################################################
// TODO ssi tag,
//            // Test normal behavior
//            'old-ssi01'=>array('{%% ssi %s %%}' % os.path.join(basedir, 'templates', 'ssi_include.html'), array(), 'This is for testing an ssi include. {{ test }}\n'),
//            'old-ssi02'=>array('{%% ssi %s %%}' % os.path.join(basedir, 'not_here'), array(), ''),
//
//            // Test parsed output
//            'old-ssi06'=>array('{%% ssi %s parsed %%}' % os.path.join(basedir, 'templates', 'ssi_include.html'), array('test'=>'Look ma! It parsed!'), 'This is for testing an ssi include. Look ma! It parsed!\n'),
//            'old-ssi07'=>array('{%% ssi %s parsed %%}' % os.path.join(basedir, 'not_here'), array('test'=>'Look ma! It parsed!'), ''),
//
//            // Test space in file name
//            'old-ssi08'=>array('{%% ssi %s %%}' % os.path.join(basedir, 'templates', 'ssi include with spaces.html'), array(), 'TemplateSyntaxError'),
//            'old-ssi09'=>array('{%% ssi %s parsed %%}' % os.path.join(basedir, 'templates', 'ssi include with spaces.html'), array('test'=>'Look ma! It parsed!'), 'TemplateSyntaxError'),
//
//            // Future compatibility
//            // Test normal behavior
//            'ssi01'=>array('{%% load ssi from future %%}{%% ssi "%s" %%}' % os.path.join(basedir, 'templates', 'ssi_include.html'), array(), 'This is for testing an ssi include. {{ test }}\n'),
//            'ssi02'=>array('{%% load ssi from future %%}{%% ssi "%s" %%}' % os.path.join(basedir, 'not_here'), array(), ''),
//            'ssi03'=>array("{%% load ssi from future %%}{%% ssi '%s' %%}" % os.path.join(basedir, 'not_here'), array(), ''),
//
//            // Test passing as a variable
//            'ssi04'=>array('{% load ssi from future %}{% ssi ssi_file %}', array('ssi_file'=>os.path.join(basedir, 'templates', 'ssi_include.html')), 'This is for testing an ssi include. {{ test }}\n'),
//            'ssi05'=>array('{% load ssi from future %}{% ssi ssi_file %}', array('ssi_file'=>'no_file'), ''),
//
//            // Test parsed output
//            'ssi06'=>array('{%% load ssi from future %%}{%% ssi "%s" parsed %%}' % os.path.join(basedir, 'templates', 'ssi_include.html'), array('test'=>'Look ma! It parsed!'), 'This is for testing an ssi include. Look ma! It parsed!\n'),
//            'ssi07'=>array('{%% load ssi from future %%}{%% ssi "%s" parsed %%}' % os.path.join(basedir, 'not_here'), array('test'=>'Look ma! It parsed!'), ''),
//
//            // Test space in file name
//            'ssi08'=>array('{%% load ssi from future %%}{%% ssi "%s" %%}' % os.path.join(basedir, 'templates', 'ssi include with spaces.html'), array(), 'This is for testing an ssi include with spaces in its name. {{ test }}\n'),
//            'ssi09'=>array('{%% load ssi from future %%}{%% ssi "%s" parsed %%}' % os.path.join(basedir, 'templates', 'ssi include with spaces.html'), array('test'=>'Look ma! It parsed!'), 'This is for testing an ssi include with spaces in its name. Look ma! It parsed!\n'),

            // ### TEMPLATETAG TAG #######################################################
            'templatetag01'=>array('{% templatetag openblock %}', array(), '{%'),
            'templatetag02'=>array('{% templatetag closeblock %}', array(), '%}'),
            'templatetag03'=>array('{% templatetag openvariable %}', array(), '{{'),
            'templatetag04'=>array('{% templatetag closevariable %}', array(), '}}'),
            'templatetag05'=>array('{% templatetag %}', array(), 'TemplateSyntaxError'),
            'templatetag06'=>array('{% templatetag foo %}', array(), 'TemplateSyntaxError'),
            'templatetag07'=>array('{% templatetag openbrace %}', array(), '{'),
            'templatetag08'=>array('{% templatetag closebrace %}', array(), '}'),
            'templatetag09'=>array('{% templatetag openbrace %}{% templatetag openbrace %}', array(), '{{'),
            'templatetag10'=>array('{% templatetag closebrace %}{% templatetag closebrace %}', array(), '}}'),
            'templatetag11'=>array('{% templatetag opencomment %}', array(), '{#'),
            'templatetag12'=>array('{% templatetag closecomment %}', array(), '#}'),

            // Simple tags with customized names
            'simpletag-renamed01'=>array('{% load custom %}{% minusone 7 %}', array(), '6'),
            'simpletag-renamed02'=>array('{% load custom %}{% minustwo 7 %}', array(), '5'),
            'simpletag-renamed03'=>array('{% load custom %}{% minustwo_overridden_name 7 %}', array(), 'TemplateSyntaxError'),

            // ### WIDTHRATIO TAG ########################################################
            'widthratio01'=>array('{% widthratio a b 0 %}', array('a'=>50,'b'=>100), '0'),
            'widthratio02'=>array('{% widthratio a b 100 %}', array('a'=>0,'b'=>0), '0'),
            'widthratio03'=>array('{% widthratio a b 100 %}', array('a'=>0,'b'=>100), '0'),
            'widthratio04'=>array('{% widthratio a b 100 %}', array('a'=>50,'b'=>100), '50'),
            'widthratio05'=>array('{% widthratio a b 100 %}', array('a'=>100,'b'=>100), '100'),

            // 62.5 should round to 63
            'widthratio06'=>array('{% widthratio a b 100 %}', array('a'=>50,'b'=>80), '63'),

            // 71.4 should round to 71
            'widthratio07'=>array('{% widthratio a b 100 %}', array('a'=>50,'b'=>70), '71'),

            // Raise exception if we don't have 3 args, last one an integer
            'widthratio08'=>array('{% widthratio %}', array(), 'TemplateSyntaxError'),
            'widthratio09'=>array('{% widthratio a b %}', array('a'=>50,'b'=>100), 'TemplateSyntaxError'),
            'widthratio10'=>array('{% widthratio a b 100.0 %}', array('a'=>50,'b'=>100), '50'),

            // #10043=> widthratio should allow max_width to be a variable
            'widthratio11'=>array('{% widthratio a b c %}', array('a'=>50,'b'=>100, 'c'=> 100), '50'),

            // ### WITH TAG ########################################################
            'with01'=>array('{% with key=dict.key %}{{ key }}{% endwith %}', array('dict'=>array('key'=>50)), '50'),
            'legacywith01'=>array('{% with dict.key as key %}{{ key }}{% endwith %}', array('dict'=>array('key'=>50)), '50'),

            'with02'=>array('{{ key }}{% with key=dict.key %}{{ key }}-{{ dict.key }}-{{ key }}{% endwith %}{{ key }}', array('dict'=>array('key'=>50)), array('50-50-50', 'INVALID50-50-50INVALID')),
            'legacywith02'=>array('{{ key }}{% with dict.key as key %}{{ key }}-{{ dict.key }}-{{ key }}{% endwith %}{{ key }}', array('dict'=>array('key'=>50)), array('50-50-50', 'INVALID50-50-50INVALID')),

            'with03'=>array('{% with a=alpha b=beta %}{{ a }}{{ b }}{% endwith %}', array('alpha'=>'A', 'beta'=>'B'), 'AB'),

            'with-error01'=>array('{% with dict.key xx key %}{{ key }}{% endwith %}', array('dict'=>array('key'=>50)), 'TemplateSyntaxError'),
            'with-error02'=>array('{% with dict.key as %}{{ key }}{% endwith %}', array('dict'=>array('key'=>50)), 'TemplateSyntaxError'),

            // ### NOW TAG ########################################################
            // Simple case
            'now01'=>array('{% now "j n Y" %}', array(), date('j') . ' ' . date('n') . ' ' . date('Y')),
            // Check parsing of locale strings
            'now02'=>array('{% now "DATE_FORMAT" %}', array(),  dja_date_format(time())),
            // Also accept simple quotes - #15092
            'now03'=>array("{% now 'j n Y' %}", array(), date('j') . ' ' . date('n') . ' ' . date('Y')),
            'now04'=>array("{% now 'DATE_FORMAT' %}", array(),  dja_date_format(time())),
            'now05'=>array('{% now \'j "n" Y\'%}', array(), date('j') . ' "' . date('n') . '" ' . date('Y')),
            'now06'=>array('{% now "j \'n\' Y"%}', array(), date('j') . ' \'' . date('n') . '\' ' . date('Y')),

             // ### URL TAG ########################################################

// TODO Implement generic url manager.
//            // Successes
//            'legacyurl02'=>array('{% url regressiontests.templates.views.client_action id=client.id,action="update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'legacyurl02a'=>array('{% url regressiontests.templates.views.client_action client.id,"update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'legacyurl02b'=>array("{% url regressiontests.templates.views.client_action id=client.id,action='update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'legacyurl02c'=>array("{% url regressiontests.templates.views.client_action client.id,'update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'legacyurl10'=>array('{% url regressiontests.templates.views.client_action id=client.id,action="two words" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/two%20words/'),
//            'legacyurl13'=>array('{% url regressiontests.templates.views.client_action id=client.id, action=arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'legacyurl14'=>array('{% url regressiontests.templates.views.client_action client.id, arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'legacyurl16'=>array('{% url regressiontests.templates.views.client_action action="update",id="1" %}', array(), '/url_tag/client/1/update/'),
//            'legacyurl16a'=>array("{% url regressiontests.templates.views.client_action action='update',id='1' %}", array(), '/url_tag/client/1/update/'),
//            'legacyurl17'=>array('{% url regressiontests.templates.views.client_action client_id=client.my_id,action=action %}', array('client'=>array('my_id'=>1), 'action'=>'update'), '/url_tag/client/1/update/'),
//
//            'old-url01'=>array('{% url regressiontests.templates.views.client client.id %}', array('client'=>array('id'=>1)), '/url_tag/client/1/'),
//            'old-url02'=>array('{% url regressiontests.templates.views.client_action id=client.id action="update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'old-url02a'=>array('{% url regressiontests.templates.views.client_action client.id "update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'old-url02b'=>array("{% url regressiontests.templates.views.client_action id=client.id action='update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'old-url02c'=>array("{% url regressiontests.templates.views.client_action client.id 'update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'old-url03'=>array('{% url regressiontests.templates.views.index %}', array(), '/url_tag/'),
//            'old-url04'=>array('{% url named.client client.id %}', array('client'=>array('id'=>1)), '/url_tag/named-client/1/'),
//            'old-url05'=>array('{% url метка_оператора v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'old-url06'=>array('{% url метка_оператора_2 tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'old-url07'=>array('{% url regressiontests.templates.views.client2 tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'old-url08'=>array('{% url метка_оператора v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'old-url09'=>array('{% url метка_оператора_2 tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'old-url10'=>array('{% url regressiontests.templates.views.client_action id=client.id action="two words" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/two%20words/'),
//            'old-url11'=>array('{% url regressiontests.templates.views.client_action id=client.id action="==" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/==/'),
//            'old-url12'=>array('{% url regressiontests.templates.views.client_action id=client.id action="," %}', array('client'=>array('id'=>1)), '/url_tag/client/1/,/'),
//            'old-url13'=>array('{% url regressiontests.templates.views.client_action id=client.id action=arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'old-url14'=>array('{% url regressiontests.templates.views.client_action client.id arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'old-url15'=>array('{% url regressiontests.templates.views.client_action 12 "test" %}', array(), '/url_tag/client/12/test/'),
//            'old-url16'=>array('{% url regressiontests.templates.views.client "1,2" %}', array(), '/url_tag/client/1,2/'),
//
//            // Failures
//            'old-url-fail01'=>array('{% url %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail02'=>array('{% url no_such_view %}', array(), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'old-url-fail03'=>array('{% url regressiontests.templates.views.client %}', array(), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'old-url-fail04'=>array('{% url view id, %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail05'=>array('{% url view id= %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail06'=>array('{% url view a.id=id %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail07'=>array('{% url view a.id!id %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail08'=>array('{% url view id="unterminatedstring %}', array(), 'TemplateSyntaxError'),
//            'old-url-fail09'=>array('{% url view id=", %}', array(), 'TemplateSyntaxError'),
//
//            // {% url ... as var %}
//            'old-url-asvar01'=>array('{% url regressiontests.templates.views.index as url %}', array(), ''),
//            'old-url-asvar02'=>array('{% url regressiontests.templates.views.index as url %}{{ url }}', array(), '/url_tag/'),
//            'old-url-asvar03'=>array('{% url no_such_view as url %}{{ url }}', array(), ''),
//
//            // forward compatibility
//            // Successes
//            'url01'=>array('{% load url from future %}{% url "regressiontests.templates.views.client" client.id %}', array('client'=>array('id'=>1)), '/url_tag/client/1/'),
//            'url02'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" id=client.id action="update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'url02a'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" client.id "update" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'url02b'=>array("{% load url from future %}{% url 'regressiontests.templates.views.client_action' id=client.id action='update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'url02c'=>array("{% load url from future %}{% url 'regressiontests.templates.views.client_action' client.id 'update' %}", array('client'=>array('id'=>1)), '/url_tag/client/1/update/'),
//            'url03'=>array('{% load url from future %}{% url "regressiontests.templates.views.index" %}', array(), '/url_tag/'),
//            'url04'=>array('{% load url from future %}{% url "named.client" client.id %}', array('client'=>array('id'=>1)), '/url_tag/named-client/1/'),
//            'url05'=>array('{% load url from future %}{% url "метка_оператора" v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'url06'=>array('{% load url from future %}{% url "метка_оператора_2" tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'url07'=>array('{% load url from future %}{% url "regressiontests.templates.views.client2" tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'url08'=>array('{% load url from future %}{% url "метка_оператора" v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'url09'=>array('{% load url from future %}{% url "метка_оператора_2" tag=v %}', array('v'=>'Ω'), '/url_tag/%D0%AE%D0%BD%D0%B8%D0%BA%D0%BE%D0%B4/%CE%A9/'),
//            'url10'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" id=client.id action="two words" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/two%20words/'),
//            'url11'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" id=client.id action="==" %}', array('client'=>array('id'=>1)), '/url_tag/client/1/==/'),
//            'url12'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" id=client.id action="," %}', array('client'=>array('id'=>1)), '/url_tag/client/1/,/'),
//            'url13'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" id=client.id action=arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'url14'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" client.id arg|join:"-" %}', array('client'=>array('id'=>1), 'arg'=>array('a','b')), '/url_tag/client/1/a-b/'),
//            'url15'=>array('{% load url from future %}{% url "regressiontests.templates.views.client_action" 12 "test" %}', array(), '/url_tag/client/12/test/'),
//            'url18'=>array('{% load url from future %}{% url "regressiontests.templates.views.client" "1,2" %}', array(), '/url_tag/client/1,2/'),
//
//            'url19'=>array('{% load url from future %}{% url named_url client.id %}', array('named_url'=>'regressiontests.templates.views.client', 'client'=>array('id'=>1)), '/url_tag/client/1/'),
//            'url20'=>array('{% load url from future %}{% url url_name_in_var client.id %}', array('url_name_in_var'=>'named.client', 'client'=>array('id'=>1)), '/url_tag/named-client/1/'),
//
//            // Failures
//            'url-fail01'=>array('{% load url from future %}{% url %}', array(), 'TemplateSyntaxError'),
//            'url-fail02'=>array('{% load url from future %}{% url "no_such_view" %}', array(), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'url-fail03'=>array('{% load url from future %}{% url "regressiontests.templates.views.client" %}', array(), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'url-fail04'=>array('{% load url from future %}{% url "view" id, %}', array(), 'TemplateSyntaxError'),
//            'url-fail05'=>array('{% load url from future %}{% url "view" id= %}', array(), 'TemplateSyntaxError'),
//            'url-fail06'=>array('{% load url from future %}{% url "view" a.id=id %}', array(), 'TemplateSyntaxError'),
//            'url-fail07'=>array('{% load url from future %}{% url "view" a.id!id %}', array(), 'TemplateSyntaxError'),
//            'url-fail08'=>array('{% load url from future %}{% url "view" id="unterminatedstring %}', array(), 'TemplateSyntaxError'),
//            'url-fail09'=>array('{% load url from future %}{% url "view" id=", %}', array(), 'TemplateSyntaxError'),
//
//            'url-fail11'=>array('{% load url from future %}{% url named_url %}', array(), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'url-fail12'=>array('{% load url from future %}{% url named_url %}', array('named_url'=>'no_such_view'), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'url-fail13'=>array('{% load url from future %}{% url named_url %}', array('named_url'=>'regressiontests.templates.views.client'), array('DjaUrlNoReverseMatch', 'DjaUrlNoReverseMatch')),
//            'url-fail14'=>array('{% load url from future %}{% url named_url id, %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//            'url-fail15'=>array('{% load url from future %}{% url named_url id= %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//            'url-fail16'=>array('{% load url from future %}{% url named_url a.id=id %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//            'url-fail17'=>array('{% load url from future %}{% url named_url a.id!id %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//            'url-fail18'=>array('{% load url from future %}{% url named_url id="unterminatedstring %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//            'url-fail19'=>array('{% load url from future %}{% url named_url id=", %}', array('named_url'=>'view'), 'TemplateSyntaxError'),
//
//            // {% url ... as var %}
//            'url-asvar01'=>array('{% load url from future %}{% url "regressiontests.templates.views.index" as url %}', array(), ''),
//            'url-asvar02'=>array('{% load url from future %}{% url "regressiontests.templates.views.index" as url %}{{ url }}', array(), '/url_tag/'),
//            'url-asvar03'=>array('{% load url from future %}{% url "no_such_view" as url %}{{ url }}', array(), ''),

            
            // ### CACHE TAG ######################################################
            'cache03'=>array('{% load cache %}{% cache 2 test %}cache03{% endcache %}', array(), 'cache03'),
            'cache04'=>array('{% load cache %}{% cache 2 test %}cache04{% endcache %}', array(), 'cache03'),
            'cache05'=>array('{% load cache %}{% cache 2 test foo %}cache05{% endcache %}', array('foo'=> 1), 'cache05'),
            'cache06'=>array('{% load cache %}{% cache 2 test foo %}cache06{% endcache %}', array('foo'=> 2), 'cache06'),
            'cache07'=>array('{% load cache %}{% cache 2 test foo %}cache07{% endcache %}', array('foo'=> 1), 'cache05'),

            // Allow first argument to be a variable.
            'cache08'=>array('{% load cache %}{% cache time test foo %}cache08{% endcache %}', array('foo'=> 2, 'time'=> 2), 'cache06'),

            // Raise exception if we don't have at least 2 args, first one integer.
            'cache11'=>array('{% load cache %}{% cache %}{% endcache %}', array(), 'TemplateSyntaxError'),
            'cache12'=>array('{% load cache %}{% cache 1 %}{% endcache %}', array(), 'TemplateSyntaxError'),
            'cache13'=>array('{% load cache %}{% cache foo bar %}{% endcache %}', array(), 'TemplateSyntaxError'),
            'cache14'=>array('{% load cache %}{% cache foo bar %}{% endcache %}', array('foo'=> 'fail'), 'TemplateSyntaxError'),
            'cache15'=>array('{% load cache %}{% cache foo bar %}{% endcache %}', array('foo'=> array()), 'TemplateSyntaxError'),

            // Regression test for #7460.
            'cache16'=>array('{% load cache %}{% cache 1 foo bar %}{% endcache %}', array('foo'=> 'foo', 'bar'=> 'with spaces'), ''),

            // Regression test for #11270.
            'cache17'=>array('{% load cache %}{% cache 10 long_cache_key poem %}Some Content{% endcache %}', array('poem'=> 'Oh freddled gruntbuggly/Thy micturations are to me/As plurdled gabbleblotchits/On a lurgid bee/That mordiously hath bitled out/Its earted jurtles/Into a rancid festering/Or else I shall rend thee in the gobberwarts with my blurglecruncheon/See if I dont.'), 'Some Content'),

            // ### AUTOESCAPE TAG ##############################################
            'autoescape-tag01'=>array("{% autoescape off %}hello{% endautoescape %}", array(), "hello"),
            'autoescape-tag02'=>array("{% autoescape off %}{{ first }}{% endautoescape %}", array("first"=>"<b>hello</b>"), "<b>hello</b>"),
            'autoescape-tag03'=>array("{% autoescape on %}{{ first }}{% endautoescape %}", array("first"=>"<b>hello</b>"), "&lt;b&gt;hello&lt;/b&gt;"),

            // Autoescape disabling and enabling nest in a predictable way.
            'autoescape-tag04'=>array("{% autoescape off %}{{ first }} {% autoescape  on%}{{ first }}{% endautoescape %}{% endautoescape %}", array("first"=>"<a>"), "<a> &lt;a&gt;"),

            'autoescape-tag05'=>array("{% autoescape on %}{{ first }}{% endautoescape %}", array("first"=>"<b>first</b>"), "&lt;b&gt;first&lt;/b&gt;"),

            // Strings (ASCII or unicode) already marked as "safe" are not auto-escaped
            'autoescape-tag06'=>array("{{ first }}", array("first"=>mark_safe("<b>first</b>")), "<b>first</b>"),
            'autoescape-tag07'=>array("{% autoescape on %}{{ first }}{% endautoescape %}", array("first"=>mark_safe("<b>Apple</b>")), "<b>Apple</b>"),

            // Literal string arguments to filters, if used in the result, are safe.
            'autoescape-tag08'=>array('{% autoescape on %}{{ var|default_if_none:" endquote\" hah" }}{% endautoescape %}', array("var"=>null), ' endquote" hah'),

            // Objects which return safe strings as their __unicode__ method won't get double-escaped.
            'autoescape-tag09'=>array('{{ unsafe }}', array('unsafe'=>new UnsafeClass()), 'you &amp; me'),
            'autoescape-tag10'=>array('{{ safe }}', array('safe'=>new SafeClass()), 'you &gt; me'),

            // The "safe" and "escape" filters cannot work due to internal
            // implementation details (fortunately, the (no)autoescape block tags can be used in those cases)
            'autoescape-filtertag01'=>array("{{ first }}{% filter safe %}{{ first }} x<y{% endfilter %}", array("first"=>"<a>"), 'TemplateSyntaxError'),

            // ifqeual compares unescaped vales.
            'autoescape-ifequal01'=>array('{% ifequal var "this & that" %}yes{% endifequal %}', array( "var"=>"this & that" ), "yes"),

            // Arguments to filters are 'safe' and manipulate their input unescaped.
            'autoescape-filters01'=>array('{{ var|cut:"&" }}', array( "var"=>"this & that" ), "this  that" ),
            'autoescape-filters02'=>array('{{ var|join:" & " }}', array( "var"=>array("Tom", "Dick", "Harry") ), "Tom & Dick & Harry"),

            // Literal strings are safe.
            'autoescape-literals01'=>array('{{ "this & that" }}',array(), "this & that"),

            // Iterating over strings outputs safe characters.
            'autoescape-stringiterations01'=>array('{% for l in var %}{{ l }},{% endfor %}', array('var'=>'K&R'), "K,&amp;,R,"),

            // Escape requirement survives lookup.
            'autoescape-lookup01'=>array('{{ var.key }}', array( "var"=>array("key"=>"this & that" )), "this &amp; that"),

            // ### Static template tags

// TODO Implement static taglib.
//            'static-prefixtag01'=>array('{% load static %}{% get_static_prefix %}', array(), Dja::getSetting('STATIC_URL')),
//            'static-prefixtag02'=>array('{% load static %}{% get_static_prefix as static_prefix %}{{ static_prefix }}', array(), Dja::getSetting('STATIC_URL')),
//            'static-prefixtag03'=>array('{% load static %}{% get_media_prefix %}', array(), Dja::getSetting('MEDIA_URL')),
//            'static-prefixtag04'=>array('{% load static %}{% get_media_prefix as media_prefix %}{{ media_prefix }}', array(), Dja::getSetting('MEDIA_URL')),
//            'static-statictag01'=>array('{% load static %}{% static "admin/base.css" %}', array(), Dja::urljoin(Dja::getSetting('STATIC_URL'), 'admin/base.css')),
//            'static-statictag02'=>array('{% load static %}{% static base_css %}', array('base_css'=>'admin/base.css'), Dja::urljoin(Dja::getSetting('STATIC_URL'), 'admin/base.css')),

        );

        return $tests;
    }

}


class TemplateTagLoading extends PHPUnit_Framework_TestCase {

    public function testLoadError() {
        $ttext = "{% load broken_tag %}";
        $this->setExpectedException('TemplateSyntaxError');
        new Template($ttext);
    }

}
