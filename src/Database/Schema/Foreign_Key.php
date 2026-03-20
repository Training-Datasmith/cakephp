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

use InvalidArgumentException;
/**
 * ForeignKey metadata object
 *
 * Models a database foreign key constraint
 */
class Foreign_Key extends Constraint
{
    public const CASCADE = 'cascade';
    public const RESTRICT = 'restrict';
    public const SET_NULL = 'setNull';
    public const NO_ACTION = 'noAction';
    public const SET_DEFAULT = 'setDefault';
    public const DEFERRED = 'DEFERRABLE INITIALLY DEFERRED';
    public const IMMEDIATE = 'DEFERRABLE INITIALLY IMMEDIATE';
    public const NOT_DEFERRED = 'NOT DEFERRABLE';
    /**
     * An allow list of valid actions
     *
     * @var array<string>
     */
    protected array $valid_actions = [self::CASCADE, self::RESTRICT, self::SET_NULL, self::NO_ACTION, self::SET_DEFAULT];
    /**
     * The action to take when the referenced row is deleted.
     */
    protected ?string $delete = null;
    /**
     * The action to take when the referenced row is updated.
     */
    protected ?string $update = null;
    protected ?string $deferrable = null;
    /**
     * Constructor
     *
     * @param string $name The name of the index.
     * @param array<string> $columns The columns to index.
     * @param ?string $referencedTable The columns to index.
     * @param array<string> $referencedColumns The columns in $referencedTable that this key references.
     * @param ?string $delete The action to take when the referenced row is deleted.
     * @param ?string $update The action to take when the referenced row is updated.
     */
    public function __construct(protected string $name, protected array $columns, protected ?string $referenced_table = null, protected array $referenced_columns = [], ?string $delete = null, ?string $update = null, ?string $deferrable = null)
    {
        $this->type = self::FOREIGN;
        $this->delete = $this->normalize_action($delete ?? self::NO_ACTION);
        $this->update = $this->normalize_action($update ?? self::NO_ACTION);
        if ($deferrable) {
            $this->deferrable = $this->normalize_deferrable($deferrable);
        }
    }
    /**
     * Sets the foreign key referenced table.
     *
     * @param string $table The table this KEY is pointing to
     * @return $this
     */
    public function set_referenced_table(string $table): static
    {
        $this->referenced_table = $table;
        return $this;
    }
    /**
     * Gets the foreign key referenced table.
     */
    public function get_referenced_table(): ?string
    {
        return $this->referenced_table;
    }
    /**
     * Sets the foreign key referenced columns.
     *
     * @param array<string>|string $referencedColumns Referenced columns
     * @return $this
     */
    public function set_referenced_columns(array|string $referenced_columns): static
    {
        $referenced_columns = is_string($referenced_columns) ? [$referenced_columns] : $referenced_columns;
        $this->referenced_columns = $referenced_columns;
        return $this;
    }
    /**
     * Gets the foreign key referenced columns.
     *
     * @return array<string>
     */
    public function get_referenced_columns(): array
    {
        return $this->referenced_columns;
    }
    /**
     * Converts the foreign key to an array that is compatible
     * with the constructor.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'columns' => $this->columns, 'referencedTable' => $this->referenced_table, 'referencedColumns' => $this->referenced_columns, 'delete' => $this->delete, 'update' => $this->update, 'deferrable' => $this->deferrable];
    }
    /**
     * Sets ON DELETE action for the foreign key.
     *
     * @param string $delete On Delete
     * @return $this
     */
    public function set_delete(string $delete): static
    {
        $this->delete = $this->normalize_action($delete);
        return $this;
    }
    /**
     * Gets ON DELETE action for the foreign key.
     */
    public function get_delete(): ?string
    {
        return $this->delete;
    }
    /**
     * Gets ON UPDATE action for the foreign key.
     */
    public function get_update(): ?string
    {
        return $this->update;
    }
    /**
     * Sets ON UPDATE action for the foreign key.
     *
     * @param string $update On Update
     * @return $this
     */
    public function set_update(string $update): static
    {
        $this->update = $this->normalize_action($update);
        return $this;
    }
    /**
     * From passed value checks if it's correct and fixes if needed
     *
     * @param string $action Action
     * @throws \InvalidArgumentException
     */
    protected function normalize_action(string $action): string
    {
        if (in_array($action, $this->valid_actions, true)) {
            return $action;
        }
        throw new InvalidArgumentException('Unknown action passed: ' . $action);
    }
    /**
     * Sets deferrable mode for the foreign key.
     *
     * @param string $deferrable Constraint
     * @return $this
     */
    public function set_deferrable(string $deferrable): static
    {
        $this->deferrable = $this->normalize_deferrable($deferrable);
        return $this;
    }
    /**
     * Gets deferrable mode for the foreign key.
     */
    public function get_deferrable(): ?string
    {
        return $this->deferrable;
    }
    /**
     * From passed value checks if it's correct and fixes if needed
     *
     * @param string $deferrable Deferrable
     * @throws \InvalidArgumentException
     */
    protected function normalize_deferrable(string $deferrable): string
    {
        $mapping = ['DEFERRED' => Foreign_Key::DEFERRED, 'IMMEDIATE' => Foreign_Key::IMMEDIATE, 'NOT DEFERRED' => Foreign_Key::NOT_DEFERRED, Foreign_Key::DEFERRED => Foreign_Key::DEFERRED, Foreign_Key::IMMEDIATE => Foreign_Key::IMMEDIATE, Foreign_Key::NOT_DEFERRED => Foreign_Key::NOT_DEFERRED];
        $normalized = strtoupper(str_replace('_', ' ', $deferrable));
        if (array_key_exists($normalized, $mapping)) {
            return $mapping[$normalized];
        }
        throw new InvalidArgumentException('Unknown deferrable passed: ' . $deferrable);
    }
}