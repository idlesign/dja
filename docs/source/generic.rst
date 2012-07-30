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



Generic Internationalization mechanism
--------------------------------------

To support internzationalization dja uses simple `DjaI18n` class.
It allows, for example, `trans` template tag to localize messages.

To access class object use `Dja::getI18n()` method.

This built-in system uses primitive array storage approach to keep the translations.

One can pass an array of messages indexed by language codes to `DjaI18n` using its `setMessages()` method.

.. code-block:: php

    <?php

    $i18n = Dja::getI18n();
    $i18n->setMessages(array(
        'de'=>array(
            'Page not found'=>'Seite nicht gefunden',
        ),
        'ru'=>array(
            'Page not found'=>'Страница не найдена',
        ),
    ));


To let `DjaI18n` know what languages are supported by your application, you should pass an array with
languages definitions to `setLanguages` method:

.. code-block:: php

    <?php

    $i18n = Dja::getI18n();
    $i18n->setLanguages(array(
        'de'=>'German',
        'ru'=>'Russian'
    ));

Read more about in-template internationalization at https://docs.djangoproject.com/en/dev/topics/i18n/translation/#internationalization-in-template-code


Custom Internationalization mechanism
-------------------------------------

Dja allows you to replace generic Internationalization mechanism with a custom one.

To do this one should construct a class implementing IDjaI18n interface,
and pass an object of that class into `Dja::setI18n()` before template rendering.



Generic Cache Managers
----------------------

Dja offers not only several built-in cache managers, but also means to implement that of your own.
Caching can be applied both to template parts (`cache` tag) and entire compiled template objects.

To access current cache manager object use `Dja::getCacheManager()` method.

Built-in managers:

* `DjaGlobalsCache`

  The default cache manager, using GLOBALS array to keep cache.
  Although used as the default, **this mechanism is almost entirely ineffective**, as
  cached data is not shared among running threads.

* `DjaFilebasedCache`

  This uses filesystem to store cache. Each cache entry is stored as a separate file
  with serialized data.

* `DjaDummyCache`

  Dummy cache, which doesn't actually cache, but rather implements the cache interface
  without doing anything. Can be used in develepment.


Cache manager tuning example:

.. code-block:: php

    <?php

    // Let's use filebased cache instead of the default.
    Dja::setCacheManager(new DjaFilebasedCache('/tmp/custom/temporaty/path/for/dja/'));


Fetch interesting, though not strongly related, information on subject from https://docs.djangoproject.com/en/dev/topics/cache/


Custom Cache Manager
--------------------

To create your own cache manager please implement IDjaCacheManager interface,
and pass an object of that class into `Dja::setCacheManager()` before template rendering.
