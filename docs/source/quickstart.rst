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

    <?php

    // We'll certainly use dja, so we require it.
    require_once 'dja/dja.php';

    // Initialize 'TEMPLATE_DIRS' setting to point to our template directory(ies).
    Dja::setSetting('TEMPLATE_DIRS', array('/home/idle/my/templates/are/here/'));

    // Use shortcut render() method.
    echo Dja::render('pages/another_page.html', array('title'=>'My title for another page'));


.. note::

    If TEMPLATE_DEBUG setting equals True `Dja::render()` will render pretty page with usefull debug information.


Under the hood the example above does roughly the following::

.. code-block:: php

    <?php

    require_once 'dja/dja.php';

    // First we create a template object, passing to it a template source string.
    $template = new Template('My template. It counts {{ what }}: {% for item in items %}{{ item }} and {% endfor %}no more. That\'s all, folks!');

    // After that we create a context object, passing to it an array of data to be used later in template.
    $context = new Context(array('items' => range(1, 10), 'what'=>'cows'));

    // The final step is to pass our context object to our template object.
    $result = $template->render($context);

    // Now $result contains a string ready for output.
    echo $result;

