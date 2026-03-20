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
namespace Cake\ORM\Association;

use Cake\Datasource\Entity_Interface;
use Cake\ORM\Association;
/**
 * Helper class for cascading deletes in associations.
 *
 * @internal
 */
class Dependent_Delete_Helper
{
    /**
     * Cascade a delete to remove dependent records.
     *
     * This method does nothing if the association is not dependent.
     *
     * @param \Cake\ORM\Association $association The association callbacks are being cascaded on.
     * @param \Cake\Datasource\EntityInterface $entity The entity that started the cascaded delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success.
     */
    public function cascade_delete(Association $association, Entity_Interface $entity, array $options = []): bool
    {
        if (!$association->get_dependent()) {
            return true;
        }
        $table = $association->get_target();
        /** @var callable $callable */
        $callable = $association->alias_field(...);
        $foreign_key = array_map($callable, (array) $association->get_foreign_key());
        $binding_key = (array) $association->get_binding_key();
        $binding_value = $entity->extract($binding_key);
        if (in_array(null, $binding_value, true)) {
            return true;
        }
        $conditions = array_combine($foreign_key, $binding_value);
        if ($association->get_cascade_callbacks()) {
            foreach ($association->find()->where($conditions)->all()->to_list() as $related) {
                /** @phpstan-ignore argument.type (cascade callbacks always have hydration enabled) */
                $success = $table->delete($related, $options);
                if (!$success) {
                    return false;
                }
            }
            return true;
        }
        $association->delete_all($conditions);
        return true;
    }
}