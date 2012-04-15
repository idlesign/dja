<?php


class FilesystemLoader extends BaseLoader {

    public $is_usable = True;

    /*
     * Returns the absolute paths to "template_name", when appended to each
     * directory in "template_dirs". Any paths that don't lie inside one of the
     * template dirs are excluded from the result set, for security reasons.
     */
    public function getTemplateSources($template_name, $template_dirs = null) {
        if (!$template_dirs) {
            $template_dirs = Dja::getSetting('TEMPLATE_DIRS');
        }
        $dirs_ = array();
        foreach ($template_dirs as $template_dir) {
            try {
                $dirs_[] = safe_join($template_dir, $template_name);
            } catch (ValueError $e) {
                /*
                 * The joined path was located outside of this particular
                 * template_dir (it might be inside another one, so this isn't fatal).
                 */
                continue;
            }
        }
        return $dirs_;
    }

    public function loadTemplateSource($template_name, $template_dirs = null) {
        $tried = array();

        foreach ($this->getTemplateSources($template_name, $template_dirs) as $filepath) {

            if (!file_exists($filepath) || !is_readable($filepath)) {
                $tried[] = $filepath;
                continue;
            }
            return array(file_get_contents($filepath), $filepath);
        }

        if ($tried) {
            $error_msg = 'Tried ' . print_r($tried, True);
        } else {
            $error_msg = 'Your TEMPLATE_DIRS setting is empty. Change it to point to at least one template directory.';
        }
        throw new TemplateDoesNotExist($error_msg);
    }

}

return new FilesystemLoader();
