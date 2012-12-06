<?php


class DjaLoader {

    public static $template_source_loaders = null;

    public static function makeOrigin($display_name, $loader, $name, $dirs) {
        if (Dja::getSetting('TEMPLATE_DEBUG') && $display_name) {
            return new LoaderOrigin($display_name, $loader, $name, $dirs);
        }
        return null;
    }

    public static function findTemplateLoader($loader) {
        if (is_array($loader)) {
            $loader = $loader[0];
            $args = py_slice($loader, 1);
        } else {
            $args = array();
        }

        if (is_string($loader)) {

            $expl_ = explode('.', $loader);
            $module = join('.', py_slice($expl_, 0, -1));
            $attr = $expl_[count($expl_) - 1];

            try {
                $mod = import_module($module);
            } catch (ImportError $e) {
                throw new ImproperlyConfigured('Error importing template source loader ' . $loader . ': "' . $e . '"');
            }

            if (!($mod instanceof BaseLoader)) {
                throw new ImproperlyConfigured('Error importing template source loader ' . $loader . ': module doesn\'t return a BaseLoader descendant object.');
            }

            $TemplateLoader = $mod;

            if (isset($TemplateLoader->load_template_source)) {
                $func = new $TemplateLoader($args);
            } else {
                // Try loading module the old way - string is full path to callable
                if ($args) {
                    throw new ImproperlyConfigured('Error importing template source loader ' . $loader . ' - can\'t pass arguments to function-based loader.');
                }
                $func = $TemplateLoader;
            }
            return $func;
        } else {
            throw new ImproperlyConfigured('Loader does not define a "load_template" callable template source loader');
        }
    }


    public static function findTemplate($name, $dirs = null) {
        /*
         * Calculate template_source_loaders the first time the function is executed
         * because putting this logic in the module-level namespace may cause
         * circular import errors. See Django ticket #1292.
         */
        if (DjaLoader::$template_source_loaders === null) {
            $loaders = array();
            foreach (Dja::getSetting('TEMPLATE_LOADERS') as $loader_name) {
                $loader = DjaLoader::findTemplateLoader($loader_name);
                if ($loader !== null) {
                    $loaders[] = $loader;
                }
            }
            DjaLoader::$template_source_loaders = $loaders;
        }

        /** @var $loader Closure */
        foreach (DjaLoader::$template_source_loaders as $loader) {
            try {
                list ($source, $display_name) = $loader($name, $dirs);
                return array($source, self::makeOrigin($display_name, $loader, $name, $dirs));
            } catch (TemplateDoesNotExist $e) {
                continue;
            }
        }

        throw new TemplateDoesNotExist($name);
    }

    /**
     * Returns a compiled Template object for the given template name,
     * handling template inheritance recursively.
     *
     * @param $template_name
     *
     * @return Template
     */
    public static function getTemplate($template_name) {
        $self = get_called_class();
        $get_template = function() use ($template_name, $self) {
            list($template, $origin) = $self::findTemplate($template_name);
            if (!py_hasattr($template, 'render')) {
                // template needs to be compiled
                $template = $self::getTemplateFromString($template, $origin, $template_name);
            }
            return $template;
        };

        $use_cache = Dja::getSetting('TEMPLATE_CACHE');
        if (!$use_cache) {
            return $get_template();
        }
        $cacher = Dja::getCacheManager();
        if (!$template=$cacher->get($template_name)) {
            $template = $get_template();
            $cacher->set($template_name, $template);
        }
        return $template;
    }

    /**
     * Returns a compiled Template object for the given template code,
     * handling template inheritance recursively.
     *
     * @param $source
     * @param null $origin
     * @param null $name
     *
     * @return mixed
     */
    public static function getTemplateFromString($source, $origin = null, $name = null) {
        return new Template($source, $origin, $name);
    }

    /**
     * Loads the given template_name and renders it with the given dictionary as
     * context. The template_name may be a string to load a single template using
     * get_template, or it may be a tuple to use select_template to find one of
     * the templates in the list. Returns a string.
     *
     * @static
     *
     * @param $template_name
     * @param null|array $dictionary
     * @param null|Context $context_instance
     *
     * @return SafeString
     * @throws Exception
     */
    public static function renderToString($template_name, $dictionary=null, $context_instance=null) {
        if ($dictionary===null) {
            $dictionary = array();
        }

        if (is_array($template_name)) {
            $t = self::selectTemplate($template_name);
        } else {
            $t = self::getTemplate($template_name);
        }

        if ($context_instance===null) {
            return $t->render(new Context($dictionary));
        }

        // Add the dictionary to the context stack, ensuring it gets removed again
        // to keep the context_instance in the same state it started in.
        $context_instance->update($dictionary);

        // $r_ is a workaround for PHP missing `finally`.
        try {
            $r_ = $t->render($context_instance);
        } catch (Exception $e) {
            $r_ = $e;
        }
        $context_instance->pop();

        if ($r_ instanceof Exception) {
            throw $r_;
        }
        return $r_;
    }

    /**
     * Given a list of template names, returns the first that can be loaded.
     *
     * @static
     *
     * @param $template_name_list
     *
     * @return Template
     * @throws TemplateDoesNotExist
     */
    public static function selectTemplate($template_name_list) {
        if (!$template_name_list) {
            throw new TemplateDoesNotExist('No template names provided');
        }
        $not_found = array();

        foreach ($template_name_list as $template_name) {
            try {
                return self::getTemplate($template_name);
            } catch (TemplateDoesNotExist $e) {
                $msg_ = $e->getMessage();
                if (!in_array($msg_, $not_found)) {
                    $not_found[] = $msg_;
                }
                continue;
            }
        }

        // If we get here, none of the templates could be loaded
        throw new TemplateDoesNotExist(join(', ', $not_found));
    }

}


class BaseLoader {

    public $is_usable = False;

    public function __invoke($template_name, $template_dirs = null) {
        return $this->loadTemplate($template_name, $template_dirs);
    }

    public function loadTemplate($template_name, $template_dirs = null) {
        list ($source, $display_name) = $this->loadTemplateSource($template_name, $template_dirs);
        $origin = DjaLoader::makeOrigin($display_name, py_getattr($this, 'loadTemplateSource'), $template_name, $template_dirs);
        try {
            $template = DjaLoader::getTemplateFromString($source, $origin, $template_name);
            return array($template, null);
        } catch (TemplateDoesNotExist $e) {
            /*
             * If compiling the template we found raises TemplateDoesNotExist, back off to
             * returning the source and display name for the template we were asked to load.
             * This allows for correct identification (later) of the actual template that does not exist.
             */
            return array($source, $display_name);
        }
    }

    /**
     * Returns a tuple containing the source and origin for the given template name.
     *
     * @param $template_name
     * @param null $template_dirs
     *
     * @throws NotImplementedError
     */
    public function loadTemplateSource($template_name, $template_dirs = null) {
        throw new NotImplementedError();
    }

    /**
     * Resets any state maintained by the loader instance (e.g., cached
     * templates or cached loader modules).
     */
    public function reset() {
    }
}


class LoaderOrigin extends Origin {

    public function __construct($display_name, $loader, $name, $dirs) {
        parent::__construct($display_name);
        $this->loader = $loader;
        $this->loadname = $name;
        $this->dirs = $dirs;
    }

    public function reload() {
        $loader = $this->loader;
        $res_ = $loader($this->loadname, $this->dirs);
        return $res_[0];
    }
}

DjaBase::addToBuiltins('loader_tags');
