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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Behavior\Translate;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Collection\Collection_Interface;
use Cake\Core\Instance_Config_Trait;
use Cake\Datasource\Entity_Interface;
use Cake\Event\Event_Interface;
use Cake\ORM\Locator\Locator_Aware_Trait;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
use Cake\Utility\Hash;
/**
 * This class provides a way to translate dynamic data by keeping translations
 * in a separate table linked to the original record from another one. Translated
 * fields can be configured to override those in the main table when fetched or
 * put aside into another property for the same entity.
 *
 * If you wish to override fields, you need to call the `locale` method in this
 * behavior for setting the language you want to fetch from the translations table.
 *
 * If you want to bring all or certain languages for each of the fetched records,
 * you can use the custom `translations` finder of `TranslateBehavior` that is
 * exposed to the table.
 */
class Eav_Strategy implements Translate_Strategy_Interface
{
    use Instance_Config_Trait;
    use Locator_Aware_Trait;
    use Translate_Strategy_Trait;
    /**
     * Default config
     *
     * These are merged with user-provided configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['fields' => [], 'translationTable' => 'I18n', 'defaultLocale' => null, 'referenceName' => null, 'allowEmptyTranslations' => true, 'onlyTranslated' => false, 'strategy' => 'subquery', 'tableLocator' => null, 'validator' => false];
    /**
     * Constructor
     *
     * @param \Cake\ORM\Table $table The table this strategy is attached to.
     * @param array<string, mixed> $config The config for this strategy.
     */
    public function __construct(Table $table, array $config = [])
    {
        if (isset($config['tableLocator'])) {
            $this->_table_locator = $config['tableLocator'];
        }
        $this->set_config($config);
        $this->table = $table;
        $this->translation_table = $this->get_table_locator()->get($this->_config['translationTable'], ['allowFallbackClass' => true]);
        $this->setup_associations();
    }
    /**
     * Creates the associations between the bound table and every field passed to
     * this method.
     *
     * Additionally it creates a `i18n` HasMany association that will be
     * used for fetching all translations for each record in the bound table.
     */
    protected function setup_associations(): void
    {
        $fields = $this->_config['fields'];
        $table = $this->_config['translationTable'];
        $model = $this->_config['referenceName'];
        $strategy = $this->_config['strategy'];
        $filter = $this->_config['onlyTranslated'];
        $target_alias = $this->translation_table->get_alias();
        $alias = $this->table->get_alias();
        $table_locator = $this->get_table_locator();
        foreach ($fields as $field) {
            $name = $alias . '_' . $field . '_translation';
            if (!$table_locator->exists($name)) {
                $field_table = $table_locator->get($name, ['className' => $table, 'alias' => $name, 'table' => $this->translation_table->get_table(), 'allowFallbackClass' => true]);
            } else {
                $field_table = $table_locator->get($name);
            }
            $conditions = [$name . '.model' => $model, $name . '.field' => $field];
            if (!$this->_config['allowEmptyTranslations']) {
                $conditions[$name . '.content !='] = '';
            }
            if ($this->table->associations()->has($name)) {
                $this->table->associations()->remove($name);
            }
            $this->table->has_one($name, ['targetTable' => $field_table, 'foreignKey' => 'foreign_key', 'joinType' => $filter ? Select_Query::JOIN_TYPE_INNER : Select_Query::JOIN_TYPE_LEFT, 'conditions' => $conditions, 'propertyName' => $field . '_translation']);
        }
        $conditions = ["{$target_alias}.model" => $model];
        if (!$this->_config['allowEmptyTranslations']) {
            $conditions["{$target_alias}.content !="] = '';
        }
        if ($this->table->associations()->has($target_alias)) {
            $this->table->associations()->remove($target_alias);
        }
        $this->table->has_many($target_alias, ['className' => $table, 'foreignKey' => 'foreign_key', 'strategy' => $strategy, 'conditions' => $conditions, 'propertyName' => '_i18n', 'dependent' => true]);
    }
    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main table records.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeFind event that was fired.
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query
     * @param \ArrayObject<string, mixed> $options The options for the query
     */
    public function before_find(Event_Interface $event, Select_Query $query, ArrayObject $options): void
    {
        $locale = Hash::get($options, 'locale', $this->get_locale());
        if ($locale === $this->get_config('defaultLocale')) {
            return;
        }
        $conditions = fn(string $field, string $locale, Select_Query $query, array $select) => function (Select_Query $q) use ($field, $locale, $query, $select): \Cake\ORM\Query\Select_Query {
            $table = $q->get_repository();
            $q->where([$table->alias_field('locale') => $locale]);
            if ($query->is_auto_fields_enabled() || in_array($field, $select, true) || in_array($this->table->alias_field($field), $select, true)) {
                $q->select(['id', 'content']);
            }
            return $q;
        };
        $contain = [];
        $fields = $this->_config['fields'];
        $alias = $this->table->get_alias();
        $select = $query->clause('select');
        $change_filter = isset($options['filterByCurrentLocale']) && $options['filterByCurrentLocale'] !== $this->_config['onlyTranslated'];
        foreach ($fields as $field) {
            $name = $alias . '_' . $field . '_translation';
            $contain[$name]['queryBuilder'] = $conditions($field, $locale, $query, $select);
            if ($change_filter) {
                $filter = $options['filterByCurrentLocale'] ? Select_Query::JOIN_TYPE_INNER : Select_Query::JOIN_TYPE_LEFT;
                $contain[$name]['joinType'] = $filter;
            }
        }
        $query->contain($contain);
        $query->format_results(fn(Collection_Interface $results): \Cake\Collection\Collection_Interface => $this->row_mapper($results, $locale), Select_Query::PREPEND);
    }
    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject<string, mixed> $options the options passed to the save method
     */
    public function before_save(Event_Interface $event, Entity_Interface $entity, ArrayObject $options): void
    {
        $locale = $entity->has('_locale') ? $entity->get('_locale') : $this->get_locale();
        $new_options = [$this->translation_table->get_alias() => ['validate' => false]];
        $options['associated'] = $new_options + $options['associated'];
        // Check early if empty translations are present in the entity.
        // If this is the case, unset them to prevent persistence.
        // This only applies if $this->_config['allowEmptyTranslations'] is false
        if ($this->_config['allowEmptyTranslations'] === false) {
            $this->unset_empty_fields($entity);
        }
        $this->bundle_translated_fields($entity);
        $bundled = $entity->has('_i18n') ? $entity->get('_i18n') : [];
        $no_bundled = count($bundled) === 0;
        // No additional translation records need to be saved,
        // as the entity is in the default locale.
        if ($no_bundled && $locale === $this->get_config('defaultLocale')) {
            return;
        }
        $values = $entity->extract($this->_config['fields'], true);
        $fields = array_keys($values);
        $no_fields = $fields === [];
        // If there are no fields and no bundled translations, or both fields
        // in the default locale and bundled translations we can
        // skip the remaining logic as it is not necessary.
        if ($no_fields && $no_bundled || $fields && $bundled) {
            return;
        }
        /** @var string $primaryKey */
        $primary_key = current((array) $this->table->get_primary_key());
        $key = $entity->has($primary_key) ? $entity->get($primary_key) : null;
        // When we have no key and bundled translations, we
        // need to mark the entity dirty so the root
        // entity persists.
        if ($no_fields && $bundled && !$key) {
            foreach ($this->_config['fields'] as $field) {
                $entity->set_dirty($field, true);
            }
            return;
        }
        if ($no_fields) {
            return;
        }
        $model = $this->_config['referenceName'];
        $preexistent = [];
        if ($key) {
            /** @var \Traversable<string, \Cake\Datasource\EntityInterface> $preexistent */
            $preexistent = $this->translation_table->find()->select(['id', 'field'])->where(['field IN' => $fields, 'locale' => $locale, 'foreign_key' => $key, 'model' => $model])->all()->index_by('field');
        }
        $modified = [];
        foreach ($preexistent as $field => $translation) {
            $translation->set('content', $values[$field]);
            $modified[$field] = $translation;
        }
        $entity_class = $this->translation_table->get_entity_class();
        $new = array_diff_key($values, $modified);
        foreach ($new as $field => $content) {
            $new[$field] = new $entity_class(compact('locale', 'field', 'content', 'model'), ['useSetters' => false, 'markNew' => true]);
        }
        $entity->set('_i18n', array_merge($bundled, array_values($modified + $new)));
        $entity->set('_locale', $locale, ['setter' => false]);
        $entity->set_dirty('_locale', false);
        foreach ($fields as $field) {
            $entity->set_dirty($field, false);
        }
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
        $table = $this->table;
        if ($this->get_locale() === $this->get_config('defaultLocale')) {
            return $table->alias_field($field);
        }
        $association_name = $table->get_alias() . '_' . $field . '_translation';
        if ($table->associations()->has($association_name)) {
            return $association_name . '.content';
        }
        return $table->alias_field($field);
    }
    /**
     * Modifies the results from a table find in order to merge the translated fields
     * into each entity for a given locale.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to map.
     * @param string $locale Locale string
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    protected function row_mapper(Collection_Interface $results, string $locale): Collection_Interface
    {
        return $results->map(function ($row) use ($locale) {
            /** @var \Cake\Datasource\EntityInterface|array|null $row */
            if ($row === null) {
                return $row;
            }
            $hydrated = $row instanceof Entity_Interface;
            foreach ($this->_config['fields'] as $field) {
                $name = $field . '_translation';
                $translation = $row[$name] ?? null;
                if ($translation === null || $translation === false) {
                    unset($row[$name]);
                    continue;
                }
                $content = $translation['content'] ?? null;
                if ($content !== null) {
                    $row[$field] = $content;
                    if ($hydrated) {
                        /** @var \Cake\Datasource\EntityInterface $row */
                        $row->set_dirty($field, false);
                    }
                }
                unset($row[$name]);
            }
            $row['_locale'] = $locale;
            if ($hydrated) {
                /** @var \Cake\Datasource\EntityInterface $row */
                $row->set_dirty('_locale', false);
            }
            return $row;
        });
    }
    /**
     * Modifies the results from a table find in order to merge full translation
     * records into each entity under the `_translations` key.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to modify.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function group_translations(Collection_Interface $results): Collection_Interface
    {
        return $results->map(function ($row) {
            if (!$row instanceof Entity_Interface) {
                return $row;
            }
            $translations = $row->has('_i18n') ? $row->get('_i18n') : [];
            if ($translations === []) {
                if ($row->has('_translations')) {
                    return $row;
                }
                $row->set('_translations', [])->set_dirty('_translations', false);
                unset($row['_i18n']);
                return $row;
            }
            $grouped = new Collection($translations);
            $entity_class = $this->table->get_entity_class();
            $result = [];
            foreach ($grouped->combine('field', 'content', 'locale') as $locale => $keys) {
                $translation = new $entity_class($keys + ['locale' => $locale], ['markNew' => false, 'useSetters' => false, 'markClean' => true]);
                $result[$locale] = $translation;
            }
            $row->set('_translations', $result, ['setter' => false, 'guard' => false])->set_dirty('_translations', false);
            unset($row['_i18n']);
            return $row;
        });
    }
    /**
     * Helper method used to generated multiple translated field entities
     * out of the data found in the `_translations` property in the passed
     * entity. The result will be put into its `_i18n` property.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     */
    protected function bundle_translated_fields(Entity_Interface $entity): void
    {
        /** @var array<string, \Cake\Datasource\EntityInterface> $translations */
        $translations = $entity->has('_translations') ? (array) $entity->get('_translations') : [];
        if (!$translations && !$entity->is_dirty('_translations')) {
            return;
        }
        $fields = $this->_config['fields'];
        if ($entity->is_new()) {
            $key = null;
        } else {
            $primary_key = (array) $this->table->get_primary_key();
            $key = $entity->get((string) current($primary_key));
        }
        $find = [];
        /** @var array<\Cake\Datasource\EntityInterface> $contents */
        $contents = [];
        $entity_class = $this->translation_table->get_entity_class();
        foreach ($translations as $lang => $translation) {
            foreach ($fields as $field) {
                if (!$translation->is_dirty($field)) {
                    continue;
                }
                $find[] = ['locale' => $lang, 'field' => $field, 'foreign_key IS' => $key];
                $contents[] = new $entity_class(['content' => $translation->get($field)], ['useSetters' => false]);
            }
        }
        if (!$find) {
            return;
        }
        $results = $this->find_existing_translations($find);
        foreach ($find as $i => $translation) {
            if (!empty($results[$i])) {
                $contents[$i]->set('id', $results[$i], ['setter' => false]);
                $contents[$i]->set_new(false);
            } else {
                $translation['model'] = $this->_config['referenceName'];
                unset($translation['foreign_key IS']);
                if (method_exists($contents[$i], 'patch')) {
                    $contents[$i]->patch($translation, ['setter' => false, 'guard' => false]);
                } else {
                    $contents[$i]->set($translation, ['setter' => false, 'guard' => false]);
                }
                $contents[$i]->set_new(true);
            }
        }
        $entity->set('_i18n', $contents);
    }
    /**
     * Returns the ids found for each of the condition arrays passed for the
     * translations table. Each records is indexed by the corresponding position
     * to the conditions array.
     *
     * @param array $ruleSet An array of array of conditions to be used for finding each
     */
    protected function find_existing_translations(array $rule_set): array
    {
        $association = $this->table->get_association($this->translation_table->get_alias());
        $query = $association->find()->select(['id', 'num' => 0])->where(current($rule_set))->disable_hydration();
        unset($rule_set[0]);
        foreach ($rule_set as $i => $conditions) {
            $q = $association->find()->select(['id', 'num' => $i])->where($conditions);
            $query->union_all($q);
        }
        return $query->all()->combine('num', 'id')->to_array();
    }
}