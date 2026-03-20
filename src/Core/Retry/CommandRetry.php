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
 * @since         3.6.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Core\Retry;

use Closure;
use Exception;
/**
 * Allows any action to be retried in case of an exception.
 *
 * This class can be parametrized with a strategy, which will be followed
 * to determine whether the action should be retried.
 */
class Command_Retry
{
    protected int $num_retries;
    /**
     * Creates the CommandRetry object with the given strategy and retry count
     *
     * @param \Cake\Core\Retry\RetryStrategyInterface $strategy The strategy to follow should the action fail
     * @param int $maxRetries The maximum number of retry attempts allowed
     */
    public function __construct(
        /**
         * The strategy to follow should the executed action fail.
         */
        protected Retry_Strategy_Interface $strategy,
        protected int $max_retries = 1
    )
    {
    }
    /**
     * The number of retries to perform in case of failure
     *
     * @param \Closure $action Callback to run for each attempt
     * @return mixed The return value of the passed action callable
     * @throws \Exception Throws exception from last failure
     */
    public function run(Closure $action): mixed
    {
        $this->num_retries = 0;
        while (true) {
            try {
                return $action();
            } catch (Exception $e) {
                if ($this->num_retries < $this->max_retries && $this->strategy->should_retry($e, $this->num_retries)) {
                    $this->num_retries++;
                    continue;
                }
                throw $e;
            }
        }
    }
    /**
     * Returns the last number of retry attempts.
     */
    public function get_retries(): int
    {
        return $this->num_retries;
    }
}