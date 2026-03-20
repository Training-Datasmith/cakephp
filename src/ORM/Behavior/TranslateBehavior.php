<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Behavior;

use ArrayObject;
use function Cake\Core\Namespace_Split;
use Cake\Datasource\Query_Interface;
use Cake\Event\Event_Interface;
use Cake\I18n\I18n;
use Cake\ORM\Behavior;
use Cake\ORM\Behavior\Translate\Shadow_Table_Strategy;
use Cake\ORM\Behavior\Translate\Translate_Strategy_Interface;
use Cake\ORM\Marshaller;
use Cake\ORM\Property_Marshal_Interface;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
/**
 * This behavior provides a way to translate dynamic data by keeping translations
 * in a separate table linked to the original record from another one. Translated
 * fields can be configured to override those in the main table when fetched or
 * put aside into another property for the same entity.
 *
 * If you wish to override fields, you need to call the `locale` method in this
 * behavior for setting the language you want to fetch from the translations table.
 *
 * If you want to bring all or certain languages for each of the fetched records,
 * you can use the custom `translations` finders that is exposed to the table.
 */
class Translate_Behavior extends Behavior implements Property_Marshal_Interface
{
    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['implementedFinders' => ['translations' => 'findTranslations'], 'implementedMethods' => ['setLocale' => 'setLocale', 'getLocale' => 'getLocale', 'translationField' => 'translationField', 'getStrategy' => 'getStrategy'], 'fields' => [], 'defaultLocale' => null, 'referenceName' => '', 'allowEmptyTranslations' => true, 'onlyTranslated' => false, 'strategy' => 'subquery', 'tableLocator' => null, 'validator' => false, 'strategyClass' => null];
    /**
     * Default strategy class name.
     *
     * @phpstan-var class-string<\Cake\ORM\Behavior\Translate\TranslateStrategyInterface>
     */
    protected static string $default_strategy_class = Shadow_Table_Strategy::class;
    /**
     * Translation strategy instance.
     */
    protected ?Translate_Strategy_Interface $strategy = null;
    /**
     * Constructor
     *
     * ### Options
     *
     * - `fields`: List of fields which need to be translated. Providing this fields
     *   list is mandatory when using `EavStrategy`. If the fields list is empty when
     *   using `ShadowTableStrategy` then the list will be auto generated based on
     *   shadow table schema.
     * - `defaultLocale`: The locale which is treated as default by the behavior.
     *   Fields values for default locale will be stored in the primary table itself
     *   and the rest in translation table. If not explicitly set the value of
     *   `I18n::getDefaultLocale()` will be used to get default locale.
     *   If you do not want any default locale and want translated fields
     *   for all locales to be stored in translation table then set this config
     *   to empty string `''`.
     * - `allowEmptyTranslations`: By default if a record has been translated and
     *   stored as an empty string the translate behavior will take and use this
     *   value to overwrite the original field value. If you don't want this behavior
     *   then set this option to `false`.
     * - `validator`: The validator that should be used when translation records
     *   are created/modified. Default `null`.
     *
     * @param \Cake\ORM\Table $table The table this behavior is attached to.
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function __construct(Table $table, array $config = [])
    {
        $config += ['defaultLocale' => I18n::get_default_locale(), 'referenceName' => $this->reference_name($table), 'tableLocator' => $table->associations()->get_table_locator()];
        parent::__construct($table, $config);
    }
    /**
     * Initialize hook
     *
     * @param array<string, mixed> $config The config for this behavior.
     */
    public function initialize(array $config): void
    {
        $this->get_strategy();
    }
    /**
     * Set default strategy class name.
     *
     * @param string $class Class name.
     * @since 4.0.0
     * @phpstan-param class-string<\Cake\ORM\Behavior\Translate\TranslateStrategyInterface> $class
     */
    public static function set_default_strategy_class(string $class): void
    {
        static::$default_strategy_class = $class;
    }
    /**
     * Get default strategy class name.
     *
     * @since 4.0.0
     * @phpstan-return class-string<\Cake\ORM\Behavior\Translate\TranslateStrategyInterface>
     */
    public static function get_default_strategy_class(): string
    {
        return static::$default_strategy_class;
    }
    /**
     * Get strategy class instance.
     *
     * @since 4.0.0
     */
    public function get_strategy(): Translate_Strategy_Interface
    {
        return $this->strategy ??= $this->create_strategy();
    }
    /**
     * Create strategy instance.
     *
     * @since 4.0.0
     */
    protected function create_strategy(): Translate_Strategy_Interface
    {
        $config = array_diff_key($this->_config, ['implementedFinders', 'implementedMethods', 'strategyClass']);
        /** @var class-string<\Cake\ORM\Behavior\Translate\TranslateStrategyInterface> $className */
        $class_name = $this->get_config('strategyClass', static::$default_strategy_class);
        return new $class_name($this->_table, $config);
    }
    /**
     * Set strategy class instance.
     *
     * @param \Cake\ORM\Behavior\Translate\TranslateStrategyInterface $strategy Strategy class instance.
     * @return $this
     * @since 4.0.0
     */
    public function set_strategy(Translate_Strategy_Interface $strategy): static
    {
        $this->strategy = $strategy;
        return $this;
    }
    /**
     * Gets the Model callbacks this behavior is interested in.
     *
     * @return array<string, mixed>
     */
    public function implemented_events(): array
    {
        return ['Model.beforeFind' => 'beforeFind', 'Model.beforeMarshal' => 'beforeMarshal', 'Model.beforeSave' => 'beforeSave', 'Model.afterSave' => 'afterSave'];
    }
    /**
     * Hoist fields for the default locale under `_translations` key to the root
     * in the data.
     *
     * This allows `_translations.{locale}.field_name` type naming even for the
     * default locale in forms.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event that was fired.
     * @param \ArrayObject<string, mixed> $data The data being marshalled.
     * @param \ArrayObject<string, mixed> $options The options for marshalling.
     */
    public function before_marshal(Event_Interface $event, ArrayObject $data, ArrayObject $options): void
    {
        if (isset($options['translations']) && !$options['translations']) {
            return;
        }
        $default_locale = $this->get_config('defaultLocale');
        if (!isset($data['_translations'][$default_locale])) {
            return;
        }
        foreach ($data['_translations'][$default_locale] as $field => $value) {
            $data[$field] = $value;
        }
        unset($data['_translations'][$default_locale]);
    }
    /**
     * {@inheritDoc}
     *
     * Add in `_translations` marshaling handlers. You can disable marshaling
     * of translations by setting `'translations' => false` in the options
     * provided to `Table::newEntity()` or `Table::patchEntity()`.
     *
     * @param \Cake\ORM\Marshaller $marshaller The marshaler of the table the behavior is attached to.
     * @param array<string, callable> $map The property map being built.
     * @param array<string, mixed> $options The options array used in the marshaling call.
     * @return array<string, callable> A map of `[property => callable]` of additional properties to marshal.
     */
    public function build_marshal_map(Marshaller $marshaller, array $map, array $options): array
    {
        return $this->get_strategy()->build_marshal_map($marshaller, $map, $options);
    }
    /**
     * Sets the locale that should be used for all future find and save operations on
     * the table where this behavior is attached to.
     *
     * When fetching records, the behavior will include the content for the locale set
     * via this method, and likewise when saving data, it will save the data in that
     * locale.
     *
     * Note that in case an entity has a `_locale` property set, that locale will win
     * over the locale set via this method (and over the globally configured one for
     * that matter)!
     *
     * @param string|null $locale The locale to use for fetching and saving records. Pass `null`
     * in order to unset the current locale, and to make the behavior falls back to using the
     * globally configured locale.
     * @return $this
     * @see \Cake\ORM\Behavior\TranslateBehavior::getLocale()
     * @link https://book.cakephp.org/5/en/orm/behaviors/translate.html#retrieving-one-language-without-using-i18n-setlocale
     * @link https://book.cakephp.org/5/en/orm/behaviors/translate.html#saving-in-another-language
     */
    public function set_locale(?string $locale): static
    {
        $this->get_strategy()->set_locale($locale);
        return $this;
    }
    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will return
     * the currently configured global locale.
     *
     * @see \Cake\I18n\I18n::getLocale()
     * @see \Cake\ORM\Behavior\TranslateBehavior::setLocale()
     */
    public function get_locale(): string
    {
        return $this->get_strategy()->get_locale();
    }
    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, the `content`
     * field with an alias of a corresponding association is returned. Table-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     */
    public function translation_field(string $field): string
    {
        return $this->get_strategy()->translation_field($field);
    }
    /**
     * Custom finder method used to retrieve all translations for the found records.
     * Fetched translations can be filtered by locale by passing the `locales` key
     * in the options array.
     *
     * Translated values will be found for each entity under the property `_translations`,
     * containing an array indexed by locale name.
     *
     * ### Example:
     *
     * ```
     * $article = $articles->find('translations', locales: ['eng', 'deu'])->first();
     * $englishTranslatedFields = $article->get('_translations')['eng'];
     * ```
     *
     * If the `locales` array is not passed, it will bring all translations found
     * for each record.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The original query to modify
     * @param array<string> $locales A list of locales or options with the `locales` key defined
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array>
     */
    public function find_translations(Select_Query $query, array $locales = []): Select_Query
    {
        $target_alias = $this->get_strategy()->get_translation_table()->get_alias();
        return $query->contain([$target_alias => function (Query_Interface $query) use ($locales, $target_alias): \Cake\Datasource\Query_Interface {
            if ($locales) {
                $query->where(["{$target_alias}.locale IN" => $locales]);
            }
            return $query;
        }])->format_results($this->get_strategy()->group_translations(...), Select_Query::PREPEND);
    }
    /**
     * Proxy method calls to strategy class instance.
     *
     * @param string $method Method name.
     * @param array $args Method arguments.
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->get_strategy()->{$method}(...$args);
    }
    /**
     * Determine the reference name to use for a given table
     *
     * The reference name is usually derived from the class name of the table object
     * (PostsTable -> Posts), however for autotable instances it is derived from
     * the database table the object points at - or as a last resort, the alias
     * of the autotable instance.
     *
     * @param \Cake\ORM\Table $table The table class to get a reference name for.
     */
    protected function reference_name(Table $table): string
    {
        $name = namespace_split($table::class);
        $name = substr(end($name), 0, -5);
        if (!$name) {
            $name = $table->get_table() ?: $table->get_alias();
            $name = Inflector::camelize($name);
        }
        return $name;
    }
}