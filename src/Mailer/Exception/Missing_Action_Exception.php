<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @since         3.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer\Exception;

use Cake\Core\Exception\Cake_Exception;
/**
 * Missing Action exception - used when a mailer action cannot be found.
 */
class Missing_Action_Exception extends Cake_Exception
{
    /**
     * @inheritDoc
     */
    protected string $_message_template = 'Mail %s::%s() could not be found, or is not accessible.';
}