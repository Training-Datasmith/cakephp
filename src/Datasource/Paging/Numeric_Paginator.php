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
 * @since         3.5.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging;

use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Instance_Config_Trait;
use function Cake\Core\Trigger_Warning;
use Cake\Datasource\Paging\Exception\Page_Out_Of_Bounds_Exception;
use Cake\Datasource\Query_Interface;
use Cake\Datasource\Repository_Interface;
use Cake\Datasource\Result_Set_Interface;
/**
 * This class is used to handle automatic model data pagination.
 */
class Numeric_Paginator implements Paginator_Interface
{
    use Instance_Config_Trait;
    /**
     * Default pagination settings.
     *
     * When calling paginate() these settings will be merged with the configuration
     * you provide.
     *
     * - `maxLimit` - The maximum limit users can choose to view. Defaults to 100
     * - `limit` - The initial number of items per page. Defaults to 20.
     * - `page` - The starting page, defaults to 1.
     * - `allowedParameters` - A list of parameters users are allowed to set using request
     *   parameters. Modifying this list will allow users to have more influence
     *   over pagination, be careful with what you permit.
     * - `sortableFields` - Controls which fields can be used for sorting. Accepts multiple formats:
     *   - Simple array: A list of field names that can be sorted. By default all table
     *     columns can be used. Use this to restrict sorting to specific fields. An empty
     *     array will disable sorting altogether.
     *   - Map with SortField objects: A map of sort keys to their corresponding database fields.
     *     Allows creating friendly sort keys that map to one or more actual fields. Supports
     *     simple mapping, multi-column sorting, locked directions, and default directions.
     *     Can accept a callable that receives a SortableFieldsBuilder instance.
     *
     *   Examples:
     *   ```
     *   // Simple array (traditional)
     *   'sortableFields' => ['title', 'created', 'author_id']
     *
     *   // Map with SortField objects
     *   'sortableFields' => [
     *       'name' => 'Users.name',
     *       'newest' => [
     *           SortField::desc('created'),
     *           SortField::asc('title'),
     *       ],
     *   ]
     *
     *   // Callable with builder
     *   'sortableFields' => function(SortableFieldsBuilder $builder) {
     *       return $builder
     *           ->add('name', SortField::asc('Users.name'))
     *           ->add('popularity', SortField::desc('score', locked: true), 'created');
     *   }
     *   ```
     * - `finder` - The table finder to use. Defaults to `all`.
     * - `scope` - If specified this scope will be used to get the paging options
     *   from the query params passed to paginate(). Scopes allow namespacing the
     *   paging options and allows paginating multiple models in the same action.
     *   Default `null`.
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['page' => 1, 'limit' => 20, 'maxLimit' => 100, 'allowedParameters' => ['limit', 'sort', 'page', 'direction'], 'sortableFields' => null, 'finder' => 'all', 'scope' => null];
    /**
     * Calculated paging params.
     */
    protected array $paging_params = ['limit' => null, 'maxLimit' => null, 'count' => null, 'totalCount' => null, 'perPage' => null, 'pageCount' => null, 'currentPage' => null, 'requestedPage' => null, 'start' => null, 'end' => null, 'hasPrevPage' => null, 'hasNextPage' => null, 'sort' => null, 'sortDefault' => null, 'direction' => null, 'directionDefault' => null, 'completeSort' => null, 'alias' => null, 'scope' => null];
    /**
     * Handles automatic pagination of model records.
     *
     * ### Configuring pagination
     *
     * When calling `paginate()` you can use the $settings parameter to pass in
     * pagination settings. These settings are used to build the queries made
     * and control other pagination settings.
     *
     * If your settings contain a key with the current table's alias. The data
     * inside that key will be used. Otherwise, the top level configuration will
     * be used.
     *
     * ```
     *  $settings = [
     *    'limit' => 20,
     *    'maxLimit' => 100
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * The above settings will be used to paginate any repository. You can configure
     * repository specific settings by keying the settings with the repository alias.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'limit' => 20,
     *      'maxLimit' => 100
     *    ],
     *    'Comments' => [ ... ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * This would allow you to have different pagination settings for
     * `Articles` and `Comments` repositories.
     *
     * ### Controlling sort fields
     *
     * By default CakePHP will automatically allow sorting on any column on the
     * repository object being paginated. Often times you will want to allow
     * sorting on either associated columns or calculated fields. In these cases
     * you will need to define an allowed list of all the columns you wish to allow
     * sorting on. You can define the allowed sort fields in the `$settings` parameter:
     *
     * ```
     * $settings = [
     *   'Articles' => [
     *     'finder' => 'custom',
     *     'sortableFields' => ['title', 'author_id', 'comment_count'],
     *   ]
     * ];
     * ```
     *
     * Passing an empty array as sortableFields disallows sorting altogether.
     *
     * ### Paginating with custom finders
     *
     * You can paginate with any find type defined on your table using the
     * `finder` option.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'finder' => 'popular'
     *    ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * Would paginate using the `find('popular')` method.
     *
     * You can also pass an already created instance of a query to this method:
     *
     * ```
     * $query = $this->Articles->find('popular')->matching('Tags', function ($q) {
     *   return $q->where(['name' => 'CakePHP'])
     * });
     * $results = $paginator->paginate($query);
     * ```
     *
     * ### Scoping Request parameters
     *
     * By using request parameter scopes you can paginate multiple queries in
     * the same controller action:
     *
     * ```
     * $articles = $paginator->paginate($articlesQuery, ['scope' => 'articles']);
     * $tags = $paginator->paginate($tagsQuery, ['scope' => 'tags']);
     * ```
     *
     * Each of the above queries will use different query string parameter sets
     * for pagination data. An example URL paginating both results would be:
     *
     * ```
     * /dashboard?articles[page]=1&tags[page]=2
     * ```
     *
     * @param mixed $target The repository or query
     *   to paginate.
     * @param array $params Request params
     * @param array $settings The settings/configuration used for pagination.
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     * @throws \Cake\Datasource\Paging\Exception\PageOutOfBoundsException
     */
    public function paginate(mixed $target, array $params = [], array $settings = []): Paginated_Interface
    {
        $query = null;
        if ($target instanceof Query_Interface) {
            $query = $target;
            $target = $query->get_repository();
            if ($target === null) {
                throw new Cake_Exception('No repository set for query.');
            }
        }
        assert($target instanceof Repository_Interface, 'Pagination target must be an instance of `' . Query_Interface::class . '` or `' . Repository_Interface::class . '`.');
        $data = $this->extract_data($target, $params, $settings);
        $query = $this->get_query($target, $query, $data);
        $count_query = clone $query;
        $items = $this->get_items($query, $data);
        $this->paging_params['count'] = count($items);
        $this->paging_params['totalCount'] = $this->get_count($count_query, $data);
        $paging_params = $this->build_params($data);
        if ($paging_params['requestedPage'] > $paging_params['currentPage']) {
            throw new Page_Out_Of_Bounds_Exception(['requestedPage' => $paging_params['requestedPage'], 'pagingParams' => $paging_params]);
        }
        return $this->build_paginated($items, $paging_params);
    }
    /**
     * Build paginated result set.
     *
     * @param \Cake\Datasource\ResultSetInterface<int, mixed> $items
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     */
    protected function build_paginated(Result_Set_Interface $items, array $paging_params): Paginated_Interface
    {
        return new Paginated_Result_Set($items, $paging_params);
    }
    /**
     * Get query for fetching paginated results.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository instance.
     * @param \Cake\Datasource\QueryInterface|null $query Query Instance.
     * @param array<string, mixed> $data Pagination data.
     */
    protected function get_query(Repository_Interface $object, ?Query_Interface $query, array $data): Query_Interface
    {
        $options = $data['options'];
        $query_options = array_intersect_key($options, ['order' => null, 'page' => null, 'limit' => null]);
        $args = [];
        $type = $options['finder'] ?? null;
        if (is_array($type)) {
            $args = (array) current($type);
            $type = key($type);
        }
        if ($query === null) {
            $query = $object->find($type ?? 'all', ...$args);
        } elseif ($type !== null) {
            $query->find($type, ...$args);
        }
        $query->apply_options($query_options);
        return $query;
    }
    /**
     * Get paginated items.
     *
     * @param \Cake\Datasource\QueryInterface $query Query to fetch items.
     * @param array $data Paging data.
     * @return \Cake\Datasource\ResultSetInterface<int, mixed>
     */
    protected function get_items(Query_Interface $query, array $data): Result_Set_Interface
    {
        return $query->all();
    }
    /**
     * Get total count of records.
     *
     * @param \Cake\Datasource\QueryInterface $query Query instance.
     * @param array $data Pagination data.
     */
    protected function get_count(Query_Interface $query, array $data): ?int
    {
        return $query->count();
    }
    /**
     * Extract pagination data needed
     *
     * @param \Cake\Datasource\RepositoryInterface $object The repository object.
     * @param array<string, mixed> $params Request params
     * @param array<string, mixed> $settings The settings/configuration used for pagination.
     */
    protected function extract_data(Repository_Interface $object, array $params, array $settings): array
    {
        $alias = $object->get_alias();
        $defaults = $this->get_defaults($alias, $settings);
        $valid_settings = array_keys($this->_default_config);
        $valid_settings[] = 'order';
        $extra_settings = array_diff_key($defaults, array_flip($valid_settings));
        if ($extra_settings) {
            trigger_warning('Passing query options as paginator settings is no longer supported.' . ' Use a custom finder through the `finder` config or pass a SelectQuery instance to paginate().' . ' Extra keys found are: `' . implode('`, `', array_keys($extra_settings)) . '`.');
        }
        $options = $this->merge_options($params, $defaults);
        $options = $this->validate_sort($object, $options);
        $options = $this->check_limit($options);
        $options['page'] = max((int) $options['page'], 1);
        return compact('defaults', 'options', 'alias');
    }
    /**
     * Build pagination params.
     *
     * @param array<string, mixed> $data Paginator data containing keys 'options',
     *  'defaults', 'alias'.
     * @return array<string, mixed> Paging params.
     */
    protected function build_params(array $data): array
    {
        $this->paging_params = ['perPage' => $data['options']['limit'], 'requestedPage' => $data['options']['page'], 'alias' => $data['alias'], 'scope' => $data['options']['scope'], 'maxLimit' => $data['options']['maxLimit']] + $this->paging_params;
        $this->add_page_count_params($data);
        $this->add_start_end_params($data);
        $this->add_prev_next_params($data);
        $this->add_sorting_params($data);
        $this->paging_params['limit'] = (int) $data['defaults']['limit'] !== (int) $data['options']['limit'] ? $data['options']['limit'] : null;
        // Add sortableFields configuration for view helpers
        if (isset($data['options']['sortableFields'])) {
            $sortable_fields = $data['options']['sortableFields'];
            if ($sortable_fields instanceof Sortable_Fields_Builder) {
                $this->paging_params['sortableFields'] = $sortable_fields->to_array();
            }
        }
        return $this->paging_params;
    }
    /**
     * Add "currentPage" and "pageCount" params.
     *
     * @param array $data Paginator data.
     */
    protected function add_page_count_params(array $data): void
    {
        $page = $data['options']['page'];
        $page_count = null;
        if ($this->paging_params['totalCount'] !== null) {
            $page_count = max((int) ceil($this->paging_params['totalCount'] / $this->paging_params['perPage']), 1);
            $page = min($page, $page_count);
        } elseif ($this->paging_params['count'] === 0 && $this->paging_params['requestedPage'] > 1) {
            $page = 1;
        }
        $this->paging_params['currentPage'] = $page;
        $this->paging_params['pageCount'] = $page_count;
    }
    /**
     * Add "start" and "end" params.
     *
     * @param array $data Paginator data.
     */
    protected function add_start_end_params(array $data): void
    {
        $start = 0;
        $end = 0;
        if ($this->paging_params['count'] > 0) {
            $start = ($this->paging_params['currentPage'] - 1) * $this->paging_params['perPage'] + 1;
            $end = $start + $this->paging_params['count'] - 1;
        }
        $this->paging_params['start'] = $start;
        $this->paging_params['end'] = $end;
    }
    /**
     * Add "prevPage" and "nextPage" params.
     *
     * @param array $data Paging data.
     */
    protected function add_prev_next_params(array $data): void
    {
        $this->paging_params['hasPrevPage'] = $this->paging_params['currentPage'] > 1;
        if ($this->paging_params['totalCount'] === null) {
            $this->paging_params['hasNextPage'] = true;
        } else {
            $this->paging_params['hasNextPage'] = $this->paging_params['totalCount'] > $this->paging_params['currentPage'] * $this->paging_params['perPage'];
        }
    }
    /**
     * Add sorting / ordering params.
     *
     * @param array $data Paging data.
     */
    protected function add_sorting_params(array $data): void
    {
        $defaults = $data['defaults'];
        $order = (array) $data['options']['order'];
        $sort_default = false;
        $direction_default = false;
        if (!empty($defaults['order']) && count($defaults['order']) >= 1) {
            $sort_default = key($defaults['order']);
            $direction_default = current($defaults['order']);
        }
        if (isset($data['options']['sortDirection'])) {
            $direction = $data['options']['sortDirection'];
        } else {
            $direction = isset($data['options']['sort']) && count($order) ? current($order) : null;
        }
        $this->paging_params = ['sort' => $data['options']['sort'], 'direction' => $direction, 'sortDefault' => $sort_default, 'directionDefault' => $direction_default, 'completeSort' => $order] + $this->paging_params;
    }
    /**
     * Merges the various options that Paginator uses.
     * Pulls settings together from the following places:
     *
     * - General pagination settings
     * - Model specific settings.
     * - Request parameters
     *
     * The result of this method is the aggregate of all the option sets
     * combined together. You can change config value `allowedParameters` to modify
     * which options/values can be set using request parameters.
     *
     * @param array<string, mixed> $params Request params.
     * @param array $settings The settings to merge with the request data.
     * @return array<string, mixed> Array of merged options.
     */
    protected function merge_options(array $params, array $settings): array
    {
        if (!empty($settings['scope'])) {
            $scope = $settings['scope'];
            $params = (array) ($params[$scope] ?? []);
        }
        $params = array_intersect_key($params, array_flip($this->get_config('allowedParameters')));
        return array_merge($settings, $params);
    }
    /**
     * Get the settings for a $model. If there are no settings for a specific
     * repository, the general settings will be used.
     *
     * @param string $alias Model name to get settings for.
     * @param array<string, mixed> $settings The settings which is used for combining.
     * @return array<string, mixed> An array of pagination settings for a model,
     *   or the general settings.
     */
    protected function get_defaults(string $alias, array $settings): array
    {
        if (isset($settings[$alias])) {
            $settings = $settings[$alias];
        }
        $defaults = $this->get_config();
        $max_limit = $settings['maxLimit'] ?? $defaults['maxLimit'];
        $limit = $settings['limit'] ?? $defaults['limit'];
        if ($limit > $max_limit) {
            $limit = $max_limit;
        }
        $settings['maxLimit'] = $max_limit;
        $settings['limit'] = $limit;
        return $settings + $defaults;
    }
    /**
    * Validate that the desired sorting can be performed on the $object.
    *
    * Only fields or virtualFields can be sorted on. The direction param will
    * also be sanitized. Lastly sort + direction keys will be converted into
    * the model friendly order key.
    *
    /**
    * You can use the allowedParameters option to control which columns/fields are
    * available for sorting via URL parameters. This helps prevent users from ordering large
    * result sets on un-indexed values.
    *
    * If you need to sort on associated columns or synthetic properties you
    * will need to use the `sortableFields` option.
    *
    * Any columns listed in the allowed sort fields will be implicitly trusted.
    * You can use this to sort on synthetic columns, or columns added in custom
    * find operations that may not exist in the schema.
    *
    * The default order options provided to paginate() will be merged with the user's
    * requested sorting field/direction.
    *
    * @param \Cake\Datasource\RepositoryInterface $object Repository object.
    * @param array<string, mixed> $options The pagination options being used for this request.
    * @return array<string, mixed> An array of options with sort + direction removed and
    *   replaced with order if possible.
    */
    protected function validate_sort(Repository_Interface $object, array $options): array
    {
        // Check if we have sortableFields configured
        $sortable_fields = $options['sortableFields'] ?? null;
        $builder = $sortable_fields instanceof Sortable_Fields_Builder ? $sortable_fields : Sortable_Fields_Builder::create($sortable_fields);
        // Store the converted builder for later use in paging params
        if ($builder !== null) {
            $options['sortableFields'] = $builder;
        }
        $sort_allowed = $builder !== null;
        if (isset($options['sort'])) {
            // Parse sort and direction parameters
            $sort_params = $this->parse_sort_params($options);
            // Update options with parsed sort key (handles combined format)
            $options['sort'] = $sort_params['sortKey'];
            if ($builder !== null) {
                // Use builder to resolve sort key
                $order = $builder->resolve($sort_params['sortKey'], $sort_params['direction'], $sort_params['directionSpecified']);
                if ($order === null) {
                    // Invalid sort key, clear sort
                    $options['order'] = [];
                    $options['sort'] = null;
                    unset($options['direction']);
                    return $options;
                }
                // Merge with existing order - existing order comes AFTER our resolved order
                $existing_order = isset($options['order']) && is_array($options['order']) ? $options['order'] : [];
                // Only keep fields from existing order that aren't already in our resolved order
                foreach ($existing_order as $field => $dir) {
                    if (!isset($order[$field])) {
                        $order[$field] = $dir;
                    }
                }
                $options['order'] = $order;
                $options['sortDirection'] = $sort_params['direction'];
            } else {
                // No sortableFields configured - allow any field (default behavior)
                $order = isset($options['order']) && is_array($options['order']) ? $options['order'] : [];
                if ($order && $sort_params['sortKey'] && !str_contains($sort_params['sortKey'], '.')) {
                    $order = $this->_remove_aliases($order, $object->get_alias());
                }
                $options['order'] = [$sort_params['sortKey'] => $sort_params['direction']] + $order;
            }
        } else {
            $options['sort'] = null;
        }
        unset($options['direction']);
        if (empty($options['order'])) {
            $options['order'] = [];
        }
        if (!is_array($options['order'])) {
            return $options;
        }
        if ($options['sort'] === null && count($options['order']) >= 1 && !is_numeric(key($options['order']))) {
            $options['sort'] = key($options['order']);
        }
        $options['order'] = $this->_prefix($object, $options['order'], $sort_allowed);
        return $options;
    }
    /**
     * Remove alias if needed.
     *
     * @param array<string, mixed> $fields Current fields
     * @param string $model Current model alias
     * @return array<string, mixed> $fields Unaliased fields where applicable
     */
    protected function _remove_aliases(array $fields, string $model): array
    {
        $result = [];
        foreach ($fields as $field => $sort) {
            if (is_int($field)) {
                throw new Cake_Exception(sprintf('The `order` config must be an associative array. Found invalid value with numeric key: `%s`', $sort));
            }
            if (!str_contains($field, '.')) {
                $result[$field] = $sort;
                continue;
            }
            [$alias, $current_field] = explode('.', $field);
            if ($alias === $model) {
                $result[$current_field] = $sort;
                continue;
            }
            $result[$field] = $sort;
        }
        return $result;
    }
    /**
     * Prefixes the field with the table alias if possible.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array $order Order array.
     * @param bool $allowed Whether the field was allowed.
     * @return array Final order array.
     */
    protected function _prefix(Repository_Interface $object, array $order, bool $allowed = false): array
    {
        $table_alias = $object->get_alias();
        $table_order = [];
        foreach ($order as $key => $value) {
            if (is_numeric($key)) {
                $table_order[] = $value;
                continue;
            }
            $field = $key;
            $alias = $table_alias;
            if (str_contains($key, '.')) {
                [$alias, $field] = explode('.', $key);
            }
            $correct_alias = $table_alias === $alias;
            if ($correct_alias && $allowed) {
                // Disambiguate fields in schema. As id is quite common.
                if ($object->has_field($field)) {
                    $field = $alias . '.' . $field;
                }
                $table_order[$field] = $value;
            } elseif ($correct_alias && $object->has_field($field)) {
                $table_order[$table_alias . '.' . $field] = $value;
            } elseif (!$correct_alias && $allowed) {
                $table_order[$alias . '.' . $field] = $value;
            }
        }
        return $table_order;
    }
    /**
     * Parse sort parameters from options.
     *
     * Extracts and normalizes sort key and direction from pagination options.
     * Supports both traditional format (?sort=field&direction=asc) and
     * combined format (?sort=field-asc).
     *
     * @param array<string, mixed> $options The options array
     * @return array{sortKey: string, direction: string, directionSpecified: bool}
     */
    protected function parse_sort_params(array $options): array
    {
        $sort_key = $options['sort'];
        $direction = isset($options['direction']) ? strtolower((string) $options['direction']) : Sort_Field::ASC;
        $direction_specified = isset($options['direction']);
        // Check for combined sort-direction format (e.g., 'title-asc' or 'title-desc')
        if (preg_match('/^(.+)-(asc|desc)$/i', (string) $sort_key, $matches)) {
            $sort_key = $matches[1];
            $direction = strtolower($matches[2]);
            $direction_specified = true;
        }
        // Validate direction
        if (!in_array($direction, [Sort_Field::ASC, Sort_Field::DESC], true)) {
            $direction = Sort_Field::ASC;
        }
        return ['sortKey' => $sort_key, 'direction' => $direction, 'directionSpecified' => $direction_specified];
    }
    /**
     * Check the limit parameter and ensure it's within the maxLimit bounds.
     *
     * @param array<string, mixed> $options An array of options with a limit key to be checked.
     * @return array<string, mixed> An array of options for pagination.
     */
    protected function check_limit(array $options): array
    {
        $options['limit'] = (int) $options['limit'];
        if ($options['limit'] < 1) {
            $options['limit'] = 1;
        }
        $options['limit'] = max(min($options['limit'], $options['maxLimit']), 1);
        return $options;
    }
}