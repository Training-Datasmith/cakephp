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
 * @since         4.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Retry;

use Cake\Core\Retry\Retry_Strategy_Interface;
use Exception;
use PDOException;
/**
 * Implements retry strategy based on db error codes and wait interval.
 *
 * @internal
 */
class Error_Code_Wait_Strategy implements Retry_Strategy_Interface
{
    /**
     * @param array<int> $errorCodes DB-specific error codes that allow retrying
     * @param int $retryInterval Seconds to wait before allowing next retry, 0 for no wait.
     */
    public function __construct(protected array $error_codes, protected int $retry_interval)
    {
    }
    /**
     * @inheritDoc
     */
    public function should_retry(Exception $exception, int $retry_count): bool
    {
        if ($exception instanceof PDOException && $exception->error_info && in_array($exception->error_info[1], $this->error_codes)) {
            if ($this->retry_interval > 0) {
                sleep($this->retry_interval);
            }
            return true;
        }
        return false;
    }
}