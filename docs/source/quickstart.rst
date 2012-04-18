Quick start
===========

So you are here to give dja a try.
To cut a long story short, I must say that you are not yet ready for it,
unless you are already familiar with Django templates syntax.


**Templates syntax**

First of all, take a look at Django documentation, as it is a good start to get aquainted
with templates essentials:

* Syntax overview - https://docs.djangoproject.com/en/1.4/topics/templates/
* Built-in tags and filters - https://docs.djangoproject.com/en/1.4/ref/templates/builtins/


**Basic usage example**

Now when you're familiar with Django templates[, but not yet ready to quit PHP %)], take a look
at a basic dja usage example:

.. code-block:: php

    // We'll certainly use dja, so we require it.
    require_once 'dja/dja.php';

    // First we create a template object, passing to it a template source string.
    $template = new Template('My template. It counts {{ what }}: {% for item in items %}{{ item }} and {% endfor %}no more. That\'s all, folks!');

    // After that we create a context object, passing to it an array of data to be used later in template.
    $context = new Context(array('items' => range(1, 10), 'what'=>'cows'));

    // The final step is to pass our context object to our template object.
    $result = $template->render($context);

    // Now $result contains a string ready for output.
    echo $result;


**Usage example**

When you have a bunch of template files on disk and want dja to pick them up and use automatically,
you can use the following approach:

.. code-block:: php

    // Again require dja.
    require_once 'dja/dja.php';

    // Dja class holds template engine settings (similar to settings.py of Django).
    // We initialize 'TEMPLATE_DIRS' setting to point to our template directory(ies).
    Dja::setSetting('TEMPLATE_DIRS', array('/home/idle/my/templates/here/'));

    // Now using renderToString() shortcut we render template source from intro.html.
    $result = DjaLoader::renderToString('intro.html', array('items' => array('who', 'is', 'who')));

    // $result contains a string ready for output.
    echo $result;
