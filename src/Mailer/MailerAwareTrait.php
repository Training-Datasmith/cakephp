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
 * @since         3.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer;

use Cake\Core\App;
use Cake\Mailer\Exception\Missing_Mailer_Exception;
/**
 * Provides functionality for loading mailer classes
 * onto properties of the host object.
 *
 * Example users of this trait are Cake\Controller\Controller and
 * Cake\Console\Command.
 */
trait Mailer_Aware_Trait
{
    /**
     * Returns a mailer instance.
     *
     * @param string $name Mailer's name.
     * @param array<string, mixed>|string|null $config Array of configs, or profile name string.
     * @throws \Cake\Mailer\Exception\MissingMailerException if undefined mailer class.
     */
    protected function get_mailer(string $name, array|string|null $config = null): Mailer
    {
        $class_name = App::class_name($name, 'Mailer', 'Mailer');
        if ($class_name === null) {
            throw new Missing_Mailer_Exception(compact('name'));
        }
        return new $class_name($config);
    }
}