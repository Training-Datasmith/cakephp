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
 * @since         3.4.3
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Exception;

use Cake\Core\Exception\Cake_Exception;
use Throwable;
/**
 * Class NestedTransactionRollbackException
 */
class Nested_Transaction_Rollback_Exception extends Cake_Exception
{
    /**
     * Constructor
     *
     * @param string|null $message If no message is given, a default message will be used.
     * @param int|null $code Status code, defaults to 500.
     * @param \Throwable|null $previous the previous exception.
     */
    public function __construct(?string $message = null, ?int $code = 500, ?Throwable $previous = null)
    {
        $message ??= 'Cannot commit transaction - rollback() has been already called in the nested transaction';
        parent::__construct($message, $code, $previous);
    }
}