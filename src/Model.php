<?php namespace Ovide\Lib\Translate;

/**
 * Extends a model to manage translations.
 *
 * You must attach into the DI a TranslationInterface adapter to manage the translations
 *
 * The table must not have translatable columns which must be declared in the $_translatable array.
 * Translations are saved/fetched after saving/fetching the main record.
 *
 * All the translatable fields are accessible by the methods getTranslation()
 * and setTranslation, or through the magic getter/setter, so you should add
 * those attributes as a comment in the class header.
 *
 *
 * @example
 * ```php
 * /**
 *  * My foo class
 *  *
 *  * @property string $description
 *  * /
 * class MyFooClass extends Ovide\Lib\Model\Translatable
 * {
 *     public $id;
 *     public $field1;
 *     public $field2;
 *     protected $_translatable = array('description');
 * }
 *
 * $di->setShared('translator', function() {
 *     //You can attach a backend for each database
 *     $service = new Ovide\Lib\Translate\Service();
 *     //All the translatable models in 'db' will have their translations in a mongo db
 *     $service->attach('db', \Ovide\Lib\Translate\Adapter\Mongo::class);
 *     return $service;
 * });
 *
 * $model = new MyFooClass();
 * $model->field1 = 'foo';
 * $model->field2 = 'bar';
 * $model->description = 'foo bar';
 * //field 'description' is saved in a mongodb after the main record
 * $model->save();
 * $model2 = MyFooClass::findByField1('foo');
 * echo $model2->description;
 * ```
 *
 * @author albert@ovide.net
 */
class Model extends \Phalcon\Mvc\Model
{
    /**
     * Array of translatable fields.
     * Add a @property annotation for each field instead of a public attribute
     * Don't create the column in the main table.
     *
     * @var string[]
     */
    protected $_translatable = [];

    /**
     * @var Service
     */
    protected $_translator = null;

    /**
     * An array of field names that form the PK
     *
     * @var array
     */
    protected $__pk = null;

    /**
     * Tries to resolve the current default language
     *
     * @see Model::setLanguageResolver()
     * @var \Closure
     */
    protected static $_langResolver = null;

    /**
     * Cached translations
     *
     * @var TranslationInterface
     */
    private $__translations = null;

    /**
     * The current language
     *
     * @var string
     */
    private $__language = 'en';

    /**
     * Modified fields to save
     *
     * @var array Formed by $language => $field
     */
    private $__updated = [];

    /**
     * Calls the initializer after create a new object
     */
    public function onConstruct()
    {
        $this->_init();
    }

    /**
     * {@inheritdoc}
     */
    public function create($data = null, $whiteList = null)
    {
        if (is_array($data)) {
            foreach (array_keys($data) as $field) {
                if (in_array($field, $this->_translatable)) {
                    $this->setTranslation($field, $data[$field]);
                }
            }
        }

        return parent::create($data, $whiteList);
    }

    /**
     * Calls the initializer after fetch a record
     */
    public function afterFetch()
    {
        $this->_init();
        $this->loadTranslations();
    }

    /**
     * Deletes the translation after the main record is deleted
     */
    public function afterDelete()
    {
        /*
        if (!$this->__translations) {
            $this->loadTranslations();
        }
         */

        $this->__translations->remove();
    }

    /**
     * Saves the translation after the main record is saved
     */
    public function afterSave()
    {
        if (!$this->__translations) {
            $this->loadTranslations();
        }

        $this->__translations->persist($this->__updated);
        $this->__updated = [];
    }

    /**
     * {@inheritdoc}
     */
    public function setConnectionService($connectionService)
    {
        /* @var $service \Ovide\Lib\Translate\Service */
        $service = $this->_dependencyInjector->getTranslator();
        parent::setConnectionService($connectionService);
        if (!$service->isModelBinded(static::class)) {
            $service->bindModelConnection($connectionService, static::class);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setReadConnectionService($connectionService)
    {
        parent::setReadConnectionService($connectionService);
        $this->_conRead = $connectionService;
    }

    /**
     * Sets the current language used for translations
     *
     * @param string $language
     */
    public function setCurrentLang($language)
    {
        $this->__language = $language;
    }

    /**
     * Gets the current language used for translations
     *
     * @return string
     */
    public function getCurrentLang()
    {
        return $this->__language;
    }

    /**
     * Returns the list of translatable fields for that model
     *
     * @return string[]
     */
    public function getTranslatableFields()
    {
        return $this->_translatable;
    }

    /**
     * Gets the translation of the called attribute (if is translatable)
     * for the current language
     *
     * @param  string $property
     * @return string
     */
    public function __get($property)
    {
        return in_array($property, $this->_translatable) ?
            $this->getTranslation($property) :
            parent::__get($property);
    }

    /**
     * Sets the translation of the called attribute (if is translatable)
     * for the current language
     *
     * @param string $property
     * @param string $value
     */
    public function __set($property, $value)
    {
        if (in_array($property, $this->_translatable)) {
            $this->setTranslation($property, $value);
        } else {
            parent::__set($property, $value);
        }
    }

    /**
     * Gets the translation of the called attribute (if is translatable)
     * for the language, or the current language if none specified.
     *
     * @param  string $field
     *                          The translatable attribute
     * @param  string $language
     *                          The language used
     * @return string
     *                         The translation
     */
    public function getTranslation($field, $language = null)
    {
        if ($language === null) {
            $language = $this->__language;
        }

        if(!$this->__translations){
            $this->afterFetch();
        }

        $translation = $this->__translations->get($field, $language);
        if ($translation === null || $translation === '') {
            return parent::__get($field);
        }
        return $translation;
    }

    /**
     * Sets the translation of the called attribute (if is translatable)
     * for the language, or the current language if none specified.
     *
     * @param string $field
     *                         The translatable attribute
     * @param string $value
     *                         The new value
     * @param string $language
     *                         The language used
     */
    public function setTranslation($field, $value, $language = null)
    {
        if ($language === null) {
            $language = $this->__language;
        }

        if (!$this->__translations) {
            $this->loadTranslations();
        }

        $this->__translations->set($field, $language, $value);

        if (!isset($this->__updated[$language])) {
            $this->__updated[$language] = [];
        }

        $this->__updated[$language][] = $field;
    }

    public function setTranslations(array $translations)
    {
        foreach ($translations as $field => $locales) {
            foreach ($locales as $locale => $value) {
                $this->setTranslation($field, $value, $locale);
            }
        }
    }

    public static function setLanguageResolver(\Closure $function)
    {
        static::$_langResolver = $function;
    }

    /**
     * Resolves the current language using the $_langResolver.
     * Default is 'en'
     *
     * @see setLanguageResolver
     * @return string
     */
    protected static function resolveLanguage()
    {
        if (static::$_langResolver === null) {
            return 'en';
        } else {
            $func = static::$_langResolver;

            return $func();
        }
    }

    /**
     * Initializer
     */
    private function _init()
    {
        $this->_translator = $this->_dependencyInjector->getTranslator();
        $this->setCurrentLang($this->resolveLanguage());
    }

    /**
     * Loads the translations from the interface
     */
    private function loadTranslations()
    {
        $key = $this->getKeyFilters();

        $adapter = $this->_translator->getAdapterFor(static::class);
        $options = isset($adapter['options']) ? $adapter['options'] : null;
        $class = $adapter['manager'];
        $this->__translations = $class::retrieve($this, $key, $options);
    }

    /**
     * Returns an array with the primary keys that identifies the row
     * @return array
     *               The key fields that identifies the main row
     */
    private function getKeyFilters()
    {
        if (!is_array($this->__pk)) {
            $metadata = $this->getModelsMetaData();
            $mapped = $metadata->getColumnMap($this);
            $keys = $metadata->getPrimaryKeyAttributes($this);

            foreach ($keys as $key) {
                $use = isset($mapped[$key]) ? $mapped[$key] : $key;
                $this->__pk[] = $use;
            }
        }

        return $this->__pk;
    }
}
