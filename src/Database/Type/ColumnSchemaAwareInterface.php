<?php

declare (strict_types=1);
namespace Cake\Database\Type;

use Cake\Database\Driver;
use Cake\Database\Schema\Table_Schema_Interface;
interface Column_Schema_Aware_Interface
{
    /**
     * Generate the SQL fragment for a single column in a table.
     *
     * @param \Cake\Database\Schema\TableSchemaInterface $schema The table schema instance the column is in.
     * @param string $column The name of the column.
     * @param \Cake\Database\Driver $driver The driver instance being used.
     * @return string|null An SQL fragment, or `null` in case the column isn't processed by this type.
     */
    public function get_column_sql(Table_Schema_Interface $schema, string $column, Driver $driver): ?string;
    /**
     * Convert a SQL column definition to an abstract type definition.
     *
     * @param array $definition The column definition.
     * @param \Cake\Database\Driver $driver The driver instance being used.
     * @return array<string, mixed>|null Array of column information, or `null` in case the column isn't processed by this type.
     */
    public function convert_column_definition(array $definition, Driver $driver): ?array;
}