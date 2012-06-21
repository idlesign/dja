Generic interfaces
==================

Django is a full-blown web-framework whereas dja is only a template engine, but nevertheless
some template tags/filters used in dja do require an access to other application parts available
in Django, e.g. URL dispatcher, Cache framework, etc. Those parts of Django being rather big and
more or less complicated are not ported as a part of dja. Instead dja allows you to "plug in" that
functionality, if it is already available to you somehow (as a part of a web-framework or
written by yourself), or use dja's generic interfaces which are trying to mimic Django components
behaviour.

.. note::

    Dja generic interfaces might be not as efficient as you expected or/and tailored for your needs.


Generic URL Dispatcher
----------------------

In order to support `url` template tag dja uses a so called URL Dispatcher.

To access dispatcher object use `Dja::getUrlDispatcher()` method.

Dja's generic URL Dispatcher is called `DjaUrlDispatcher` and it roughly mimics Django URL dispatcher, but
instead of URLconfs it operates over a set of rules: URL Patterns (regular expressions) to URL alias mappings.

You can pass an array of rules to `DjaUrlDispatcher` using its `setRules()` method.

.. code-block:: php

    <?php

    $dispatcher = Dja::getUrlDispatcher();
    $dispatcher->setRules(
        array(
            '~^/comments/(?P<article_slug>[^/]+)/(?P<user_id>\d+)/$~' => 'article_user_comments',
            '~^/client/(\d+)/$~' => 'client_details',
        ));


From the example above you can see that array keys are simply regular expressions (with named groups
in the first case), and array values are URL aliases. You can address those URLs from your templates
using `url` tag: `{% url article_user_comments article_slug user_id %}`.

Supposing that `article_slug` template variable contains a slug associated with a certain article
(e.g. my-first-article) and `user_id` contains user identifier (e.g. 33) URL dispatcher should
reverse it into `/comments/my-first-article/33/`. For more `url` tag examples please refer to Django
documentation: https://docs.djangoproject.com/en/dev/ref/templates/builtins/#url


Custom URL Dispatcher
---------------------

Dja allows you to replace generic URL Dispatcher with a custom one.

To do this one should construct a dispatching class which implements IDjaUrlDispatcher interface,
and pass an object of that class into `Dja::setUrlDispatcher()` before template rendering.
