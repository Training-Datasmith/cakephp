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
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Base_Command;
use Cake\Console\Console_Io;
use Cake\Log\Log_Trait;
use Cake\ORM\Locator\Locator_Aware_Trait;
/**
 * Base class for commands using the full stack
 * CakePHP Framework.
 *
 * Includes traits that integrate logging
 * and ORM models to console commands.
 */
class Command extends Base_Command
{
    use Locator_Aware_Trait;
    use Log_Trait;
    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null|void The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io)
    {
    }
}