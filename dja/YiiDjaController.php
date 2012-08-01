<?php


/**
 * This class allows dja template rendering in Yii http://www.yiiframework.com/.
 * To get it working please inherit your application base controller from YiiDjaController.
 *
 * Example (components/Controller.php):
 *
 *     // Import dja controller.
 *     Yii::import('application.extensions.dja.dja.YiiDjaController');
 *
 *     class Controller extends YiiDjaController {
 *         // Your code here.
 *     }
 *
 * In your controller action methods call $this->render() as usual, just bear
 * in mind that `view name` param is expected in the form of a filepath
 * under your [theme] views directory, i.e. to render `{views_dir}/subdir/file.html`
 * dja expects 'views_subdir/file'.
 */
class YiiDjaController extends CController {

    public function getViewFile($viewName) {
        // We do not resolve full path to view, as that's dja's job.
        return $viewName . '.html';
    }

    public function getLayoutFile($layoutName) {
        // And we're not taking into account layouts, as, again, that's dja's job.
        return False;
    }

}


/**
 * This is dja specific view renderer.
 */
class DjaViewRenderer extends CViewRenderer {

    public $fileExtension='.html';

    protected function generateViewFile($sourceFile,$viewFile) {}

    public function renderFile($context, $sourceFile, $data, $return) {
        $result = Dja::render($sourceFile, $data);
        if ($return) {
            return $result;
        }
        echo $result;
        return null;
    }

}


/**
 * Dja settings initialization.
 */
function init_dja() {
    /** @var $app CWebApplication */
    $app = Yii::app();
    $settings = array();

    if (YII_DEBUG) {
        $settings['TEMPLATE_DEBUG'] = True;
    }

    $template_dirs = array();
    /** @var $theme CTheme */
    if (($theme=$app->getTheme())!==null) {
        // Add theme's views directory if any,
        $template_dirs[] = $theme->getViewPath();
    }
    // Add app views directory.
    $template_dirs[] = $app->getViewPath();

    $settings['TEMPLATE_DIRS'] = $template_dirs;
    Dja::setSettings($settings);
}


require Yii::getPathOfAlias('application.extensions.dja.dja') . DIRECTORY_SEPARATOR . 'dja.php';

Yii::app()->setComponent('viewRenderer', new DjaViewRenderer());
init_dja();
