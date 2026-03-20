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
 * @since         3.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database;

use Cake\Database\Type\Batch_Casting_Interface;
use Cake\Database\Type\Optional_Convert_Interface;
/**
 * An invokable class to be used for processing each of the rows in a statement
 * result, so that the values are converted to the right PHP types.
 *
 * @internal
 */
class Field_Type_Converter
{
    /**
     * Maps type names to conversion settings.
     */
    protected array $conversions = [];
    /**
     * Builds the type map
     *
     * @param \Cake\Database\TypeMap $typeMap Contains the types to use for converting results
     * @param \Cake\Database\Driver $driver The driver to use for the type conversion
     */
    public function __construct(Type_Map $type_map, protected Driver $driver)
    {
        $types = Type_Factory::build_all();
        foreach ($type_map->to_array() as $field => $type_name) {
            $type = $types[$type_name] ?? null;
            if (!$type) {
                continue;
            }
            if ($type instanceof Optional_Convert_Interface && !$type->requires_to_php_cast()) {
                continue;
            }
            $this->conversions[$type_name] ??= ['type' => $type, 'hasBatch' => $type instanceof Batch_Casting_Interface, 'fields' => []];
            $this->conversions[$type_name]['fields'][] = $field;
        }
    }
    /**
     * Converts each of the fields in the array that are present in the type map
     * using the corresponding Type class.
     *
     * @param mixed $row The array with the fields to be casted
     */
    public function __invoke(mixed $row): mixed
    {
        if (!is_array($row)) {
            return $row;
        }
        foreach ($this->conversions as $conversion) {
            /** @var \Cake\Database\TypeInterface $type */
            $type = $conversion['type'];
            if ($conversion['hasBatch']) {
                /** @var \Cake\Database\Type\BatchCastingInterface $type */
                $row = $type->many_to_php($row, $conversion['fields'], $this->driver);
                continue;
            }
            foreach ($conversion['fields'] as $field) {
                $row[$field] = $type->to_php($row[$field], $this->driver);
            }
        }
        return $row;
    }
}