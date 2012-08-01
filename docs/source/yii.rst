Yii Framework Integration Guide
===============================

Dja comes with `YiiDjaController.php` which can be used to allow dja template rendering from Yii
(http://www.yiiframework.com/).

To get things done please inherit your application base controller (usually `components/Controller.php`)
from YiiDjaController:

.. code-block:: php

    <?php

    // Import dja controller.
    Yii::import('application.extensions.dja.dja.YiiDjaController');

    class Controller extends YiiDjaController {  // <-- Inherit from YiiDjaController.
        // Your code here.
    }


Now let your controller action methods call $this->render() as usual, just bear in mind that `view name` param
is expected to be in form of a filepath under your [theme] views directory.
I.e. to render `{views_dir}/subdir/file.html` dja expects 'subdir/file' from you to be passed as a view name.

.. note::

    Dja will function in debug mode and notify you on template errors if YII_DEBUG = True.

