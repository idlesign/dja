Extending dja
=============

Sometimes you'll get feeling that dja's built-in filters and tags are just not enough
for your needs.

You can create your own tags and filters libraries and use them in your templates
with the help of `load` tag.



Libraries
---------

Tags and filters libraries are just php files defining and exporting a `Library` object.

.. code-block:: php

    <?php

    // We create dja library object close to the beginning of the file.
    $lib = new Library();

    ...

    // And "export" that object right at the end of file.
    return $lib;


You can save library file wherever you want, e.g. `/home/idle/somewhere/here/mylibfile.php`.

Use DjaBase::addLibraryFrom() to load library into dja.

.. code-block:: php

    <?php

    DjaBase::addLibraryFrom('/home/idle/somewhere/here/mylibfile.php', 'mytaglib');

To have access to tags and filters from `mytaglib` library use `load` tag::

    {% load mytaglib %}

    <h1>That's me, {{ name|bongo }}</h1>



Custom filters
--------------

Adding filters is a rather simple task: just use Library::filter() method to register your
filter function (PHP's anonymous function).


.. code-block:: php

    <?php

    // We register filter with name `bongo`.
    $lib->filter('bingo', function($value, $arg) {
        // Here `$value` is a value from a template to filter.
        // One may define second `$arg` argument to get filter argument from the template.

        // This filter just adds `-bongo` ending to any filtered value,
        // and doesn't handle any filter arguments.
        return $value . '-bongo';
    });



Custom tags
-----------

Adding tags is a more complex task than adding filters as it involves Node object creation.
To add a tag one needs to register it within a library with Library::tag() method.


.. code-block:: php

    <?php


    $lib->tag('mytag', function($parser, $token) {
        /**
         * @var Parser $parser
         * @var Token $token
         */
        $bits = $token->splitContents();  // Here we parse arguments from our tag token.
        // Note that the first argument is a tag name itself.

        if (count($bits)!=2) {
            // Throw an error on argument count mismatch.
            throw new TemplateSyntaxError('mytag takes one argument');
        }

        // Pass tag argument into node object.
        return new MyTagNode($bits[1]);
    });


Node object metioned above is an object which will take part in tag node rendering.
We define our node class somewhere near as follows:


.. code-block:: php


    <?php

    class MyTagNode extends Node {

        private $_arg = null;

        public function __construct($arg) {
            $this->_arg = $arg;
        }

        /**
         * This method will be called each time tag is rendered.
         *
         * @param Context $context Template context.
         * @return string
         */
        public function render($context) {
            return print_r($this->_arg);
        }
    }
