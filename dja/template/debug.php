<?php


class DjaDebug {

    const TEMPLATE_EXCEPTION_HTML = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta http-equiv="content-type" content="text/html; charset=utf-8">
        <meta name="robots" content="NONE,NOARCHIVE">
        <title>{% if exception_type %}{{ exception_type }}{% else %}Report{% endif %}{% if request %} at {{ request.path_info|escape }}{% endif %}</title>
        <style type="text/css">
            html * { padding:0; margin:0; }
            body * { padding:10px 20px; }
            body * * { padding:0; }
            body { font:small sans-serif; }
            body>div { border-bottom:1px solid #ddd; }
            h1 { font-weight:normal; }
            h2 { margin-bottom:.8em; }
            h2 span { font-size:80%; color:#666; font-weight:normal; }
            h3 { margin:1em 0 .5em 0; }
            code, pre { font-size: 100%; white-space: pre-wrap; }
            table { border:1px solid #ccc; border-collapse: collapse; width:100%; background:white; }
            tbody td, tbody th { vertical-align:middle; padding:2px 3px; }
            thead th { padding:1px 6px 1px 3px; background:#fefefe; text-align:left; font-weight:normal; font-size:11px; border:1px solid #ddd; }
            tbody th { width:12em; text-align:right; color:#666; padding-right:.5em; }
            table td.code { width:100%; }
            table td.code pre { overflow:hidden; }
            table.source th { color:#666; }
            table.source td { font-family:monospace; white-space:pre; border-bottom:1px solid #eee; }
            #summary { background: #ffe8e8; }
            #summary h2 { font-weight: normal; color: #666; }
            #explanation { background:#eee; }
            #template, #template-not-exist { background:#f6f6f6; }
            #template-not-exist ul { margin: 0 0 0 20px; }
            #summary table { border:none; background:transparent; }
            #requestinfo h2, #requestinfo h3 { position:relative; margin-left:-100px; }
            #requestinfo h3 { margin-bottom:-1em; }
            .error { background: #ffe8e8; }
            .specific { color:#880000; font-weight:bold; }
            pre.exception_value { font-family: sans-serif; color: #666; font-size: 1.5em; margin: 10px 0 10px 0; }
        </style>
    </head>
    <body>
    <div id="summary">
        <h1>{% if exception_type %}{{ exception_type }}{% else %}Report{% endif %}</h1>
        <pre class="exception_value">{% if exception_value %}{{ exception_value|force_escape }}{% else %}No exception supplied{% endif %}</pre>
        <table class="meta">
            <tr>
                <th>Dja Version:</th>
                <td>{{ dja_version_info }}</td>
            </tr>
            {% if exception_type %}
            <tr>
                <th>Exception Type:</th>
                <td>{{ exception_type }}</td>
            </tr>
            {% endif %}
            {% if exception_type and exception_value %}
            <tr>
                <th>Exception Value:</th>
                <td>
                    <pre>{{ exception_value|force_escape }}</pre>
                </td>
            </tr>
            {% endif %}
        </table>
    </div>

    {% if template_does_not_exist %}
    <div id="template-not-exist">
        <h2>Template-loader postmortem</h2>
        {% if loader_debug_info %}
        <p>Dja tried loading the following templates, in this order:</p>
        <ul>
            {% for loader in loader_debug_info %}
            <li>Using loader <code>{{ loader.loader }}</code>:
                <ul>{% for t in loader.templates %}
                    <li><code>{{ t.name }}</code> (File {% if t.exists %}exists{% else %}does not exist{% endif %})</li>
                    {% endfor %}
                </ul>
            </li>
            {% endfor %}
        </ul>
        {% else %}
        <p>Dja couldn\'t find any templates because <code>TEMPLATE_LOADERS</code> setting is empty.</p>
        {% endif %}
    </div>
    {% endif %}

    {% if template_info %}
    <div id="template">
        <h2>Error during template rendering</h2>

        <p>In template <code>{{ template_info.name }}</code>, error at line <strong>{{ template_info.line }}</strong></p>

        <h3>{{ template_info.message }}</h3>
        <table class="source{% if template_info.top %} cut-top{% endif %}{% ifnotequal template_info.bottom template_info.total %} cut-bottom{% endifnotequal %}">
            {% for source_line in template_info.source_lines %}
            {% ifequal source_line.0 template_info.line %}
            <tr class="error">
                <th>{{ source_line.0 }}</th>
                <td>{{ template_info.before }}<span class="specific">{{ template_info.during }}</span>{{ template_info.after }}</td>
            </tr>
            {% else %}
            <tr>
                <th>{{ source_line.0 }}</th>
                <td>{{ source_line.1 }}</td>
            </tr>
            {% endifequal %}
            {% endfor %}
        </table>
    </div>
    {% endif %}

    <div id="explanation">
        <p>
            You\'re seeing this error because of <code>TEMPLATE_DEBUG = True</code> in your
            Dja settings. Change it to <code>False</code>, and Dja won\'t handle exceptions.
        </p>
    </div>
    </body>
    </html>';

    private static function getTracebackData($template_file, $e) {
        $loader_debug_info = array();
        $template_does_not_exist = False;
        $template_info = null;

        if ($e instanceof TemplateDoesNotExist) {
            foreach (DjaLoader::$template_source_loaders as $loader) {
                $template_does_not_exist = True;
                $template_list = array();
                $tpl_sources = $loader->getTemplateSources($template_file);
                foreach ($tpl_sources as $t) {
                    $template_list[] = array('name'=>$t, 'exists'=>file_exists($t));
                }
                $loader_debug_info[] = array('loader'=>get_class($loader), 'templates'=>$template_list);
            }
        }

        if (isset($e->django_template_source)) {
            list($origin, $pos) = $e->django_template_source;
            list($start, $end) = $pos;
            $template_source = $origin->reload();

            $context_lines = 10;
            $line = 0;
            $upto = 0;
            $source_lines = array();
            $before = $during = $after = '';

            $linebreak_iter = function($source, &$last_pos) {
                if ($last_pos===null) {
                    $last_pos = 0;
                    return $last_pos;
                } elseif($last_pos===false) {
                    return -1;
                }
                $p = strpos($source, "\n", $last_pos);
                if ($p===false) {
                    $last_pos = strlen($source) + 1;  // TODO check unicode handling
                } else {
                    $last_pos = $p+1;
                }
                return $p;
            };

            $num = 0;
            $next = null;
            while (($r_ = $linebreak_iter($template_source, $next))!=-1) {
                if ($start>=$upto && $end<=$next) {
                    $line = $num;
                    $before = escape(py_slice($template_source, $upto, $start));
                    $during = escape(py_slice($template_source, $start, $end));
                    $after = escape(py_slice($template_source, $end, $next));
                }
                $source_lines[] = array($num, escape(py_slice($template_source, $upto, $next)));
                $upto = $next;

                if ($r_===false) {
                    $next = false;
                }
                $num++;
            }

            $total = count($source_lines);
            $top = max(1, $line - $context_lines);
            $bottom = min($total, $line + 1 + $context_lines);

            $template_info = array(
                'message'=>$e->getMessage(),
                'source_lines'=>py_slice($source_lines, $top, $bottom),
                'before'=>$before,
                'during'=>$during,
                'after'=>$after,
                'top'=>$top,
                'bottom'=>$bottom,
                'total'=>$total,
                'line'=>$line,
                'name'=>$origin->name,
            );
        }

        return array(
            'exception_type'=>get_class($e),
            'exception_value'=>$e->getMessage(),
            'dja_version_info'=>Dja::getVersion(),
            'template_info'=>$template_info,
            'template_does_not_exist'=>$template_does_not_exist,
            'loader_debug_info'=>$loader_debug_info,
        );
    }

    /**
     * Returns HTML code for Dja exception suitable
     * for debug information rendering.
     *
     * @static
     * @param string $template_file
     * @param DjaException $e
     * @return string
     */
    public static function getTracebackHtml($template_file, $e) {
        $t = new Template(DjaDebug::TEMPLATE_EXCEPTION_HTML, null, 'Technical 500 template');
        $c = new Context(self::getTracebackData($template_file, $e));
        return $t->render($c);
    }

}


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
                $result[] = $this->createToken(py_slice($this->template_string, $upto, $start), False, array($upto, $start));
                $upto = $start;
            }
            $result[] = $this->createToken(py_slice($this->template_string, $start, $end), True, array($start, $end));
            $upto = $end;
        }
        $last_bit = py_slice($this->template_string, $upto);
        if ($last_bit) {
            $result[] = $this->createToken($last_bit, False, array($upto, $upto + strlen($last_bit)));
        }
        return $result;
    }

    public function createToken($token_string, $in_tag, $source=null) {
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
        $e->django_template_source = $source;
        return $e;
    }

    public function createNodelist() {
        return new DebugNodeList();
    }

    public function createVariableNode($contents) {
        return new DebugVariableNode($contents);
    }

    public function extendNodelist(&$nodelist, $node, $token) {
        $node->source = $token->source;
        parent::extendNodelist($nodelist, $node, $token);
    }

    public function unclosedBlockTag($parse_until) {
        list($command, $source) = py_arr_pop($this->command_stack);
        $msg = 'Unclosed tag \'' . $command . '\'. Looking for one of: ' . join(', ', $parse_until);
        throw $this->sourceError($source, $msg);
    }

    public function compileFunctionError($token, $e) {
        if (!isset($e->django_template_source)) {
            $e->django_template_source = $token->source;
        }
    }
}


class DebugNodeList extends NodeList {

    public function renderNode($node, $context) {
        try {
            return $node->render($context);
        } catch (Exception $e) {
            if (!isset($e->django_template_source)) {
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
            if (!isset($e->django_template_source)) {
                $e->django_template_source = $this->source;
            }
            throw $e;
        }

        if (($context->autoescape && !($output instanceof SafeData)) || ($output instanceof EscapeData)) {
            return escape($output);
        }
        return $output;
    }
}
