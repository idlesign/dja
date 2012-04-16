<?php


class AppDirectoriesLoader extends BaseLoader {

    public $is_usable = True;

    private static $app_template_dirs = null;

    private function getAppTemplateDirs() {
        if (self::$app_template_dirs===null) {
            foreach (Dja::getSetting('INSTALLED_APPS') as $app) {
                $template_dir = ($app . '/templates');  // TODO Probably needs directory exists check.
                self::$app_template_dirs[] = $template_dir;
            }
        }
        return self::$app_template_dirs;
    }

    /*
     * Returns the absolute paths to "template_name", when appended to each
     * directory in "template_dirs". Any paths that don't lie inside one of the
     * template dirs are excluded from the result set, for security reasons.
     */
    public function getTemplateSources($template_name, $template_dirs=null) {

        if (!$template_dirs) {
            $template_dirs = $this->getAppTemplateDirs();
        }

        // It seems no template dirs can be found.
        if ($template_dirs===null) {
            $template_dirs = array();
        }

        $dirs_ = array();
        foreach ($template_dirs as $template_dir) {
            $dirs_[] = safe_join($template_dir, $template_name);
        }
        return $dirs_;
    }

    public function loadTemplateSource($template_name, $template_dirs = null) {

        foreach ($this->getTemplateSources($template_name, $template_dirs) as $filepath) {
            if (!file_exists($filepath) || !is_readable($filepath)) {
                continue;
            }
            return array(file_get_contents($filepath), $filepath);
        }


        throw new TemplateDoesNotExist($template_name);
    }

}

return new AppDirectoriesLoader();