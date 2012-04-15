<?php


class CachedLoader extends BaseLoader {

    public $is_usable = True;

    public function __construct($loaders) {
        $this->template_cache = array();
        $this->_loaders = $loaders;
        $this->_cached_loaders = array();
    }

    public function loaders() {
        // Resolve loaders on demand to avoid circular imports
        if (!$this->_cached_loaders) {
            // Set self._cached_loaders atomically. Otherwise, another thread could see an incomplete list. See #17303.
            $cached_loaders = array();
            foreach ($this->_loaders as $loader) {
                $cached_loaders[] = DjaLoader::findTemplateLoader($loader);
            }
            $this->_cached_loaders = $cached_loaders;
        }
        return $this->_cached_loaders;
    }

    public function findTemplate($name, $dirs = null) {
        /** @var $loader Closure */
        foreach ($this->loaders() as $loader) {
            try {
                list ($template, $display_name) = $loader($name, $dirs);
                return array($template, DjaLoader::makeOrigin($display_name, $loader, $name, $dirs));
            } catch (TemplateDoesNotExist $e) {
                continue;
            }
        }
        throw new TemplateDoesNotExist($name);
    }

    /**
     * @param $template_name
     * @param null|array $template_dirs
     * @return array
     */
    public function loadTemplate($template_name, $template_dirs = null) {
        $key = $template_name;
        if ($template_dirs) {
            // If template directories were specified, use a hash to differentiate
            $key = join('-', array($template_name, sha1(join('|', $template_dirs))));
        }

        if (!isset($this->template_cache[$key])) {
            list ($template, $origin) = $this->findTemplate($template_name, $template_dirs);
            if (!py_hasattr($template, 'render')) {
                try {
                    $template = DjaLoader::getTemplateFromString($template, $origin, $template_name);
                } catch (TemplateDoesNotExist $e) {
                    /*
                     * If compiling the template we found raises TemplateDoesNotExist,
                     * back off to returning the source and display name for the template
                     * we were asked to load. This allows for correct identification (later)
                     * of the actual template that does not exist.
                     */
                    return array($template, $origin);
                }
            }
            $this->template_cache[$key] = $template;
        }
        return array($this->template_cache[$key], null);
    }

    /**
     * Empty the template cache.
     */
    public function reset() {
        $this->template_cache = array();
    }

}
