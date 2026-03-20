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
use Cake\Collection\Collection_Interface;
use Cake\Core\Instance_Config_Trait;
use function Cake\Core\Plugin_Split;
use Cake\Database\Expression\Field_Interface;
use Cake\Database\Expression\Query_Expression;
use Cake\Datasource\Entity_Interface;
use Cake\Event\Event_Interface;
use Cake\ORM\Locator\Locator_Aware_Trait;
use Cake\ORM\Marshaller;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
use Cake\Utility\Hash;
/**
 * This class provides a way to translate dynamic data by keeping translations
 * in a separate shadow table where each row corresponds to a row of primary table.
 */
class Shadow_Table_Strategy implements Translate_Strategy_Interface
{
    use Instance_Config_Trait;
    use Locator_Aware_Trait;
    use Translate_Strategy_Trait {
        buildMarshalMap as private _buildMarshalMap;
    }
    /**
     * Default config
     *
     * These are merged with user-provided configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['fields' => [], 'defaultLocale' => null, 'referenceName' => null, 'allowEmptyTranslations' => true, 'onlyTranslated' => false, 'strategy' => 'subquery', 'tableLocator' => null, 'validator' => false];
    /**
     * Constructor
     *
     * @param \Cake\ORM\Table $table Table instance.
     * @param array<string, mixed> $config Configuration.
     */
    public function __construct(Table $table, array $config = [])
    {
        $table_alias = $table->get_alias();
        [$plugin] = plugin_split($table->get_registry_alias(), true);
        $table_reference_name = $config['referenceName'];
        $config += ['mainTableAlias' => $table_alias, 'translationTable' => $plugin . $table_reference_name . 'Translations', 'hasOneAlias' => $table_alias . 'Translation'];
        if (isset($config['tableLocator'])) {
            $this->_table_locator = $config['tableLocator'];
        }
        $this->set_config($config);
        $this->table = $table;
        $this->translation_table = $this->get_table_locator()->get($this->_config['translationTable'], ['allowFallbackClass' => true]);
        $this->setup_associations();
    }
    /**
     * Create a hasMany association for all records.
     *
     * Don't create a hasOne association here as the join conditions are modified
     * in before find - so create/modify it there.
     */
    protected function setup_associations(): void
    {
        $config = $this->get_config();
        $target_alias = $this->translation_table->get_alias();
        if ($this->table->associations()->has($target_alias)) {
            $this->table->associations()->remove($target_alias);
        }
        $this->table->has_many($target_alias, ['className' => $config['translationTable'], 'foreignKey' => 'id', 'strategy' => $config['strategy'], 'propertyName' => '_i18n', 'dependent' => true]);
    }
    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main table records.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeFind event that was fired.
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query.
     * @param \ArrayObject<string, mixed> $options The options for the query.
     */
    public function before_find(Event_Interface $event, Select_Query $query, ArrayObject $options): void
    {
        $locale = Hash::get($options, 'locale', $this->get_locale());
        $config = $this->get_config();
        if ($locale === $config['defaultLocale']) {
            return;
        }
        $this->setup_has_one_association($locale, $options);
        $fields_added = $this->add_fields_to_query($query, $config);
        $order_by_translated_field = $this->iterate_clause($query, 'order', $config);
        $filtered_by_translated_field = $this->traverse_clause($query, 'where', $config) || $config['onlyTranslated'] || ($options['filterByCurrentLocale'] ?? null);
        if (!$fields_added && !$order_by_translated_field && !$filtered_by_translated_field) {
            return;
        }
        $query->contain([$config['hasOneAlias']]);
        $query->format_results(fn(Collection_Interface $results): \Cake\Collection\Collection_Interface => $this->row_mapper($results, $locale), Select_Query::PREPEND);
    }
    /**
     * Create a hasOne association for record with required locale.
     *
     * @param string $locale Locale
     * @param \ArrayObject<string, mixed> $options Find options
     */
    protected function setup_has_one_association(string $locale, ArrayObject $options): void
    {
        $config = $this->get_config();
        [$plugin] = plugin_split($config['translationTable']);
        $has_one_target_alias = $plugin ? $plugin . '.' . $config['hasOneAlias'] : $config['hasOneAlias'];
        if (!$this->get_table_locator()->exists($has_one_target_alias)) {
            // Load table before hand with fallback class usage enabled
            $this->get_table_locator()->get($has_one_target_alias, ['className' => $config['translationTable'], 'allowFallbackClass' => true]);
        }
        if (isset($options['filterByCurrentLocale'])) {
            $join_type = $options['filterByCurrentLocale'] ? 'INNER' : 'LEFT';
        } else {
            $join_type = $config['onlyTranslated'] ? 'INNER' : 'LEFT';
        }
        if ($this->table->associations()->has($config['hasOneAlias'])) {
            $this->table->associations()->remove($config['hasOneAlias']);
        }
        $this->table->has_one($config['hasOneAlias'], ['foreignKey' => ['id'], 'joinType' => $join_type, 'propertyName' => 'translation', 'className' => $config['translationTable'], 'conditions' => [$config['hasOneAlias'] . '.locale' => $locale]]);
    }
    /**
     * Add translation fields to query.
     *
     * If the query is using autofields (directly or implicitly) add the
     * main table's fields to the query first.
     *
     * Only add translations for fields that are in the main table, always
     * add the locale field though.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to check.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function add_fields_to_query(Select_Query $query, array $config): bool
    {
        if ($query->is_auto_fields_enabled()) {
            return true;
        }
        $select = array_filter($query->clause('select'), is_string(...));
        if (!$select) {
            return true;
        }
        $alias = $config['mainTableAlias'];
        $join_required = false;
        foreach ($this->translated_fields() as $field) {
            if (array_intersect($select, [$field, "{$alias}.{$field}"])) {
                $join_required = true;
                $query->select($query->alias_field($field, $config['hasOneAlias']));
            }
        }
        if ($join_required) {
            $query->select($query->alias_field('locale', $config['hasOneAlias']));
        }
        return $join_required;
    }
    /**
     * Iterate over a clause to alias fields.
     *
     * The objective here is to transparently prevent ambiguous field errors by
     * prefixing fields with the appropriate table alias. This method currently
     * expects to receive an order clause only.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to check.
     * @param string $name The clause name.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function iterate_clause(Select_Query $query, string $name = '', array $config = []): bool
    {
        $clause = $query->clause($name);
        assert($clause === null || $clause instanceof Query_Expression);
        if (!$clause || !$clause->count()) {
            return false;
        }
        $alias = $config['hasOneAlias'];
        $fields = $this->translated_fields();
        $main_table_alias = $config['mainTableAlias'];
        $main_table_fields = $this->main_fields();
        $join_required = false;
        $clause->iterate_parts(function ($c, &$field) use ($fields, $alias, $main_table_alias, $main_table_fields, &$join_required) {
            if (!is_string($field) || str_contains($field, '.')) {
                return $c;
            }
            if (in_array($field, $fields, true)) {
                $join_required = true;
                $field = "{$alias}.{$field}";
            } elseif (in_array($field, $main_table_fields, true)) {
                $field = "{$main_table_alias}.{$field}";
            }
            return $c;
        });
        return $join_required;
    }
    /**
     * Traverse over a clause to alias fields.
     *
     * The objective here is to transparently prevent ambiguous field errors by
     * prefixing fields with the appropriate table alias. This method currently
     * expects to receive a where clause only.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query the query to check.
     * @param string $name The clause name.
     * @param array<string, mixed> $config The config to use for adding fields.
     * @return bool Whether a join to the translation table is required.
     */
    protected function traverse_clause(Select_Query $query, string $name = '', array $config = []): bool
    {
        /** @var \Cake\Database\Expression\QueryExpression|null $clause */
        $clause = $query->clause($name);
        if (!$clause || !$clause->count()) {
            return false;
        }
        $alias = $config['hasOneAlias'];
        $fields = $this->translated_fields();
        $main_table_alias = $config['mainTableAlias'];
        $main_table_fields = $this->main_fields();
        $join_required = false;
        $clause->traverse(function ($expression) use ($fields, $alias, $main_table_alias, $main_table_fields, &$join_required): void {
            if (!$expression instanceof Field_Interface) {
                return;
            }
            $field = $expression->get_field();
            if (!is_string($field) || str_contains($field, '.')) {
                return;
            }
            if (in_array($field, $fields, true)) {
                $join_required = true;
                $expression->set_field("{$alias}.{$field}");
                return;
            }
            if (in_array($field, $main_table_fields, true)) {
                $expression->set_field("{$main_table_alias}.{$field}");
            }
        });
        return $join_required;
    }
    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired.
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved.
     * @param \ArrayObject<string, mixed> $options the options passed to the save method.
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
        $bundled = $entity->has('_i18n') ? (array) $entity->get('_i18n') : [];
        $no_bundled = $bundled === [];
        // No additional translation records need to be saved,
        // as the entity is in the default locale.
        if ($no_bundled && $locale === $this->get_config('defaultLocale')) {
            return;
        }
        $values = $entity->extract($this->translated_fields(), true);
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
        $id = $entity->has($primary_key) ? $entity->get($primary_key) : null;
        // When we have no key and bundled translations, we
        // need to mark the entity dirty so the root
        // entity persists.
        if ($no_fields && $bundled && !$id) {
            foreach ($this->translated_fields() as $field) {
                $entity->set_dirty($field, true);
            }
            return;
        }
        if ($no_fields) {
            return;
        }
        $where = ['locale' => $locale];
        $translation = null;
        if ($id) {
            $where['id'] = $id;
            /** @var \Cake\Datasource\EntityInterface|null $translation */
            $translation = $this->translation_table->find()->select(array_merge(['id', 'locale'], $fields))->where($where)->first();
        }
        if ($translation) {
            if (method_exists($translation, 'patch')) {
                $translation->patch($values);
            } else {
                $translation->set($values);
            }
        } else {
            $translation = new ($this->translation_table->get_entity_class())($where + $values, ['useSetters' => false, 'markNew' => true]);
        }
        $entity->set('_i18n', array_merge($bundled, [$translation]));
        $entity->set('_locale', $locale, ['setter' => false]);
        $entity->set_dirty('_locale', false);
        foreach ($fields as $field) {
            $entity->set_dirty($field, false);
        }
    }
    /**
     * @inheritDoc
     */
    public function build_marshal_map(Marshaller $marshaller, array $map, array $options): array
    {
        $this->translated_fields();
        return $this->_build_marshal_map($marshaller, $map, $options);
    }
    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, field with
     * an alias of a corresponding association is returned. Table-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     */
    public function translation_field(string $field): string
    {
        if ($this->get_locale() === $this->get_config('defaultLocale')) {
            return $this->table->alias_field($field);
        }
        $translated_fields = $this->translated_fields();
        if (in_array($field, $translated_fields, true)) {
            return $this->get_config('hasOneAlias') . '.' . $field;
        }
        return $this->table->alias_field($field);
    }
    /**
     * Modifies the results from a table find in order to merge the translated
     * fields into each entity for a given locale.
     *
     * @param \Cake\Collection\CollectionInterface<mixed, mixed> $results Results to map.
     * @param string $locale Locale string
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    protected function row_mapper(Collection_Interface $results, string $locale): Collection_Interface
    {
        $allow_empty = $this->_config['allowEmptyTranslations'];
        return $results->map(function ($row) use ($allow_empty, $locale) {
            /** @var \Cake\Datasource\EntityInterface|array|null $row */
            if ($row === null) {
                return $row;
            }
            $hydrated = $row instanceof Entity_Interface;
            if (empty($row['translation'])) {
                $row['_locale'] = $locale;
                unset($row['translation']);
                if ($hydrated) {
                    /** @var \Cake\Datasource\EntityInterface $row */
                    $row->set_dirty('_locale', false);
                }
                return $row;
            }
            $translation = $row['translation'];
            assert($translation instanceof Entity_Interface || is_array($translation));
            if ($hydrated) {
                /** @var \Cake\Datasource\EntityInterface $translation */
                $keys = $translation->get_visible();
            } else {
                /** @var non-empty-array $translation */
                $keys = array_keys($translation);
            }
            foreach ($keys as $field) {
                if ($field === 'locale') {
                    $row['_locale'] = $translation[$field];
                    continue;
                }
                if ($translation[$field] !== null && ($allow_empty || $translation[$field] !== '')) {
                    $row[$field] = $translation[$field];
                    if ($hydrated) {
                        /** @var \Cake\Datasource\EntityInterface $row */
                        $row->set_dirty($field, false);
                    }
                }
            }
            unset($row['translation']);
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
            $result = [];
            foreach ($translations as $translation) {
                unset($translation['id']);
                $result[$translation['locale']] = $translation;
            }
            $row->set('_translations', $result)->set_dirty('_translations', false);
            unset($row['_i18n']);
            return $row;
        });
    }
    /**
     * Helper method used to generated multiple translated field entities
     * out of the data found in the `_translations` property in the passed
     * entity. The result will be put into its `_i18n` property.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     */
    protected function bundle_translated_fields(Entity_Interface $entity): void
    {
        /** @var array<string, \Cake\ORM\Entity> $translations */
        $translations = $entity->has('_translations') ? (array) $entity->get('_translations') : [];
        if (!$translations && !$entity->is_dirty('_translations')) {
            return;
        }
        if ($entity->is_new()) {
            $key = null;
        } else {
            $primary_key = (array) $this->table->get_primary_key();
            $key = $entity->get((string) current($primary_key));
        }
        foreach ($translations as $lang => $translation) {
            if ($translation->is_new()) {
                $update = ['locale' => $lang];
                if ($key !== null) {
                    $update['id'] = $key;
                }
                if (method_exists($translation, 'patch')) {
                    $translation->patch($update, ['guard' => false]);
                } else {
                    $translation->set($update, ['guard' => false]);
                }
            }
        }
        $entity->set('_i18n', $translations);
    }
    /**
     * Lazy define and return the main table fields.
     *
     * @return array<string>
     */
    protected function main_fields(): array
    {
        /** @var array<string> $fields */
        $fields = $this->get_config('mainTableFields');
        if ($fields) {
            return $fields;
        }
        $fields = $this->table->get_schema()->columns();
        $this->set_config('mainTableFields', $fields);
        return $fields;
    }
    /**
     * Lazy define and return the translation table fields.
     *
     * @return array<string>
     */
    protected function translated_fields(): array
    {
        $fields = $this->get_config('fields');
        if ($fields) {
            return $fields;
        }
        $table = $this->translation_table;
        $fields = $table->get_schema()->columns();
        $fields = array_values(array_diff($fields, ['id', 'locale']));
        $this->set_config('fields', $fields);
        return $fields;
    }
}