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
 * @since         3.9.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource\Paging;

use Cake\Datasource\Query_Interface;
use Cake\Datasource\Result_Set_Interface;
/**
 * Simplified paginator which avoids potentially expensive queries
 * to get the total count of records.
 *
 * When using a simple paginator you will not be able to generate page numbers.
 * Instead use only the prev/next pagination controls.
 */
class Simple_Paginator extends Numeric_Paginator
{
    /**
     * Get paginated items.
     *
     * Get one additional record than the limit. This helps deduce if next page exists.
     *
     * @param \Cake\Datasource\QueryInterface $query Query to fetch items.
     * @param array $data Paging data.
     * @return \Cake\Datasource\ResultSetInterface<int, mixed>
     */
    protected function get_items(Query_Interface $query, array $data): Result_Set_Interface
    {
        return $query->limit($data['options']['limit'] + 1)->all();
    }
    /**
     * @inheritDoc
     */
    protected function build_params(array $data): array
    {
        $has_next_page = false;
        if ($this->paging_params['count'] > $data['options']['limit']) {
            $has_next_page = true;
            $this->paging_params['count'] -= 1;
        }
        parent::build_params($data);
        $this->paging_params['hasNextPage'] = $has_next_page;
        return $this->paging_params;
    }
    /**
     * Build paginated result set.
     *
     * Since the query fetches an extra record, drop the last record if records
     * fetched exceeds the limit/per page.
     *
     * @param \Cake\Datasource\ResultSetInterface<int, mixed> $items
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     */
    protected function build_paginated(Result_Set_Interface $items, array $paging_params): Paginated_Interface
    {
        if (count($items) > $this->paging_params['perPage']) {
            $items = $items->take($this->paging_params['perPage']);
        }
        return new Paginated_Result_Set($items, $paging_params);
    }
    /**
     * Simple pagination does not perform any count query, so this method returns `null`.
     *
     * @param \Cake\Datasource\QueryInterface $query Query instance.
     * @param array $data Pagination data.
     */
    protected function get_count(Query_Interface $query, array $data): ?int
    {
        return null;
    }
}