<?php

/*
 * Tests for template filters (as opposed to template tags).
 *
 * The tests are hidden inside a function so that things like timestamps and
 * timezones are only evaluated at the moment of execution and will therefore be
 * consistent
 */


// These two classes are used to test auto-escaping of __invoke output.
class UnsafeClass {

    public function __invoke() {
        return 'you & me';
    }
}

class SafeClass {

    public function __invoke() {
        return mark_safe('you &gt; me');
    }
}


function get_ts($timestamp, $timezone) {
    $tz = new DateTimeZone($timezone);
    $time = new DateTime(null, $tz);
    $time->setTimestamp($timestamp);
    return $time->getTimestamp();
}


/*
 * RESULT SYNTAX --
 * 'template_name': ('template contents', 'context dict',
 *                 'expected string output' or Exception class)
 */
function get_filter_tests() {
    $now = time();
    $now_tz = new DateTimeZone(date_default_timezone_get()) ;
    $now_tz_i = new DateTimeZone('Pacific/Yap'); // imaginary time zone. * Not so imaginary for PHP as you might think.
    $today = time();
    
    return array(

        // TODO datetime-related filters
        //// Default compare with datetime.now()
        //'filter-timesince01' =>array('{{ a|timesince }}', array('a'=>datetime.now() + timedelta(minutes=-1, seconds = -10)), '1 minute'),
        //'filter-timesince02' =>array('{{ a|timesince }}', array('a'=>datetime.now() - timedelta(days=1, minutes = 1)), '1 day'),
        //'filter-timesince03' =>array('{{ a|timesince }}', array('a'=>datetime.now() - timedelta(hours=1, minutes=25, seconds = 10)), '1 hour, 25 minutes'),
        //
        //// Compare to a given parameter
        //'filter-timesince04' =>array('{{ a|timesince:b }}', array('a':now - timedelta(days=2), 'b':now - timedelta(days=1)), '1 day'),
        //'filter-timesince05' =>array('{{ a|timesince:b }}', array('a':now - timedelta(days=2, minutes=1), 'b':now - timedelta(days=2)), '1 minute'),
        //
        //// Check that timezone is respected
        //'filter-timesince06' =>array('{{ a|timesince:b }}', array('a':$now_tz - timedelta(hours=8), 'b':$now_tz), '8 hours'),
        //
        //// Regression for //7443
        //'filter-timesince07'=>array('{{ earlier|timesince }}', { 'earlier'=>now - timedelta(days=7) ), '1 week'),
        //'filter-timesince08'=>array('{{ earlier|timesince:now }}', { 'now'=>now, 'earlier'=>now - timedelta(days=7) ), '1 week'),
        //'filter-timesince09'=>array('{{ later|timesince }}', { 'later'=>now + timedelta(days=7) ), '0 minutes'),
        //'filter-timesince10'=>array('{{ later|timesince:now }}', { 'now'=>now, 'later'=>now + timedelta(days=7) ), '0 minutes'),
        //
        //// Ensures that differing timezones are calculated correctly
        //'filter-timesince11' =>array('{{ a|timesince }}', array('a'=>now), '0 minutes'),
        //'filter-timesince12' =>array('{{ a|timesince }}', array('a'=>$now_tz), '0 minutes'),
        //'filter-timesince13' =>array('{{ a|timesince }}', array('a'=>$$now_tz_i), '0 minutes'),
        //'filter-timesince14' =>array('{{ a|timesince:b }}', array('a'=>$now_tz, 'b'=>$$now_tz_i), '0 minutes'),
        //'filter-timesince15' =>array('{{ a|timesince:b }}', array('a'=>now, 'b'=>$$now_tz_i), ''),
        //'filter-timesince16' =>array('{{ a|timesince:b }}', array('a'=>$$now_tz_i, 'b'=>now), ''),
        //
        //// Regression for //9065 (two date objects).
        //'filter-timesince17' =>array('{{ a|timesince:b }}', array('a'=>today, 'b'=>today), '0 minutes'),
        //'filter-timesince18' =>array('{{ a|timesince:b }}', array('a'=>today, 'b'=>today + timedelta(hours=24)), '1 day'),
        //
        //// Default compare with datetime.now()
        //'filter-timeuntil01' =>array('{{ a|timeuntil }}', array('a':datetime.now() + timedelta(minutes=2, seconds = 10)), '2 minutes'),
        //'filter-timeuntil02' =>array('{{ a|timeuntil }}', array('a':(datetime.now() + timedelta(days=1, seconds = 10))), '1 day'),
        //'filter-timeuntil03' =>array('{{ a|timeuntil }}', array('a':(datetime.now() + timedelta(hours=8, minutes=10, seconds = 10))), '8 hours, 10 minutes'),
        //
        //// Compare to a given parameter
        //'filter-timeuntil04' =>array('{{ a|timeuntil:b }}', array('a':now - timedelta(days=1), 'b':now - timedelta(days=2)), '1 day'),
        //'filter-timeuntil05' =>array('{{ a|timeuntil:b }}', array('a':now - timedelta(days=2), 'b':now - timedelta(days=2, minutes=1)), '1 minute'),
        //
        //// Regression for //7443
        //'filter-timeuntil06'=>array('{{ earlier|timeuntil }}', { 'earlier'=>now - timedelta(days=7) ), '0 minutes'),
        //'filter-timeuntil07'=>array('{{ earlier|timeuntil:now }}', { 'now'=>now, 'earlier'=>now - timedelta(days=7) ), '0 minutes'),
        //'filter-timeuntil08'=>array('{{ later|timeuntil }}', { 'later'=>now + timedelta(days=7, hours=1) ), '1 week'),
        //'filter-timeuntil09'=>array('{{ later|timeuntil:now }}', { 'now'=>now, 'later'=>now + timedelta(days=7) ), '1 week'),
        //
        //// Ensures that differing timezones are calculated correctly
        //'filter-timeuntil10' =>array('{{ a|timeuntil }}', array('a'=>$$now_tz_i), '0 minutes'),
        //'filter-timeuntil11' =>array('{{ a|timeuntil:b }}', array('a'=>$$now_tz_i, 'b'=>$now_tz), '0 minutes'),
        //
        //// Regression for //9065 (two date objects).
        //'filter-timeuntil12' =>array('{{ a|timeuntil:b }}', array('a'=>today, 'b'=>today), '0 minutes'),
        //'filter-timeuntil13' =>array('{{ a|timeuntil:b }}', array('a'=>today, 'b'=>today - timedelta(hours=24)), '1 day'),

        'filter-addslash01'=>array("{% autoescape off %}{{ a|addslashes }} {{ b|addslashes }}{% endautoescape %}", array("a"=>"<a>'", "b"=>mark_safe("<a>'")), "<a>\' <a>\'"),
        'filter-addslash02'=>array("{{ a|addslashes }} {{ b|addslashes }}", array("a"=>"<a>'", "b"=>mark_safe("<a>'")), "&lt;a&gt;\&#39; <a>\'"),

        'filter-capfirst01'=>array("{% autoescape off %}{{ a|capfirst }} {{ b|capfirst }}{% endautoescape %}", array("a"=>"fred>", "b"=>mark_safe("fred&gt;")), "Fred> Fred&gt;"),
        'filter-capfirst02'=>array("{{ a|capfirst }} {{ b|capfirst }}", array("a"=>"fred>", "b"=>mark_safe("fred&gt;")), "Fred&gt; Fred&gt;"),

        // Note that applying fix_ampsersands in autoescape mode leads to double escaping.
        'filter-fix_ampersands01'=>array("{% autoescape off %}{{ a|fix_ampersands }} {{ b|fix_ampersands }}{% endautoescape %}", array("a"=>"a&b", "b"=>mark_safe("a&b")), "a&amp;b a&amp;b"),
        'filter-fix_ampersands02'=>array("{{ a|fix_ampersands }} {{ b|fix_ampersands }}", array("a"=>"a&b", "b"=>mark_safe("a&b")), "a&amp;amp;b a&amp;b"),

        'filter-floatformat01'=>array("{% autoescape off %}{{ a|floatformat }} {{ b|floatformat }}{% endautoescape %}", array("a"=>"1.42", "b"=>mark_safe("1.42")), "1.4 1.4"),
        'filter-floatformat02'=>array("{{ a|floatformat }} {{ b|floatformat }}", array("a"=>"1.42", "b"=>mark_safe("1.42")), "1.4 1.4"),

        // The contents of "linenumbers" is escaped according to the current autoescape setting.
        'filter-linenumbers01'=>array("{{ a|linenumbers }} {{ b|linenumbers }}", array("a"=>"one\n<two>\nthree", "b"=>mark_safe("one\n&lt;two&gt;\nthree")), "1. one\n2. &lt;two&gt;\n3. three 1. one\n2. &lt;two&gt;\n3. three"),
        'filter-linenumbers02'=>array("{% autoescape off %}{{ a|linenumbers }} {{ b|linenumbers }}{% endautoescape %}", array("a"=>"one\n<two>\nthree", "b"=>mark_safe("one\n&lt;two&gt;\nthree")), "1. one\n2. <two>\n3. three 1. one\n2. &lt;two&gt;\n3. three"),

        'filter-lower01'=>array("{% autoescape off %}{{ a|lower }} {{ b|lower }}{% endautoescape %}", array("a"=>"Apple & banana", "b"=>mark_safe("Apple &amp; banana")), "apple & banana apple &amp; banana"),
        'filter-lower02'=>array("{{ a|lower }} {{ b|lower }}", array("a"=>"Apple & banana", "b"=>mark_safe("Apple &amp; banana")), "apple &amp; banana apple &amp; banana"),

        // The make_list filter can destroy existing escaping, so the results are escaped.
        'filter-make_list01'=>array("{% autoescape off %}{{ a|make_list }}{% endautoescape %}", array("a"=>mark_safe("&")), "[u'&']"),
        'filter-make_list02'=>array("{{ a|make_list }}", array("a"=>mark_safe("&")), "[u&#39;&amp;&#39;]"),
        'filter-make_list03'=>array('{% autoescape off %}{{ a|make_list|stringformat:"s"|safe }}{% endautoescape %}', array("a"=>mark_safe("&")), "[u'&']"),
        'filter-make_list04'=>array('{{ a|make_list|stringformat:"s"|safe }}', array("a"=>mark_safe("&")), "[u'&']"),

        // Running slugify on a pre-escaped string leads to odd behavior, but the result is still safe.
        'filter-slugify01'=>array("{% autoescape off %}{{ a|slugify }} {{ b|slugify }}{% endautoescape %}", array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "a-b a-amp-b"),
        'filter-slugify02'=>array("{{ a|slugify }} {{ b|slugify }}", array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "a-b a-amp-b"),

        // Notice that escaping is applied *after* any filters, so the string
        // formatting here only needs to deal with pre-escaped characters.
        'filter-stringformat01'=>array('{% autoescape off %}.{{ a|stringformat:"5s" }}. .{{ b|stringformat:"5s" }}.{% endautoescape %}',
            array("a"=>"a<b", "b"=>mark_safe("a<b")), ".  a<b. .  a<b."),
        'filter-stringformat02'=>array('.{{ a|stringformat:"5s" }}. .{{ b|stringformat:"5s" }}.', array("a"=>"a<b", "b"=>mark_safe("a<b")),
            ".  a&lt;b. .  a<b."),

        // Test the title filter
        'filter-title1' =>array('{{ a|title }}', array('a' =>'JOE\'S CRAB SHACK'), 'Joe&#39;s Crab Shack'),
        'filter-title2' =>array('{{ a|title }}', array('a' =>'555 WEST 53RD STREET'), '555 West 53rd Street'),

        'filter-truncatewords01'=>array('{% autoescape off %}{{ a|truncatewords:"2" }} {{ b|truncatewords:"2"}}{% endautoescape %}',
            array("a"=>"alpha & bravo", "b"=>mark_safe("alpha &amp; bravo")), "alpha & ... alpha &amp; ..."),
        'filter-truncatewords02'=>array('{{ a|truncatewords:"2" }} {{ b|truncatewords:"2"}}',
            array("a"=>"alpha & bravo", "b"=>mark_safe("alpha &amp; bravo")), "alpha &amp; ... alpha &amp; ..."),

        'filter-truncatechars01'=>array('{{ a|truncatechars:5 }}', array('a'=>"Testing, testing"), "Te..."),
        'filter-truncatechars02'=>array('{{ a|truncatechars:7 }}', array('a'=>"Testing"), "Testing"),

        // The "upper" filter messes up entities (which are case-sensitive),
        // so it's not safe for non-escaping purposes.
        'filter-upper01'=>array('{% autoescape off %}{{ a|upper }} {{ b|upper }}{% endautoescape %}', array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "A & B A &AMP; B"),
        'filter-upper02'=>array('{{ a|upper }} {{ b|upper }}', array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "A &amp; B A &amp;AMP; B"),

        // TODO urlize filter.
        //'filter-urlize01'=>array('{% autoescape off %}{{ a|urlize }} {{ b|urlize }}{% endautoescape %}', array("a"=>"http://example.com/?x=&y=", "b"=>mark_safe("http://example.com?x=&amp;y=")), '<a href="http://example.com/?x=&y=" rel="nofollow">http://example.com/?x=&y=</a> <a href="http://example.com?x=&amp;y=" rel="nofollow">http://example.com?x=&amp;y=</a>'),
        //'filter-urlize02'=>array('{{ a|urlize }} {{ b|urlize }}', array("a"=>"http://example.com/?x=&y=", "b"=>mark_safe("http://example.com?x=&amp;y=")), '<a href="http://example.com/?x=&amp;y=" rel="nofollow">http://example.com/?x=&amp;y=</a> <a href="http://example.com?x=&amp;y=" rel="nofollow">http://example.com?x=&amp;y=</a>'),
        //'filter-urlize03'=>array('{% autoescape off %}{{ a|urlize }}{% endautoescape %}', array("a"=>mark_safe("a &amp; b")), 'a &amp; b'),
        //'filter-urlize04'=>array('{{ a|urlize }}', array("a"=>mark_safe("a &amp; b")), 'a &amp; b'),
        //
        //// This will lead to a nonsense result, but at least it won't be
        //// exploitable for XSS purposes when auto-escaping is on.
        //'filter-urlize05'=>array('{% autoescape off %}{{ a|urlize }}{% endautoescape %}', array("a"=>"<script>alert('foo')</script>"), "<script>alert('foo')</script>"),
        //'filter-urlize06'=>array('{{ a|urlize }}', array("a"=>"<script>alert('foo')</script>"), '&lt;script&gt;alert(&//39;foo&//39;)&lt;/script&gt;'),
        //
        //// mailto=>testing for urlize
        //'filter-urlize07'=>array('{{ a|urlize }}', array("a"=>"Email me at me@example.com"), 'Email me at <a href="mailto:me@example.com">me@example.com</a>'),
        //'filter-urlize08'=>array('{{ a|urlize }}', array("a"=>"Email me at <me@example.com>"), 'Email me at &lt;<a href="mailto:me@example.com">me@example.com</a>&gt;'),
        //
        //'filter-urlizetrunc01'=>array('{% autoescape off %}{{ a|urlizetrunc:"8" }} {{ b|urlizetrunc:"8" }}{% endautoescape %}', array("a"=>'"Unsafe" http://example.com/x=&y=', "b"=>mark_safe('&quot;Safe&quot; http://example.com?x=&amp;y=')), '"Unsafe" <a href="http://example.com/x=&y=" rel="nofollow">http:...</a> &quot;Safe&quot; <a href="http://example.com?x=&amp;y=" rel="nofollow">http:...</a>'),
        //'filter-urlizetrunc02'=>array('{{ a|urlizetrunc:"8" }} {{ b|urlizetrunc:"8" }}', array("a"=>'"Unsafe" http://example.com/x=&y=', "b"=>mark_safe('&quot;Safe&quot; http://example.com?x=&amp;y=')), '&quot;Unsafe&quot; <a href="http://example.com/x=&amp;y=" rel="nofollow">http:...</a> &quot;Safe&quot; <a href="http://example.com?x=&amp;y=" rel="nofollow">http:...</a>'),

        'filter-wordcount01'=>array('{% autoescape off %}{{ a|wordcount }} {{ b|wordcount }}{% endautoescape %}', array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "3 3"),
        'filter-wordcount02'=>array('{{ a|wordcount }} {{ b|wordcount }}', array("a"=>"a & b", "b"=>mark_safe("a &amp; b")), "3 3"),

        'filter-wordwrap01'=>array('{% autoescape off %}{{ a|wordwrap:"3" }} {{ b|wordwrap:"3" }}{% endautoescape %}', array("a"=>"a & b", "b"=>mark_safe("a & b")), "a &\nb a &\nb"),
        'filter-wordwrap02'=>array('{{ a|wordwrap:"3" }} {{ b|wordwrap:"3" }}', array("a"=>"a & b", "b"=>mark_safe("a & b")), "a &amp;\nb a &\nb"),

        'filter-ljust01'=>array('{% autoescape off %}.{{ a|ljust:"5" }}. .{{ b|ljust:"5" }}.{% endautoescape %}', array("a"=>"a&b", "b"=>mark_safe("a&b")), ".a&b  . .a&b  ."),
        'filter-ljust02'=>array('.{{ a|ljust:"5" }}. .{{ b|ljust:"5" }}.', array("a"=>"a&b", "b"=>mark_safe("a&b")), ".a&amp;b  . .a&b  ."),

        'filter-rjust01'=>array('{% autoescape off %}.{{ a|rjust:"5" }}. .{{ b|rjust:"5" }}.{% endautoescape %}', array("a"=>"a&b", "b"=>mark_safe("a&b")), ".  a&b. .  a&b."),
        'filter-rjust02'=>array('.{{ a|rjust:"5" }}. .{{ b|rjust:"5" }}.', array("a"=>"a&b", "b"=>mark_safe("a&b")), ".  a&amp;b. .  a&b."),

        'filter-center01'=>array('{% autoescape off %}.{{ a|center:"5" }}. .{{ b|center:"5" }}.{% endautoescape %}', array("a"=>"a&b", "b"=>mark_safe("a&b")), ". a&b . . a&b ."),
        'filter-center02'=>array('.{{ a|center:"5" }}. .{{ b|center:"5" }}.', array("a"=>"a&b", "b"=>mark_safe("a&b")), ". a&amp;b . . a&b ."),

        'filter-cut01'=>array('{% autoescape off %}{{ a|cut:"x" }} {{ b|cut:"x" }}{% endautoescape %}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "&y &amp;y"),
        'filter-cut02'=>array('{{ a|cut:"x" }} {{ b|cut:"x" }}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "&amp;y &amp;y"),
        'filter-cut03'=>array('{% autoescape off %}{{ a|cut:"&" }} {{ b|cut:"&" }}{% endautoescape %}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "xy xamp;y"),
        'filter-cut04'=>array('{{ a|cut:"&" }} {{ b|cut:"&" }}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "xy xamp;y"),
        // Passing ';' to cut can break existing HTML entities, so those strings are auto-escaped.
        'filter-cut05'=>array('{% autoescape off %}{{ a|cut:";" }} {{ b|cut:";" }}{% endautoescape %}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "x&y x&ampy"),
        'filter-cut06'=>array('{{ a|cut:";" }} {{ b|cut:";" }}', array("a"=>"x&y", "b"=>mark_safe("x&amp;y")), "x&amp;y x&amp;ampy"),

        // The "escape" filter works the same whether autoescape is on or off,
        // but it has no effect on strings already marked as safe.
        'filter-escape01'=>array('{{ a|escape }} {{ b|escape }}', array("a"=>"x&y", "b"=>mark_safe("x&y")), "x&amp;y x&y"),
        'filter-escape02'=>array('{% autoescape off %}{{ a|escape }} {{ b|escape }}{% endautoescape %}', array("a"=>"x&y", "b"=>mark_safe("x&y")), "x&amp;y x&y"),

        // It is only applied once, regardless of the number of times it appears in a chain.
        'filter-escape03'=>array('{% autoescape off %}{{ a|escape|escape }}{% endautoescape %}', array("a"=>"x&y"), "x&amp;y"),
        'filter-escape04'=>array('{{ a|escape|escape }}', array("a"=>"x&y"), "x&amp;y"),

        // Force_escape is applied immediately. It can be used to provide double-escaping, for example.
        'filter-force-escape01'=>array('{% autoescape off %}{{ a|force_escape }}{% endautoescape %}', array("a"=>"x&y"), "x&amp;y"),
        'filter-force-escape02'=>array('{{ a|force_escape }}', array("a"=>"x&y"), "x&amp;y"),
        'filter-force-escape03'=>array('{% autoescape off %}{{ a|force_escape|force_escape }}{% endautoescape %}', array("a"=>"x&y"), "x&amp;amp;y"),
        'filter-force-escape04'=>array('{{ a|force_escape|force_escape }}', array("a"=>"x&y"), "x&amp;amp;y"),

        // Because the result of force_escape is "safe", an additional escape filter has no effect.
        'filter-force-escape05'=>array('{% autoescape off %}{{ a|force_escape|escape }}{% endautoescape %}', array("a"=>"x&y"), "x&amp;y"),
        'filter-force-escape06'=>array('{{ a|force_escape|escape }}', array("a"=>"x&y"), "x&amp;y"),
        'filter-force-escape07'=>array('{% autoescape off %}{{ a|escape|force_escape }}{% endautoescape %}', array("a"=>"x&y"), "x&amp;y"),
        'filter-force-escape08'=>array('{{ a|escape|force_escape }}', array("a"=>"x&y"), "x&amp;y"),

        // The contents in "linebreaks" and "linebreaksbr" are escaped according to the current autoescape setting.
        'filter-linebreaks01'=>array('{{ a|linebreaks }} {{ b|linebreaks }}', array("a"=>"x&\ny", "b"=>mark_safe("x&\ny")), "<p>x&amp;<br />y</p> <p>x&<br />y</p>"),
        'filter-linebreaks02'=>array('{% autoescape off %}{{ a|linebreaks }} {{ b|linebreaks }}{% endautoescape %}', array("a"=>"x&\ny", "b"=>mark_safe("x&\ny")), "<p>x&<br />y</p> <p>x&<br />y</p>"),

        'filter-linebreaksbr01'=>array('{{ a|linebreaksbr }} {{ b|linebreaksbr }}', array("a"=>"x&\ny", "b"=>mark_safe("x&\ny")), "x&amp;<br />y x&<br />y"),
        'filter-linebreaksbr02'=>array('{% autoescape off %}{{ a|linebreaksbr }} {{ b|linebreaksbr }}{% endautoescape %}', array("a"=>"x&\ny", "b"=>mark_safe("x&\ny")), "x&<br />y x&<br />y"),

        'filter-safe01'=>array("{{ a }} -- {{ a|safe }}", array("a"=>"<b>hello</b>"), "&lt;b&gt;hello&lt;/b&gt; -- <b>hello</b>"),
        'filter-safe02'=>array("{% autoescape off %}{{ a }} -- {{ a|safe }}{% endautoescape %}", array("a"=>"<b>hello</b>"), "<b>hello</b> -- <b>hello</b>"),

        'filter-safeseq01'=>array('{{ a|join:", " }} -- {{ a|safeseq|join:", " }}', array("a"=>array("&", "<")), "&amp;, &lt; -- &, <"),
        'filter-safeseq02'=>array('{% autoescape off %}{{ a|join:", " }} -- {{ a|safeseq|join:", " }}{% endautoescape %}', array("a"=>array("&", "<")), "&, < -- &, <"),

        'filter-removetags01'=>array('{{ a|removetags:"a b" }} {{ b|removetags:"a b" }}', array("a"=>"<a>x</a> <p><b>y</b></p>", "b"=>mark_safe("<a>x</a> <p><b>y</b></p>")), "x &lt;p&gt;y&lt;/p&gt; x <p>y</p>"),
        'filter-removetags02'=>array('{% autoescape off %}{{ a|removetags:"a b" }} {{ b|removetags:"a b" }}{% endautoescape %}', array("a"=>"<a>x</a> <p><b>y</b></p>", "b"=>mark_safe("<a>x</a> <p><b>y</b></p>")), "x <p>y</p> x <p>y</p>"),

        'filter-striptags01'=>array('{{ a|striptags }} {{ b|striptags }}', array("a"=>"<a>x</a> <p><b>y</b></p>", "b"=>mark_safe("<a>x</a> <p><b>y</b></p>")), "x y x y"),
        'filter-striptags02'=>array('{% autoescape off %}{{ a|striptags }} {{ b|striptags }}{% endautoescape %}', array("a"=>"<a>x</a> <p><b>y</b></p>", "b"=>mark_safe("<a>x</a> <p><b>y</b></p>")), "x y x y"),

        'filter-first01'=>array('{{ a|first }} {{ b|first }}', array("a"=>array("a&b", "x"), "b"=>array(mark_safe("a&b"), "x")), "a&amp;b a&b"),
        'filter-first02'=>array('{% autoescape off %}{{ a|first }} {{ b|first }}{% endautoescape %}', array("a"=>array("a&b", "x"), "b"=>array(mark_safe("a&b"), "x")), "a&b a&b"),

        'filter-last01'=>array('{{ a|last }} {{ b|last }}', array("a"=>array("x", "a&b"), "b"=>array("x", mark_safe("a&b"))), "a&amp;b a&b"),
        'filter-last02'=>array('{% autoescape off %}{{ a|last }} {{ b|last }}{% endautoescape %}', array("a"=>array("x", "a&b"), "b"=>array("x", mark_safe("a&b"))), "a&b a&b"),

        'filter-random01'=>array('{{ a|random }} {{ b|random }}', array("a"=>array("a&b", "a&b"), "b"=>array(mark_safe("a&b"), mark_safe("a&b"))), "a&amp;b a&b"),
        'filter-random02'=>array('{% autoescape off %}{{ a|random }} {{ b|random }}{% endautoescape %}', array("a"=>array("a&b", "a&b"), "b"=>array(mark_safe("a&b"), mark_safe("a&b"))), "a&b a&b"),

        'filter-slice01'=>array('{{ a|slice:"1:3" }} {{ b|slice:"1:3" }}', array("a"=>"a&b", "b"=>mark_safe("a&b")), "&amp;b &b"),
        'filter-slice02'=>array('{% autoescape off %}{{ a|slice:"1:3" }} {{ b|slice:"1:3" }}{% endautoescape %}', array("a"=>"a&b", "b"=>mark_safe("a&b")), "&b &b"),

        'filter-unordered_list01'=>array('{{ a|unordered_list }}', array("a"=>array("x>", array(array("<y", array())))), "\t<li>x&gt;\n\t<ul>\n\t\t<li>&lt;y</li>\n\t</ul>\n\t</li>"),
        'filter-unordered_list02'=>array('{% autoescape off %}{{ a|unordered_list }}{% endautoescape %}', array("a"=>array("x>", array(array("<y", array())))), "\t<li>x>\n\t<ul>\n\t\t<li><y</li>\n\t</ul>\n\t</li>"),
        'filter-unordered_list03'=>array('{{ a|unordered_list }}', array("a"=>array("x>", array(array(mark_safe("<y"), array())))), "\t<li>x&gt;\n\t<ul>\n\t\t<li><y</li>\n\t</ul>\n\t</li>"),
        'filter-unordered_list04'=>array('{% autoescape off %}{{ a|unordered_list }}{% endautoescape %}', array("a"=>array("x>", array(array(mark_safe("<y"), array())))), "\t<li>x>\n\t<ul>\n\t\t<li><y</li>\n\t</ul>\n\t</li>"),
        'filter-unordered_list05'=>array('{% autoescape off %}{{ a|unordered_list }}{% endautoescape %}', array("a"=>array("x>", array(array("<y", array())))), "\t<li>x>\n\t<ul>\n\t\t<li><y</li>\n\t</ul>\n\t</li>"),

        /*
         * Literal string arguments to the default filter are always treated as
         * safe strings, regardless of the auto-escaping state.
         *
         * Note=>we have to use array("a"=>""} here, otherwise the invalid template
         * variable string interferes with the test result.
         */
        'filter-default01'=>array('{{ a|default:"x<" }}', array("a"=>""), "x<"),
        'filter-default02'=>array('{% autoescape off %}{{ a|default:"x<" }}{% endautoescape %}', array("a"=>""), "x<"),
        'filter-default03'=>array('{{ a|default:"x<" }}', array("a"=>mark_safe("x>")), "x>"),
        'filter-default04'=>array('{% autoescape off %}{{ a|default:"x<" }}{% endautoescape %}', array("a"=>mark_safe("x>")), "x>"),

        'filter-default_if_none01'=>array('{{ a|default:"x<" }}', array("a"=>null), "x<"),
        'filter-default_if_none02'=>array('{% autoescape off %}{{ a|default:"x<" }}{% endautoescape %}', array("a"=>null), "x<"),

        'filter-phone2numeric01'=>array('{{ a|phone2numeric }} {{ b|phone2numeric }}', array("a"=>"<1-800-call-me>", "b"=>mark_safe("<1-800-call-me>") ), "&lt;1-800-2255-63&gt; <1-800-2255-63>"),
        'filter-phone2numeric02'=>array('{% autoescape off %}{{ a|phone2numeric }} {{ b|phone2numeric }}{% endautoescape %}', array("a"=>"<1-800-call-me>", "b"=>mark_safe("<1-800-call-me>") ), "<1-800-2255-63> <1-800-2255-63>"),
        'filter-phone2numeric03'=>array('{{ a|phone2numeric }}', array("a"=>"How razorback-jumping frogs can level six piqued gymnasts!"), "469 729672225-5867464 37647 226 53835 749 747833 49662787!"),

        // Ensure iriencode keeps safe strings:
        'filter-iriencode01'=>array('{{ url|iriencode }}', array('url'=>'?test=1&me=2'), '?test=1&amp;me=2'),
        'filter-iriencode02'=>array('{% autoescape off %}{{ url|iriencode }}{% endautoescape %}', array('url'=>'?test=1&me=2'), '?test=1&me=2'),
        'filter-iriencode03'=>array('{{ url|iriencode }}', array('url'=>mark_safe('?test=1&me=2')), '?test=1&me=2'),
        'filter-iriencode04'=>array('{% autoescape off %}{{ url|iriencode }}{% endautoescape %}', array('url'=>mark_safe('?test=1&me=2')), '?test=1&me=2'),

        // urlencode
        'filter-urlencode01'=>array('{{ url|urlencode }}', array('url'=>'/test&"/me?/'), '/test%26%22/me%3F/'),
        'filter-urlencode02'=>array('/test/{{ urlbit|urlencode:"" }}/', array('urlbit'=>'escape/slash'), '/test/escape%2Fslash/'),

        // Chaining a bunch of safeness-preserving filters should not alter
        // the safe status either way.
        'chaining01'=>array('{{ a|capfirst|center:"7" }}.{{ b|capfirst|center:"7" }}', array("a"=>"a < b", "b"=>mark_safe("a < b")), " A &lt; b . A < b "),
        'chaining02'=>array('{% autoescape off %}{{ a|capfirst|center:"7" }}.{{ b|capfirst|center:"7" }}{% endautoescape %}', array("a"=>"a < b", "b"=>mark_safe("a < b")), " A < b . A < b "),

        // Using a filter that forces a string back to unsafe:
        'chaining03'=>array('{{ a|cut:"b"|capfirst }}.{{ b|cut:"b"|capfirst }}', array("a"=>"a < b", "b"=>mark_safe("a < b")), "A &lt; .A < "),
        'chaining04'=>array('{% autoescape off %}{{ a|cut:"b"|capfirst }}.{{ b|cut:"b"|capfirst }}{% endautoescape %}', array("a"=>"a < b", "b"=>mark_safe("a < b")), "A < .A < "),

        // Using a filter that forces safeness does not lead to double-escaping
        'chaining05'=>array('{{ a|escape|capfirst }}', array("a"=>"a < b"), "A &lt; b"),
        'chaining06'=>array('{% autoescape off %}{{ a|escape|capfirst }}{% endautoescape %}', array("a"=>"a < b"), "A &lt; b"),

        // Force to safe, then back (also showing why using force_escape too early in a chain can lead to unexpected results).
        'chaining07'=>array('{{ a|force_escape|cut:";" }}', array("a"=>"a < b"), "a &amp;lt b"),
        'chaining08'=>array('{% autoescape off %}{{ a|force_escape|cut:";" }}{% endautoescape %}', array("a"=>"a < b"), "a &lt b"),
        'chaining09'=>array('{{ a|cut:";"|force_escape }}', array("a"=>"a < b"), "a &lt; b"),
        'chaining10'=>array('{% autoescape off %}{{ a|cut:";"|force_escape }}{% endautoescape %}', array("a"=>"a < b"), "a &lt; b"),
        'chaining11'=>array('{{ a|cut:"b"|safe }}', array("a"=>"a < b"), "a < "),
        'chaining12'=>array('{% autoescape off %}{{ a|cut:"b"|safe }}{% endautoescape %}', array("a"=>"a < b"), "a < "),
        'chaining13'=>array('{{ a|safe|force_escape }}', array("a"=>"a < b"), "a &lt; b"),
        'chaining14'=>array('{% autoescape off %}{{ a|safe|force_escape }}{% endautoescape %}', array("a"=>"a < b"), "a &lt; b"),

        // Filters decorated with stringfilter still respect is_safe.
        'autoescape-stringfilter01'=>array('{{ unsafe|capfirst }}', array('unsafe'=>new UnsafeClass()), 'You &amp; me'),
        'autoescape-stringfilter02'=>array('{% autoescape off %}{{ unsafe|capfirst }}{% endautoescape %}', array('unsafe'=>new UnsafeClass()), 'You & me'),
        'autoescape-stringfilter03'=>array('{{ safe|capfirst }}', array('safe'=>new SafeClass()), 'You &gt; me'),
        'autoescape-stringfilter04'=>array('{% autoescape off %}{{ safe|capfirst }}{% endautoescape %}', array('safe'=>new SafeClass()), 'You &gt; me'),

        // TODO escapejs filter.
        // 'escapejs01'=>array('{{ a|escapejs }}', array('a'=>'testing\r\njavascript \'string" <b>escaping</b>'), 'testing\\u000D\\u000Ajavascript \\u0027string\\u0022 \\u003Cb\\u003Eescaping\\u003C/b\\u003E'),
        // 'escapejs02'=>array('{% autoescape off %}{{ a|escapejs }}{% endautoescape %}', array('a'=>'testing\r\njavascript \'string" <b>escaping</b>'), 'testing\\u000D\\u000Ajavascript \\u0027string\\u0022 \\u003Cb\\u003Eescaping\\u003C/b\\u003E'),


        // length filter.
        'length01'=>array('{{ list|length }}', array('list'=>array('4', null, True, array())), '4'),
        'length02'=>array('{{ list|length }}', array('list'=>array()), '0'),
        'length03'=>array('{{ string|length }}', array('string'=>''), '0'),
        'length04'=>array('{{ string|length }}', array('string'=>'django'), '6'),
        // Invalid uses that should fail silently.
        'length05'=>array('{{ int|length }}', array('int'=>7), ''),
        'length06'=>array('{{ None|length }}', array('None'=>null), ''),

        // length_is filter.
        'length_is01'=>array('{% if some_list|length_is:"4" %}Four{% endif %}', array('some_list'=>array('4', null, True, array())), 'Four'),
        'length_is02'=>array('{% if some_list|length_is:"4" %}Four{% else %}Not Four{% endif %}', array('some_list'=>array('4', null, True, array(), 17)), 'Not Four'),
        'length_is03'=>array('{% if mystring|length_is:"4" %}Four{% endif %}', array('mystring'=>'word'), 'Four'),
        'length_is04'=>array('{% if mystring|length_is:"4" %}Four{% else %}Not Four{% endif %}', array('mystring'=>'Python'), 'Not Four'),
        'length_is05'=>array('{% if mystring|length_is:"4" %}Four{% else %}Not Four{% endif %}', array('mystring'=>''), 'Not Four'),
        'length_is06'=>array('{% with var|length as my_length %}{{ my_length }}{% endwith %}', array('var'=>'django'), '6'),
        // Boolean return value from length_is should not be coerced to a string
        'length_is07'=>array('{% if "X"|length_is:0 %}Length is 0{% else %}Length not 0{% endif %}', array(), 'Length not 0'),
        'length_is08'=>array('{% if "X"|length_is:1 %}Length is 1{% else %}Length not 1{% endif %}', array(), 'Length is 1'),
        // Invalid uses that should fail silently.
        'length_is09'=>array('{{ var|length_is:"fish" }}', array('var'=>'django'), ''),
        'length_is10'=>array('{{ int|length_is:"1" }}', array('int'=>7), ''),
        'length_is11'=>array('{{ none|length_is:"1" }}', array('none'=>null), ''),

        'join01'=>array('{{ a|join:", " }}', array('a'=>array('alpha', 'beta & me')), 'alpha, beta &amp; me'),
        'join02'=>array('{% autoescape off %}{{ a|join:", " }}{% endautoescape %}', array('a'=>array('alpha', 'beta & me')), 'alpha, beta & me'),
        'join03'=>array('{{ a|join:" &amp; " }}', array('a'=>array('alpha', 'beta & me')), 'alpha &amp; beta &amp; me'),
        'join04'=>array('{% autoescape off %}{{ a|join:" &amp; " }}{% endautoescape %}', array('a'=>array('alpha', 'beta & me')), 'alpha &amp; beta & me'),

        // Test that joining with unsafe joiners don't result in unsafe strings (//11377)
        'join05'=>array('{{ a|join:var }}', array('a'=>array('alpha', 'beta & me'), 'var'=>' & '), 'alpha &amp; beta &amp; me'),
        'join06'=>array('{{ a|join:var }}', array('a'=>array('alpha', 'beta & me'), 'var'=>mark_safe(' & ')), 'alpha & beta &amp; me'),
        'join07'=>array('{{ a|join:var|lower }}', array('a'=>array('Alpha', 'Beta & me'), 'var'=>' & ' ), 'alpha &amp; beta &amp; me'),
        'join08'=>array('{{ a|join:var|lower }}', array('a'=>array('Alpha', 'Beta & me'), 'var'=>mark_safe(' & ')), 'alpha & beta &amp; me'),

        'date01'=>array('{{ d|date:"m" }}', array('d'=>mktime(0, 0, 0, 1, 1, 2008)), '01'),
        'date02'=>array('{{ d|date }}', array('d'=>mktime(0, 0, 0, 1, 1, 2008)), 'Jan. 1, 2008'),
        //Ticket 9520=>Make sure |date doesn't blow up on non-dates
        'date03'=>array('{{ d|date:"m" }}', array('d'=>'fail_string'), ''),
        // ISO date formats
        'date04'=>array('{{ d|date:"o" }}', array('d'=>mktime(0, 0, 0, 12, 29, 2008)), '2009'),
        'date05'=>array('{{ d|date:"o" }}', array('d'=>mktime(0, 0, 0, 1, 3, 2010)), '2009'),
        // Timezone name
        'date06'=>array('{{ d|date:"e" }}', array('d'=>get_ts(mktime(0, 0, 0, 3, 12, 2009), 'Asia/Novosibirsk')), '+0600'),

         // Tests for //11687 and //16676
         'add01'=>array('{{ i|add:"5" }}', array('i'=>2000), '2005'),
         'add02'=>array('{{ i|add:"napis" }}', array('i'=>2000), ''),
         'add03'=>array('{{ i|add:16 }}', array('i'=>'not_an_int'), ''),
         'add04'=>array('{{ i|add:"16" }}', array('i'=>'not_an_int'), 'not_an_int16'),
         'add05'=>array('{{ l1|add:l2 }}', array('l1'=>array(1, 2), 'l2'=>array(3, 4)), '[1, 2, 3, 4]'),
         'add06'=>array('{{ t1|add:t2 }}', array('t1'=>array(3, 4), 't2'=>array(1, 2)), '[3, 4, 1, 2]'),  // Dja uses arrays, and doesn't know anything about sets.
         // TODO 'add07'=>array('{{ d|add:t }}', array('d'=>date(2000, 1, 1), 't'=>timedelta(10)), 'Jan. 11, 2000'),
    );
}