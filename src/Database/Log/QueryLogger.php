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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Database\Log;

use Cake\Log\Engine\Base_Log;
use Cake\Log\Log;
use Stringable;
/**
 * This class is a bridge used to write LoggedQuery objects into a real log.
 * by default this class use the built-in CakePHP Log class to accomplish this
 *
 * @internal
 */
class Query_Logger extends Base_Log
{
    /**
     * Constructor.
     *
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->_default_config['scopes'] = ['queriesLog', 'cake.database.queries'];
        $this->_default_config['connection'] = '';
        parent::__construct($config);
    }
    /**
     * @inheritDoc
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $context += ['scope' => $this->scopes() ?: ['queriesLog', 'cake.database.queries'], 'connection' => $this->get_config('connection'), 'query' => null];
        if ($context['query'] instanceof Logged_Query) {
            $context = $context['query']->get_context() + $context;
            $message = 'connection={connection} role={role} duration={took} rows={numRows} ' . $message;
        }
        Log::write('debug', (string) $message, $context);
    }
}