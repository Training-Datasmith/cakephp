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
 * @since         5.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging;

use function Cake\Core\Deprecation_Warning;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
/**
 * Paginated result set.
 *
 * @template TKey
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 * @implements \Cake\Datasource\Paging\PaginatedInterface<TKey, TValue>
 */
class Paginated_Result_Set implements IteratorAggregate, JsonSerializable, Paginated_Interface
{
    /**
     * Constructor
     *
     * @param \Traversable<TKey, TValue> $results Resultset instance.
     * @param array $params Paging params.
     */
    public function __construct(
        /**
         * Resultset instance.
         */
        protected Traversable $results,
        /**
         * Paging params.
         */
        protected array $params
    )
    {
    }
    /**
     * @inheritDoc
     */
    public function count(): int
    {
        return $this->params['count'];
    }
    /**
     * Get the paginated items as an array.
     *
     * This will exhaust the iterator `items`.
     *
     * @return array<array-key, TValue>
     */
    public function to_array(): array
    {
        return $this->jsonSerialize();
    }
    /**
     * Get paginated items.
     *
     * @return \Traversable<TKey, TValue> The paginated items result set.
     */
    public function items(): Traversable
    {
        return $this->results;
    }
    /**
     * Provide data which should be serialized to JSON.
     */
    public function jsonSerialize(): array
    {
        return iterator_to_array($this->items());
    }
    /**
     * @inheritDoc
     */
    public function total_count(): ?int
    {
        return $this->params['totalCount'];
    }
    /**
     * @inheritDoc
     */
    public function per_page(): int
    {
        return $this->params['perPage'];
    }
    /**
     * @inheritDoc
     */
    public function page_count(): ?int
    {
        return $this->params['pageCount'];
    }
    /**
     * @inheritDoc
     */
    public function current_page(): int
    {
        return $this->params['currentPage'];
    }
    /**
     * @inheritDoc
     */
    public function has_prev_page(): bool
    {
        return $this->params['hasPrevPage'];
    }
    /**
     * @inheritDoc
     */
    public function has_next_page(): bool
    {
        return $this->params['hasNextPage'];
    }
    /**
     * @inheritDoc
     */
    public function paging_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
    /**
     * @inheritDoc
     */
    public function paging_params(): array
    {
        return $this->params;
    }
    /**
     * @inheritDoc
     */
    public function getIterator(): Traversable
    {
        return $this->results;
    }
    /**
     * Proxies method calls to internal result set instance.
     *
     * @param string $name Method name
     * @param array $arguments Arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        deprecation_warning('5.1.0', sprintf('Calling `%s` methods, such as `%s()`, on PaginatedResultSet is deprecated. ' . 'You must call `items()` first (for example, `items()->%s()`).', $this->results::class, $name, $name));
        return $this->results->{$name}(...$arguments);
    }
}