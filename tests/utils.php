<?php

require_once 'loaders/cached.php';

/**
 * Changes Django to only find templates from within a dictionary (where each
 * key is the template name and each value is the corresponding template
 * content to return).
 *
 * Use meth:`restore_template_loaders` to restore the original loaders.
 *
 * @param $templates_dict
 * @param bool|$use_cached_loader
 * @return mixed
 */
function setup_test_template_loader($templates_dict, $use_cached_loader=False) {

    if (isset($GLOBALS['RESTORE_LOADERS_ATTR'])) {
        throw new Exception('loader.' . $GLOBALS['RESTORE_LOADERS_ATTR'] . ' already exists');
    }

    // A custom template loader that loads templates from a dictionary.
    $test_template_loader = function ($template_name, $template_dirs=null) use ($templates_dict) {
        try {
            if (!isset($templates_dict[$template_name])) {
                throw new KeyError();
            }
            return array($templates_dict[$template_name], 'test: ' . $template_name);
        } catch (KeyError $e) {
            throw new TemplateDoesNotExist($template_name);
        }
    };

    if ($use_cached_loader) {
        $template_loader = new CachedLoader(array('test_template_loader'));
        $template_loader->_cached_loaders = array($test_template_loader);
    } else {
        $template_loader = $test_template_loader;
    }

    $GLOBALS['RESTORE_LOADERS_ATTR'] = DjaLoader::$template_source_loaders;
    DjaLoader::$template_source_loaders = array($template_loader);
    return $template_loader;
}

/**
 * Restores the original template loaders after
 * :meth:`setup_test_template_loader` has been run.
 */
function restore_template_loaders() {
    DjaLoader::$template_source_loaders = $GLOBALS['RESTORE_LOADERS_ATTR'];
    unset($GLOBALS['RESTORE_LOADERS_ATTR']);
}