Dja extras
==========

`dja_extras.php` file from dja package contains functions and classes absent in Django
but proved usefull in Dja.


NaiveIfTemplateNode
-------------------

This node class can be used as a template for custom naive if-like tags.

This allows `{% if_name_is_mike name %}Hi, Mike!{% else %}Where is Mike?{% endif_name_is_mike %}`
and alike constructions in templates.

The class exposes registerAsTag method to quick tag registration within a tag library:

.. code-block:: php

    <?php

    require_once 'dja/dja_extras.php';

    // Let's suppose we're in the template tags library file.
    $lib = new Library();

    // This registers `if_name_is_mike` if-like tag.
    NaiveIfTemplateNode::registerAsTag($lib, 'if_name_is_mike',
        function ($value) { return $value=='Mike'; }  // This closure will test value on rendering.
    );

    return $lib;

