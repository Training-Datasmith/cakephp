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
namespace Cake\ORM;

use Cake\ORM\Query\Select_Query;
use Closure;
use InvalidArgumentException;
/**
 * Exposes the methods for storing the associations that should be eager loaded
 * for a table once a query is provided and delegates the job of creating the
 * required joins and decorating the results so that those associations can be
 * part of the result set.
 */
class Eager_Loader
{
    /**
     * Nested array describing the association to be fetched
     * and the options to apply for each of them, if any
     *
     * @var array<string, array>
     */
    protected array $_containments = [];
    /**
     * Contains a nested array with the compiled containments tree
     * This is a normalized version of the user provided containments array.
     *
     * @var array<string, \Cake\ORM\EagerLoadable>|null
     */
    protected ?array $_normalized = null;
    /**
     * List of options accepted by associations in contain()
     * index by key for faster access.
     *
     * @var array<string, int>
     */
    protected array $_contain_options = ['associations' => 1, 'foreignKey' => 1, 'conditions' => 1, 'fields' => 1, 'sort' => 1, 'matching' => 1, 'queryBuilder' => 1, 'finder' => 1, 'joinType' => 1, 'strategy' => 1, 'negateMatch' => 1, 'includeFields' => 1];
    /**
     * A list of associations that should be loaded with a separate query.
     *
     * @var array<int, \Cake\ORM\EagerLoadable>
     */
    protected array $_load_external = [];
    /**
     * Contains a list of the association names that are to be eagerly loaded.
     *
     * @var array<string, array<string, array<int, \Cake\ORM\EagerLoadable>>>
     */
    protected array $_alias_list = [];
    /**
     * Another EagerLoader instance that will be used for 'matching' associations.
     */
    protected ?Eager_Loader $_matching = null;
    /**
     * A map of table aliases pointing to the association objects they represent
     * for the query.
     *
     * @var array<string, \Cake\ORM\EagerLoadable>
     */
    protected array $_joins_map = [];
    /**
     * Controls whether fields from associated tables will be eagerly loaded.
     * When set to false, no fields will be loaded from associations.
     */
    protected bool $_auto_fields = true;
    /**
     * Sets the list of associations that should be eagerly loaded along for a
     * specific table using when a query is provided. The list of associated tables
     * passed to this method must have been previously set as associations using the
     * Table API.
     *
     * Associations can be arbitrarily nested using dot notation or nested arrays,
     * this allows this object to calculate joins or any additional queries that
     * must be executed to bring the required associated data.
     *
     * Accepted options per passed association:
     *
     * - `foreignKey`: Used to set a different field to match both tables, if set to false
     *   no join conditions will be generated automatically
     * - `fields`: An array with the fields that should be fetched from the association
     * - `queryBuilder`: Equivalent to passing a callback instead of an options array
     * - `matching`: Whether to inform the association class that it should filter the
     *  main query by the results fetched by that class.
     * - `joinType`: For joinable associations, the SQL join type to use.
     * - `strategy`: The loading strategy to use (join, select, subquery)
     *
     * @param array|string $associations List of table aliases to be queried.
     * When this method is called multiple times it will merge previous list with
     * the new one.
     * @param \Closure|null $queryBuilder The query builder callback.
     * @return array Containments.
     * @throws \InvalidArgumentException When using $queryBuilder with an array of $associations
     */
    public function contain(array|string $associations, ?Closure $query_builder = null): array
    {
        if ($query_builder) {
            if (!is_string($associations)) {
                throw new InvalidArgumentException('Cannot set containments. To use $queryBuilder, $associations must be a string');
            }
            $associations = [$associations => ['queryBuilder' => $query_builder]];
        }
        $associations = (array) $associations;
        $associations = $this->_reformat_contain($associations, $this->_containments);
        $this->_normalized = null;
        $this->_load_external = [];
        $this->_alias_list = [];
        return $this->_containments = $associations;
    }
    /**
     * Gets the list of associations that should be eagerly loaded along for a
     * specific table using when a query is provided. The list of associated tables
     * passed to this method must have been previously set as associations using the
     * Table API.
     *
     * @return array Containments.
     */
    public function get_contain(): array
    {
        return $this->_containments;
    }
    /**
     * Remove any existing non-matching based containments.
     *
     * This will reset/clear out any contained associations that were not
     * added via matching().
     */
    public function clear_contain(): void
    {
        $this->_containments = [];
        $this->_normalized = null;
        $this->_load_external = [];
        $this->_alias_list = [];
    }
    /**
     * Sets whether contained associations will load fields automatically.
     *
     * @param bool $enable The value to set.
     * @return $this
     */
    public function enable_auto_fields(bool $enable = true): static
    {
        $this->_auto_fields = $enable;
        return $this;
    }
    /**
     * Disable auto loading fields of contained associations.
     *
     * @return $this
     */
    public function disable_auto_fields(): static
    {
        $this->_auto_fields = false;
        return $this;
    }
    /**
     * Gets whether contained associations will load fields automatically.
     *
     * @return bool The current value.
     */
    public function is_auto_fields_enabled(): bool
    {
        return $this->_auto_fields;
    }
    /**
     * Adds a new association to the list that will be used to filter the results of
     * any given query based on the results of finding records for that association.
     * You can pass a dot separated path of associations to this method as its first
     * parameter, this will translate in setting all those associations with the
     * `matching` option.
     *
     *  ### Options
     *
     *  - `joinType`: INNER, OUTER, ...
     *  - `fields`: Fields to contain
     *  - `negateMatch`: Whether to add conditions negate match on target association
     *
     * @param string $associationPath Dot separated association path, 'Name1.Name2.Name3'.
     * @param \Closure|null $builder the callback function to be used for setting extra
     * options to the filtering query.
     * @param array<string, mixed> $options Extra options for the association matching.
     * @return $this
     */
    public function set_matching(string $association_path, ?Closure $builder = null, array $options = []): static
    {
        $this->_matching ??= new static();
        $options += ['joinType' => Select_Query::JOIN_TYPE_INNER];
        $shared_options = ['negateMatch' => false, 'matching' => true] + $options;
        $contains = [];
        $nested =& $contains;
        foreach (explode('.', $association_path) as $association) {
            // Add contain to parent contain using association name as key
            $nested[$association] = $shared_options;
            // Set to next nested level
            $nested =& $nested[$association];
        }
        // Add all options to target association contain which is the last in nested chain
        $nested = ['matching' => true, 'queryBuilder' => $builder] + $options;
        $this->_matching->contain($contains);
        return $this;
    }
    /**
     * Returns the current tree of associations to be matched.
     *
     * @return array The resulting containments array.
     */
    public function get_matching(): array
    {
        $this->_matching ??= new static();
        return $this->_matching->get_contain();
    }
    /**
     * Returns the fully normalized array of associations that should be eagerly
     * loaded for a table. The normalized array will restructure the original array
     * by sorting all associations under one key and special options under another.
     *
     * Each of the levels of the associations tree will be converted to a {@link \Cake\ORM\EagerLoadable}
     * object, that contains all the information required for the association objects
     * to load the information from the database.
     *
     * Additionally, it will set an 'instance' key per association containing the
     * association instance from the corresponding source table
     *
     * @param \Cake\ORM\Table $repository The table containing the association that
     * will be normalized.
     * @return array<string, \Cake\ORM\EagerLoadable>
     */
    public function normalized(Table $repository): array
    {
        if ($this->_normalized !== null) {
            return $this->_normalized;
        }
        $contain = [];
        foreach ($this->_containments as $alias => $options) {
            $contain[$alias] = $this->_normalize_contain($repository, $alias, $options, ['root' => '']);
        }
        return $this->_normalized = $contain;
    }
    /**
     * Formats the containments array so that associations are always set as keys
     * in the array. This function merges the original associations array with
     * the new associations provided.
     *
     * @param array $associations User provided containments array.
     * @param array $original The original containments array to merge
     * with the new one.
     * @return array<string, array>
     */
    protected function _reformat_contain(array $associations, array $original): array
    {
        $result = $original;
        foreach ($associations as $table => $options) {
            $pointer =& $result;
            if (is_int($table)) {
                $table = $options;
                $options = [];
            }
            if ($options instanceof Eager_Loadable) {
                $options = $options->as_contain_array();
                $table = key($options);
                $options = current($options);
            }
            if (isset($this->_contain_options[$table])) {
                $pointer[$table] = $options;
                continue;
            }
            if (str_contains((string) $table, '.')) {
                $path = explode('.', (string) $table);
                $table = array_pop($path);
                foreach ($path as $t) {
                    $pointer += [$t => []];
                    $pointer =& $pointer[$t];
                }
            }
            if (is_array($options)) {
                // When options come from asContainArray(), they have 'config' and 'associations' keys
                // We need to keep them separate to avoid config options being treated as associations
                if (isset($options['config'], $options['associations'])) {
                    // Process associations recursively, but keep config separate
                    $associations = $this->_reformat_contain($options['associations'], $pointer[$table] ?? []);
                    // Merge config with associations, ensuring config options stay as options
                    $options = $options['config'] + $associations;
                } else {
                    $options = $this->_reformat_contain($options, $pointer[$table] ?? []);
                }
            }
            if ($options instanceof Closure) {
                $options = ['queryBuilder' => $options];
            }
            $pointer += [$table => []];
            if (isset($options['queryBuilder'], $pointer[$table]['queryBuilder'])) {
                assert(is_callable($pointer[$table]['queryBuilder']));
                $first = $pointer[$table]['queryBuilder'];
                assert(is_callable($options['queryBuilder']));
                $second = $options['queryBuilder'];
                $options['queryBuilder'] = fn($query) => $second($first($query));
            }
            if (!is_array($options)) {
                $options = [$options => []];
            }
            $pointer[$table] = $options + $pointer[$table];
        }
        return $result;
    }
    /**
     * Modifies the passed query to apply joins or any other transformation required
     * in order to eager load the associations described in the `contain` array.
     * This method will not modify the query for loading external associations, i.e.
     * those that cannot be loaded without executing a separate query.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query to be modified.
     * @param \Cake\ORM\Table $repository The repository containing the associations
     * @param bool $includeFields whether to append all fields from the associations
     * to the passed query. This can be overridden according to the settings defined
     * per association in the containments array.
     */
    public function attach_associations(Select_Query $query, Table $repository, bool $include_fields): void
    {
        if (!$this->_containments && $this->_matching === null) {
            return;
        }
        $attachable = $this->attachable_associations($repository);
        $processed = [];
        do {
            foreach ($attachable as $alias => $loadable) {
                $config = $loadable->get_config() + ['aliasPath' => $loadable->alias_path(), 'propertyPath' => $loadable->property_path(), 'includeFields' => $include_fields];
                $loadable->instance()->attach_to($query, $config);
                $processed[$alias] = true;
            }
            $new_attachable = $this->attachable_associations($repository);
            $attachable = array_diff_key($new_attachable, $processed);
        } while ($attachable !== []);
    }
    /**
     * Returns an array with the associations that can be fetched using a single query,
     * the array keys are the association aliases and the values will contain an array
     * with Cake\ORM\EagerLoadable objects.
     *
     * @param \Cake\ORM\Table $repository The table containing the associations to be
     * attached.
     * @return array<string, \Cake\ORM\EagerLoadable>
     */
    public function attachable_associations(Table $repository): array
    {
        $contain = $this->normalized($repository);
        $matching = $this->_matching ? $this->_matching->normalized($repository) : [];
        $this->_fix_strategies();
        $this->_load_external = [];
        return $this->_resolve_joins($contain, $matching);
    }
    /**
     * Returns an array with the associations that need to be fetched using a
     * separate query, each array value will contain a {@link \Cake\ORM\EagerLoadable} object.
     *
     * @param \Cake\ORM\Table $repository The table containing the associations
     * to be loaded.
     * @return array<\Cake\ORM\EagerLoadable>
     */
    public function external_associations(Table $repository): array
    {
        if ($this->_load_external) {
            return $this->_load_external;
        }
        $this->attachable_associations($repository);
        return $this->_load_external;
    }
    /**
     * Auxiliary function responsible for fully normalizing deep associations defined
     * using `contain()`.
     *
     * @param \Cake\ORM\Table $parent Owning side of the association.
     * @param string $alias Name of the association to be loaded.
     * @param array<string, mixed> $options List of extra options to use for this association.
     * @param array<string, mixed> $paths An array with two values, the first one is a list of dot
     * separated strings representing associations that lead to this `$alias` in the
     * chain of associations to be loaded. The second value is the path to follow in
     * entities' properties to fetch a record of the corresponding association.
     * @return \Cake\ORM\EagerLoadable Object with normalized associations
     * @throws \InvalidArgumentException When containments refer to associations that do not exist.
     */
    protected function _normalize_contain(Table $parent, string $alias, array $options, array $paths): Eager_Loadable
    {
        $defaults = $this->_contain_options;
        $instance = $parent->get_association($alias);
        $paths += ['aliasPath' => '', 'propertyPath' => '', 'root' => $alias];
        $paths['aliasPath'] .= '.' . $alias;
        if (isset($options['matching']) && $options['matching'] === true) {
            $paths['propertyPath'] = '_matchingData.' . $alias;
        } else {
            $paths['propertyPath'] .= '.' . $instance->get_property();
        }
        $table = $instance->get_target();
        $extra = array_diff_key($options, $defaults);
        $config = ['associations' => [], 'instance' => $instance, 'config' => array_diff_key($options, $extra), 'aliasPath' => trim($paths['aliasPath'], '.'), 'propertyPath' => trim($paths['propertyPath'], '.'), 'targetProperty' => $instance->get_property()];
        $config['canBeJoined'] = $instance->can_be_joined($config['config']);
        $eager_loadable = new Eager_Loadable($alias, $config);
        if ($config['canBeJoined']) {
            $this->_alias_list[$paths['root']][$alias][] = $eager_loadable;
        } else {
            $paths['root'] = $config['aliasPath'];
        }
        foreach ($extra as $t => $assoc) {
            $eager_loadable->add_association($t, $this->_normalize_contain($table, $t, $assoc, $paths));
        }
        return $eager_loadable;
    }
    /**
     * Iterates over the joinable aliases list and corrects the fetching strategies
     * in order to avoid aliases collision in the generated queries.
     *
     * This function operates on the array references that were generated by the
     * _normalizeContain() function.
     */
    protected function _fix_strategies(): void
    {
        foreach ($this->_alias_list as $aliases) {
            foreach ($aliases as $configs) {
                if (count($configs) < 2) {
                    continue;
                }
                foreach ($configs as $loadable) {
                    if (str_contains($loadable->alias_path(), '.')) {
                        $this->_correct_strategy($loadable);
                    }
                }
            }
        }
    }
    /**
     * Changes the association fetching strategy if required because of duplicate
     * under the same direct associations chain.
     *
     * @param \Cake\ORM\EagerLoadable $loadable The association config.
     */
    protected function _correct_strategy(Eager_Loadable $loadable): void
    {
        $config = $loadable->get_config();
        $current_strategy = $config['strategy'] ?? Association::STRATEGY_JOIN;
        if (!$loadable->can_be_joined() || $current_strategy !== Association::STRATEGY_JOIN) {
            return;
        }
        $config['strategy'] = Association::STRATEGY_SELECT;
        $loadable->set_config($config);
        $loadable->set_can_be_joined(false);
    }
    /**
     * Helper function used to compile a list of all associations that can be
     * joined in the query.
     *
     * @param array<string, \Cake\ORM\EagerLoadable> $associations List of associations from which to obtain joins.
     * @param array<string, \Cake\ORM\EagerLoadable> $matching List of associations that should be forcibly joined.
     * @return array<string, \Cake\ORM\EagerLoadable>
     */
    protected function _resolve_joins(array $associations, array $matching = []): array
    {
        $result = [];
        foreach ($matching as $table => $loadable) {
            $result[$table] = $loadable;
            $result = $this->merge_joins($result, $this->_resolve_joins($loadable->associations(), []));
        }
        foreach ($associations as $table => $loadable) {
            $in_matching = isset($matching[$table]);
            if (!$in_matching && $loadable->can_be_joined()) {
                $result[$table] = $loadable;
                $result = $this->merge_joins($result, $this->_resolve_joins($loadable->associations(), []));
                continue;
            }
            if ($in_matching) {
                $this->_correct_strategy($loadable);
            }
            $loadable->set_can_be_joined(false);
            $this->_load_external[] = $loadable;
        }
        return $result;
    }
    /**
     * Merges association joins and throws an exception if there are conflicts.
     *
     * @param array<string, \Cake\ORM\EagerLoadable> $a
     * @param array<string, \Cake\ORM\EagerLoadable> $b
     * @return array<string, \Cake\ORM\EagerLoadable>
     */
    private function merge_joins(array $a, array $b): array
    {
        foreach ($b as $alias => $loadable) {
            if (isset($a[$alias])) {
                assert(false, sprintf('You cannot join with `%s` because it conflicts with the existing `%s` join.' . ' The existing join will be lost.', $loadable->alias_path(), $a[$alias]->alias_path()));
            }
        }
        return $a + $b;
    }
    /**
     * Inject data from associations that cannot be joined directly.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query for which to eager load external.
     * associations.
     * @param iterable $results Results.
     * @throws \RuntimeException
     */
    public function load_external(Select_Query $query, iterable $results): iterable
    {
        if (!$results) {
            return $results;
        }
        $external = $this->external_associations($query->get_repository());
        if (!$external) {
            return $results;
        }
        if (!is_array($results)) {
            $results = iterator_to_array($results);
        }
        if (!$results) {
            return $results;
        }
        $collected = $this->_collect_keys($external, $query, $results);
        foreach ($external as $meta) {
            $contain = $meta->associations();
            $instance = $meta->instance();
            $config = $meta->get_config();
            $alias = $instance->get_source()->get_alias();
            $path = $meta->alias_path();
            $requires_keys = $instance->requires_keys($config);
            if ($requires_keys) {
                // If the path or alias has no key the required association load will fail.
                // Nested paths are not subject to this condition because they could
                // be attached to joined associations.
                if (!str_contains($path, '.') && (!array_key_exists($path, $collected) || !array_key_exists($alias, $collected[$path]))) {
                    $message = "Unable to load `{$path}` association. Ensure foreign key in `{$alias}` is selected.";
                    throw new InvalidArgumentException($message);
                }
                // If the association foreign keys are missing skip loading
                // as the association could be optional.
                if (empty($collected[$path][$alias])) {
                    continue;
                }
            }
            $keys = $collected[$path][$alias] ?? null;
            $callback = $instance->eager_loader($config + ['query' => $query, 'contain' => $contain, 'keys' => $keys, 'nestKey' => $meta->alias_path()]);
            $results = array_map($callback, $results);
        }
        return $results;
    }
    /**
     * Returns an array having as keys a dotted path of associations that participate
     * in this eager loader. The values of the array will contain the following keys:
     *
     * - `alias`: The association alias
     * - `instance`: The association instance
     * - `canBeJoined`: Whether the association will be loaded using a JOIN
     * - `entityClass`: The entity that should be used for hydrating the results
     * - `nestKey`: A dotted path that can be used to correctly insert the data into the results.
     * - `matching`: Whether it is an association loaded through `matching()`.
     *
     * @param \Cake\ORM\Table $table The table containing the association that
     * will be normalized.
     */
    public function associations_map(Table $table): array
    {
        $map = [];
        if (!$this->get_matching() && !$this->get_contain() && $this->_joins_map === []) {
            return $map;
        }
        assert($this->_matching !== null, 'EagerLoader not available');
        $map = $this->_build_associations_map($map, $this->_matching->normalized($table), true);
        $map = $this->_build_associations_map($map, $this->normalized($table));
        return $this->_build_associations_map($map, $this->_joins_map);
    }
    /**
     * An internal method to build a map which is used for the return value of the
     * associationsMap() method.
     *
     * @param array $map An initial array for the map.
     * @param array<\Cake\ORM\EagerLoadable> $level An array of EagerLoadable instances.
     * @param bool $matching Whether it is an association loaded through `matching()`.
     */
    protected function _build_associations_map(array $map, array $level, bool $matching = false): array
    {
        foreach ($level as $assoc => $meta) {
            $can_be_joined = $meta->can_be_joined();
            $instance = $meta->instance();
            $associations = $meta->associations();
            $for_matching = $meta->for_matching();
            $map[] = ['alias' => $assoc, 'instance' => $instance, 'canBeJoined' => $can_be_joined, 'entityClass' => $instance->get_target()->get_entity_class(), 'nestKey' => $can_be_joined ? $assoc : $meta->alias_path(), 'matching' => $for_matching ?? $matching, 'targetProperty' => $meta->target_property()];
            if ($can_be_joined && $associations) {
                $map = $this->_build_associations_map($map, $associations, $matching);
            }
        }
        return $map;
    }
    /**
     * Registers a table alias, typically loaded as a join in a query, as belonging to
     * an association. This helps hydrators know what to do with the columns coming
     * from such joined table.
     *
     * @param string $alias The table alias as it appears in the query.
     * @param \Cake\ORM\Association $assoc The association object the alias represents;
     * will be normalized.
     * @param bool $asMatching Whether this join results should be treated as a
     * 'matching' association.
     * @param string|null $targetProperty The property name where the results of the join should be nested at.
     * If not passed, the default property for the association will be used.
     */
    public function add_to_joins_map(string $alias, Association $assoc, bool $as_matching = false, ?string $target_property = null): void
    {
        $this->_joins_map[$alias] = new Eager_Loadable($alias, ['aliasPath' => $alias, 'instance' => $assoc, 'canBeJoined' => true, 'forMatching' => $as_matching, 'targetProperty' => $target_property ?: $assoc->get_property()]);
    }
    /**
     * Helper function used to return the keys from the query records that will be used
     * to eagerly load associations.
     *
     * @param array<\Cake\ORM\EagerLoadable> $external The list of external associations to be loaded.
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query The query from which the results where generated.
     * @param array $results Results array.
     */
    protected function _collect_keys(array $external, Select_Query $query, array $results): array
    {
        $collect_keys = [];
        foreach ($external as $meta) {
            $instance = $meta->instance();
            if (!$instance->requires_keys($meta->get_config())) {
                continue;
            }
            $source = $instance->get_source();
            $keys = $instance->type() === Association::MANY_TO_ONE ? (array) $instance->get_foreign_key() : (array) $instance->get_binding_key();
            $alias = $source->get_alias();
            $pk_fields = [];
            /** @var string $key */
            foreach ($keys as $key) {
                $pk_fields[] = key($query->alias_field($key, $alias));
            }
            $collect_keys[$meta->alias_path()] = [$alias, $pk_fields, count($pk_fields) === 1];
        }
        if (!$collect_keys) {
            return [];
        }
        return $this->_group_keys($results, $collect_keys);
    }
    /**
     * Helper function used to iterate a statement and extract the columns
     * defined in $collectKeys.
     *
     * @param array $results Results array.
     * @param array<string, array> $collectKeys The keys to collect.
     */
    protected function _group_keys(array $results, array $collect_keys): array
    {
        $keys = [];
        foreach ($results as $result) {
            foreach ($collect_keys as $nest_key => $parts) {
                if ($parts[2] === true) {
                    // Missed joins will have null in the results.
                    if (!array_key_exists($parts[1][0], $result)) {
                        continue;
                    }
                    // Assign empty array to avoid not found association when optional.
                    if (!isset($result[$parts[1][0]])) {
                        if (!isset($keys[$nest_key][$parts[0]])) {
                            $keys[$nest_key][$parts[0]] = [];
                        }
                    } else {
                        $value = $result[$parts[1][0]];
                        $keys[$nest_key][$parts[0]][$value] = $value;
                    }
                    continue;
                }
                // Handle composite keys.
                $collected = [];
                foreach ($parts[1] as $key) {
                    $collected[] = $result[$key];
                }
                $keys[$nest_key][$parts[0]][implode(';', $collected)] = $collected;
            }
        }
        return $keys;
    }
    /**
     * Handles cloning eager loaders and eager loadables.
     */
    public function __clone()
    {
        if ($this->_matching) {
            $this->_matching = clone $this->_matching;
        }
    }
}