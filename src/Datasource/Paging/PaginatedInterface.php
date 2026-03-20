<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         5.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging;

use Countable;
use Traversable;
/**
 * This interface describes the methods for pagination instance.
 *
 * @template TKey
 * @template-covariant TValue
 * @template-extends \Traversable<TKey, TValue>
 * @method array<mixed> toArray() Get the paginated items as an array
 */
interface Paginated_Interface extends Countable, Traversable
{
    /**
     * Get current page number.
     */
    public function current_page(): int;
    /**
     * Get items per page.
     */
    public function per_page(): int;
    /**
     * Get Total items counts.
     */
    public function total_count(): ?int;
    /**
     * Get total page count.
     */
    public function page_count(): ?int;
    /**
     * Get whether there's a previous page.
     */
    public function has_prev_page(): bool;
    /**
     * Get whether there's a next page.
     */
    public function has_next_page(): bool;
    /**
     * Get paginated items.
     *
     * @return iterable<TKey, TValue>
     */
    public function items(): iterable;
    /**
     * Get paging param.
     */
    public function paging_param(string $name): mixed;
    /**
     * Get all paging params.
     */
    public function paging_params(): array;
}