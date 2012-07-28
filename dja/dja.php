<?php

define('DJA_VERSION', '0.1');
define('DJA_ROOT', dirname(__FILE__) . DIRECTORY_SEPARATOR);

set_include_path(get_include_path() .
    PATH_SEPARATOR . DJA_ROOT .
    PATH_SEPARATOR . DJA_ROOT . 'loaders' .
    PATH_SEPARATOR . DJA_ROOT . 'template');


require 'dja_pyhelpers.php';
require 'dja_utils.php';
require 'template/base.php';
require 'template/context.php';
require 'template/loader.php';
require 'template/smartif.php';
require 'template/debug.php';


DjaBase::addToBuiltins('defaulttags');
DjaBase::addToBuiltins('defaultfilters');


class Dja {

    /**
     * Dja default settings.
     *
     * @var array
     */
    private static $_settings_default = array(

        'LANGUAGE_CODE' => 'en-us',
        'USE_L10N' => False,
        'USE_I18N' => True,

        'MEDIA_URL' => '',
        'STATIC_ROOT' => '',
        'STATIC_URL' => null,

        'USE_TZ' => False,
        'DATE_FORMAT' => 'N j, Y',
        'DATETIME_FORMAT' => 'N j, Y, P',
        'SHORT_DATE_FORMAT' => 'm/d/Y',
        'SHORT_DATETIME_FORMAT' => 'm/d/Y P',

        'TEMPLATE_DEBUG' => False,
        'TEMPLATE_STRING_IF_INVALID' => '',
        'TEMPLATE_LOADERS' => array(
            'loaders.filesystem.FilesystemLoader',
            'loaders.app_directories.AppDirectoriesLoader'
        ),
        'TEMPLATE_DIRS' => array(),
        'ALLOWED_INCLUDE_ROOTS'=>array(),

        'INSTALLED_APPS' => array(),
    );

    /**
     * @var null|array
     */
    private static $_settings = null;
    /**
     * @var null|IDjaUrlDispatcher
     */
    private static $_url_dispatcher = null;
    /**
     * @var null|IDjaI18n
     */
    private static $_i18n = null;

    /**
     * Sets Dja settings values.
     *
     * @static
     * @param array|null $settings
     */
    public static function setSettings(array $settings = null) {
        self::$_settings = self::$_settings_default;
        if ($settings !== null) {
            self::$_settings = array_merge(self::$_settings, $settings);
        }
    }

    /**
     * Sets Dja setting value.
     *
     * @static
     * @param string $name
     * @param mixed $value
     * @throws DjaException
     */
    public static function setSetting($name, $value) {
        if (self::$_settings === null) {
            self::setSettings();
        }
        if (!in_array($name, array_keys(self::$_settings_default))) {
            throw new DjaException('Unable to set an unknown setting \'' . $name . '\'.');
        }
        self::$_settings[$name] = $value;
    }

    /**
     * Returns Dja settings value.
     *
     * @static
     * @param string $name Setting name.
     * @return mixed
     * @throws DjaException
     */
    public static function getSetting($name) {
        if (self::$_settings === null) {
            self::setSettings();
        }
        if (!isset(self::$_settings[$name]) && !array_key_exists($name, self::$_settings)) {
            throw new DjaException('Unable to get an unknown setting \'' . $name . '\'.');
        }
        return self::$_settings[$name];
    }

    /**
     * Returns Dja root directory.
     *
     * @static
     * @return string
     */
    public static function getEnginePath() {
        return DJA_ROOT;
    }

    /**
     * Returns Dja version number.
     *
     * @static
     * @return string
     */
    public static function getVersion() {
        return DJA_VERSION;
    }

    /**
     * Returns object implementing internationalization functions.
     *
     * @static
     * @return IDjaI18n|null
     */
    public static function getI18n() {
        if (self::$_i18n === null) {
            self::$_i18n = new DjaI18n();
        }
        return self::$_i18n;
    }

    /**
     * Sets object implementing internationalization functions.
     * Such an object is required to implement IDjaI18n interface.
     *
     * @static
     * @param $obj
     * @throws DjaException
     */
    public static function setI18n($obj) {
        if (!($obj instanceof IDjaI18n)){
            throw new DjaException('Unable to use object not implementing IDjaI18n as internationalization interface.');
        }
        self::$_i18n = $obj;
    }

    /**
     * Returns URL Dispatcher object used by Dja to reverse URLs.
     *
     * @static
     * @return IDjaUrlDispatcher|null
     */
    public static function getUrlDispatcher() {
        if (self::$_url_dispatcher === null) {
            self::$_url_dispatcher = new DjaUrlDispatcher();
        }
        return self::$_url_dispatcher;
    }

    /**
     * Sets URL Dispatcher object used by Dja to reverse URLs.
     * Such an object is required to implement IDjaUrlDispatcher interface.
     *
     * @static
     * @param IDjaUrlDispatcher $obj
     * @throws DjaException
     */
    public static function setUrlDispatcher($obj) {
        if (!($obj instanceof IDjaUrlDispatcher)){
            throw new DjaException('Unable to use object not implementing IDjaUrlManager as URL Manager.');
        }
        self::$_url_dispatcher = $obj;
    }

    public static function getCache($key) {
        // TODO Call generic cache interface.
        if (!isset($GLOBALS['dja_cache'])) {
            return null;
        }
        $c = $GLOBALS['dja_cache'];
        if (isset($c[$key])) {
            return $c[$key][1];
        }
        return null;
    }

    public static function setCache($key, $value, $expires) {
        $GLOBALS['dja_cache'][$key] = array($expires, $value);
    }

}



/*
 * Dja exception classes.
 */


class DjaException extends Exception {}


class UrlNoReverseMatch extends DjaException {}


class TemplateSyntaxError extends DjaException {}


class TemplateDoesNotExist extends DjaException {}


class TemplateEncodingError extends DjaException {}


class VariableDoesNotExist extends DjaException {

    public function __construct($msg, $params = array()) {
        $this->msg = $msg;
        $this->params = $params;
    }

    public function __toString() {
        return $this->msg . ' ' . print_r($this->params, True);
    }

}



/*
 * Mimic Python exception classes.
 */


class InvalidTemplateLibrary extends DjaException {}


class ImproperlyConfigured extends DjaException {}


class PyException extends Exception {}


class NotImplementedError extends PyException {}


class TypeError extends PyException {}


class ValueError extends PyException {}


class KeyError extends PyException {}


class ImportError extends PyException {}


class RuntimeError extends PyException {}


class IndexError extends PyException {}


class AttributeError extends PyException {}