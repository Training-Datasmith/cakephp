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
 * @copyright     Copyright (c) Cake Software Foundation, Inc.
 *                (https://github.com/cakephp/migrations/tree/master/LICENSE.txt)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Schema;

use Cake\Database\Type_Factory;
use RuntimeException;
/**
 * Schema metadata for a single column
 *
 * Used by `TableSchema` when reflecting schema or creating tables.
 */
class Column
{
    /**
     * Constructor.
     *
     * @param string $name Name of the column
     * @param string $type Type of the column
     * @param bool $null Whether the column allows null values
     * @param mixed $default Default value for the column
     * @param int|null $length Length of the column
     * @param bool $identity Whether the column is an identity column
     * @param string|null $generated Postgres identity option (always|default)
     * @param int|null $precision Precision for decimal or float columns
     * @param int|null $increment Increment for identity columns
     * @param string|null $after Name of the column to add this column after
     * @param string|null $onUpdate MySQL 'ON UPDATE' function
     * @param string|null $comment Comment for the column
     * @param bool|null $unsigned Whether the column is unsigned
     * @param string|null $collate Collation for the column
     * @param int|null $srid SRID for geometry fields
     * @param string|null $baseType The basic schema type if the column type is a complex/custom type.
     * @param bool|null $fixed Whether the column is fixed-length (BINARY vs VARBINARY)
     */
    public function __construct(protected string $name, protected string $type, protected ?bool $null = null, protected mixed $default = null, protected ?int $length = null, protected bool $identity = false, protected ?string $generated = null, protected ?int $precision = null, protected ?int $increment = null, protected ?string $after = null, protected ?string $on_update = null, protected ?string $comment = null, protected ?bool $unsigned = null, protected ?string $collate = null, protected ?int $srid = null, protected ?string $base_type = null, protected ?bool $fixed = null)
    {
    }
    /**
     * Sets the column name.
     *
     * @param string $name Name
     * @return $this
     */
    public function set_name(string $name): static
    {
        $this->name = $name;
        return $this;
    }
    /**
     * Gets the column name.
     */
    public function get_name(): ?string
    {
        return $this->name;
    }
    /**
     * Get the base type if defined. Will fallback to `type` if not set.
     *
     * Used to get the base type of a column when the column type is a complex/custom type.
     */
    public function get_base_type(): ?string
    {
        if (isset($this->base_type)) {
            return $this->base_type;
        }
        $type = $this->type;
        if (Type_Factory::get_mapped($type)) {
            $type = Type_Factory::build($type)->get_base_type();
        }
        return $this->base_type = $type;
    }
    /**
     * Sets the base type of the column.
     *
     * Used to set the base type of a column when the column type is a complex/custom type.
     *
     * @param string|null $baseType Base type
     * @return $this
     */
    public function set_base_type(?string $base_type): static
    {
        $this->base_type = $base_type;
        return $this;
    }
    /**
     * Sets the column type.
     *
     * Type names are not validated, as drivers and dialects may implement
     * platform specific types that are not known by cakephp.
     *
     * Drivers are expected to handle unknown types gracefully.
     *
     * @param string $type Column type
     * @return $this
     */
    public function set_type(string $type): static
    {
        $this->type = $type;
        return $this;
    }
    /**
     * Gets the column type.
     */
    public function get_type(): string
    {
        return $this->type;
    }
    /**
     * Sets the column length.
     *
     * @param int|null $length Length
     * @return $this
     */
    public function set_length(?int $length): static
    {
        $this->length = $length;
        return $this;
    }
    /**
     * Gets the column length.
     */
    public function get_length(): ?int
    {
        return $this->length;
    }
    /**
     * Sets whether the column allows nulls.
     *
     * @param bool $null Null
     * @return $this
     */
    public function set_null(bool $null): static
    {
        $this->null = $null;
        return $this;
    }
    /**
     * Gets whether the column allows nulls.
     */
    public function get_null(): ?bool
    {
        return $this->null;
    }
    /**
     * Does the column allow nulls?
     */
    public function is_null(): bool
    {
        return $this->get_null() === true;
    }
    /**
     * Sets the default column value.
     *
     * @param mixed $default Default
     * @return $this
     */
    public function set_default(mixed $default): static
    {
        $this->default = $default;
        return $this;
    }
    /**
     * Gets the default column value.
     */
    public function get_default(): mixed
    {
        return $this->default;
    }
    /**
     * Sets generated option for identity columns. Ignored otherwise.
     *
     * @param string|null $generated Generated option
     * @return $this
     */
    public function set_generated(?string $generated): static
    {
        $this->generated = $generated;
        return $this;
    }
    /**
     * Gets generated option for identity columns. Null otherwise
     */
    public function get_generated(): ?string
    {
        return $this->generated;
    }
    /**
     * Sets whether the column is an identity column.
     *
     * @param bool $identity Identity
     * @return $this
     */
    public function set_identity(bool $identity): static
    {
        $this->identity = $identity;
        return $this;
    }
    /**
     * Gets whether the column is an identity column.
     */
    public function get_identity(): bool
    {
        return $this->identity;
    }
    /**
     * Is the column an identity column?
     */
    public function is_identity(): bool
    {
        return $this->get_identity();
    }
    /**
     * Sets the name of the column to add this column after.
     *
     * @param string $after After
     * @return $this
     */
    public function set_after(string $after): static
    {
        $this->after = $after;
        return $this;
    }
    /**
     * Returns the name of the column to add this column after.
     *
     * Used by MySQL and MariaDB in ALTER TABLE statements.
     */
    public function get_after(): ?string
    {
        return $this->after;
    }
    /**
     * Sets the 'ON UPDATE' mysql column function.
     *
     * Used by MySQL and MariaDB in ALTER TABLE statements.
     *
     * @param string $update On Update function
     * @return $this
     */
    public function set_on_update(string $update): static
    {
        $this->on_update = $update;
        return $this;
    }
    /**
     * Returns the value of the ON UPDATE column function.
     */
    public function get_on_update(): ?string
    {
        return $this->on_update;
    }
    /**
     * Sets the number precision for decimal or float column.
     *
     * For example `DECIMAL(5,2)`, 5 is the length and 2 is the precision,
     * and the column could store value from -999.99 to 999.99.
     *
     * @param int|null $precision Number precision
     * @return $this
     */
    public function set_precision(?int $precision): static
    {
        $this->precision = $precision;
        return $this;
    }
    /**
     * Gets the number precision for decimal or float column.
     *
     * For example `DECIMAL(5,2)`, 5 is the length and 2 is the precision,
     * and the column could store value from -999.99 to 999.99.
     */
    public function get_precision(): ?int
    {
        return $this->precision;
    }
    /**
     * Sets the column identity increment.
     *
     * @param int $increment Number increment
     * @return $this
     */
    public function set_increment(int $increment): static
    {
        $this->increment = $increment;
        return $this;
    }
    /**
     * Gets the column identity increment.
     */
    public function get_increment(): ?int
    {
        return $this->increment;
    }
    /**
     * Sets the column comment.
     *
     * @param string|null $comment Comment
     * @return $this
     */
    public function set_comment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }
    /**
     * Gets the column comment.
     *
     * @return string
     */
    public function get_comment(): ?string
    {
        return $this->comment;
    }
    /**
     * Sets whether field should be unsigned.
     *
     * @param bool $unsigned Signed
     * @return $this
     */
    public function set_unsigned(bool $unsigned): static
    {
        $this->unsigned = $unsigned;
        return $this;
    }
    /**
     * Gets whether field should be unsigned.
     */
    public function get_unsigned(): ?bool
    {
        return $this->unsigned;
    }
    /**
     * Should the column be signed?
     */
    public function is_signed(): bool
    {
        return !$this->get_unsigned();
    }
    /**
     * Should the column be unsigned?
     */
    public function is_unsigned(): bool
    {
        return $this->get_unsigned() === true;
    }
    /**
     * Sets the column collation.
     *
     * @param string $collation Collation
     * @return $this
     */
    public function set_collate(string $collation): static
    {
        $this->collate = $collation;
        return $this;
    }
    /**
     * Gets the column collation.
     */
    public function get_collate(): ?string
    {
        return $this->collate;
    }
    /**
     * Sets the column SRID for geometry fields.
     *
     * @param int $srid SRID
     * @return $this
     */
    public function set_srid(int $srid): static
    {
        $this->srid = $srid;
        return $this;
    }
    /**
     * Gets the column SRID from geometry fields.
     */
    public function get_srid(): ?int
    {
        return $this->srid;
    }
    /**
     * Sets whether the column is fixed-length.
     *
     * Used for binary columns to distinguish between BINARY and VARBINARY.
     *
     * @param bool $fixed Fixed
     * @return $this
     */
    public function set_fixed(bool $fixed): static
    {
        $this->fixed = $fixed;
        return $this;
    }
    /**
     * Gets whether the column is fixed-length.
     */
    public function get_fixed(): ?bool
    {
        return $this->fixed;
    }
    /**
     * Is the column fixed-length?
     */
    public function is_fixed(): bool
    {
        return $this->get_fixed() === true;
    }
    /**
     * Gets all allowed options. Each option must have a corresponding `setFoo` method.
     */
    protected function get_valid_options(): array
    {
        return ['name', 'length', 'precision', 'default', 'null', 'identity', 'after', 'onUpdate', 'comment', 'unsigned', 'type', 'properties', 'collate', 'srid', 'increment', 'generated', 'fixed'];
    }
    /**
     * Utility method that maps an array of column attributes to this object's methods.
     *
     * @param array<string, mixed> $attributes Attributes
     * @throws \RuntimeException
     * @return $this
     */
    public function set_attributes(array $attributes): static
    {
        $valid_options = $this->get_valid_options();
        if (isset($attributes['identity']) && $attributes['identity'] && !isset($attributes['null'])) {
            $attributes['null'] = false;
        }
        foreach ($attributes as $attribute => $value) {
            if (!in_array($attribute, $valid_options, true)) {
                throw new RuntimeException(sprintf('"%s" is not a valid column option.', $attribute));
            }
            $method = 'set' . ucfirst($attribute);
            $this->{$method}($value);
        }
        return $this;
    }
    /**
     * Convert an index into an array that is compatible with the Column constructor.
     */
    public function to_array(): array
    {
        $type = $this->get_type();
        $length = $this->get_length();
        $precision = $this->get_precision();
        if ($precision !== null && $precision > 0) {
            if ($type === Table_Schema_Interface::TYPE_TIMESTAMP) {
                $type = 'timestampfractional';
            } elseif ($type === Table_Schema_Interface::TYPE_DATETIME) {
                $type = 'datetimefractional';
            }
        }
        return ['name' => $this->get_name(), 'baseType' => $this->get_base_type(), 'type' => $type, 'length' => $length, 'null' => $this->get_null(), 'default' => $this->get_default(), 'generated' => $this->get_generated(), 'unsigned' => $this->get_unsigned(), 'onUpdate' => $this->get_on_update(), 'collate' => $this->get_collate(), 'precision' => $precision, 'srid' => $this->get_srid(), 'comment' => $this->get_comment(), 'autoIncrement' => $this->get_identity(), 'identity' => $this->get_identity(), 'fixed' => $this->get_fixed()];
    }
}