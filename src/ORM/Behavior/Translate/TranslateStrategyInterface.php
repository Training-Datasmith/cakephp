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
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Behavior\Translate;

use ArrayObject;
use Cake\Collection\Collection_Interface;
use Cake\Datasource\Entity_Interface;
use Cake\Datasource\Result_Set_Interface;
use Cake\Event\Event_Interface;
use Cake\ORM\Property_Marshal_Interface;
use Cake\ORM\Query\Select_Query;
use Cake\ORM\Table;
/**
 * This interface describes the methods for translate behavior strategies.
 */
interface Translate_Strategy_Interface extends Property_Marshal_Interface
{
    /**
     * Return translation table instance.
     */
    public function get_translation_table(): Table;
    /**
     * Sets the locale to be used.
     *
     * When fetching records, the content for the locale set via this method,
     * and likewise when saving data, it will save the data in that locale.
     *
     * Note that in case an entity has a `_locale` property set, that locale
     * will win over the locale set via this method (and over the globally
     * configured one for that matter)!
     *
     * @param string|null $locale The locale to use for fetching and saving
     *   records. Pass `null` in order to unset the current locale, and to make
     *   the behavior falls back to using the globally configured locale.
     * @return $this
     */
    public function set_locale(?string $locale);
    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will
     * return the currently configured global locale.
     */
    public function get_locale(): string;
    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, field with
     * an alias of a corresponding association is returned. Table-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     */
    public function translation_field(string $field): string;
    /**
     * Modifies the results from a table find in order to merge full translation records
     * into each entity under the `_translations` key
     *
     * @param \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface|array> $results Results to modify.
     * @return \Cake\Collection\CollectionInterface<mixed, mixed>
     */
    public function group_translations(Result_Set_Interface $results): Collection_Interface;
    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main table records.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeFind event that was fired.
     * @param \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface|array> $query Query
     * @param \ArrayObject<string, mixed> $options The options for the query
     */
    public function before_find(Event_Interface $event, Select_Query $query, ArrayObject $options): void;
    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject<string, mixed> $options the options passed to the save method
     */
    public function before_save(Event_Interface $event, Entity_Interface $entity, ArrayObject $options): void;
    /**
     * Unsets the temporary `_i18n` property after the entity has been saved
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     */
    public function after_save(Event_Interface $event, Entity_Interface $entity): void;
}