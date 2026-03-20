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
namespace Cake\Database\Type;

use Cake\Database\Driver;
use Cake\Database\Type_Interface;
use PDO;
/**
 * Base type class.
 */
abstract class Base_Type implements Type_Interface
{
    /**
     * Constructor
     *
     * @param string|null $_name The name identifying this type
     */
    public function __construct(
        /**
         * Identifier name for this type
         */
        protected ?string $_name = null
    )
    {
    }
    /**
     * @inheritDoc
     */
    public function get_name(): ?string
    {
        return $this->_name;
    }
    /**
     * @inheritDoc
     */
    public function get_base_type(): ?string
    {
        return $this->_name;
    }
    /**
     * @inheritDoc
     */
    public function to_statement(mixed $value, Driver $driver): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
    /**
     * @inheritDoc
     */
    public function new_id(): mixed
    {
        return null;
    }
}