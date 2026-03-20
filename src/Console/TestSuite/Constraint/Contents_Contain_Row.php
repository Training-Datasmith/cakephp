<?php

declare (strict_types=1);
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @since         3.7.0
 * @license       https://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Console\Test_Suite\Constraint;

use Sebastian_Bergmann\Exporter\Exporter;
/**
 * ContentsContainRow
 *
 * @internal
 */
class Contents_Contain_Row extends Contents_Reg_Exp
{
    /**
     * Checks if contents contain expected
     *
     * @param mixed $other Row
     */
    public function matches(mixed $other): bool
    {
        $row = array_map(fn($cell) => preg_quote((string) $cell, '/'), (array) $other);
        $cells = implode('\s+\|\s+', $row);
        $pattern = '/' . $cells . '/';
        return preg_match($pattern, $this->contents) > 0;
    }
    /**
     * Assertion message
     */
    public function to_string(): string
    {
        return sprintf('row was in %s', $this->output);
    }
    /**
     * @param mixed $other Expected content
     */
    public function failure_description(mixed $other): string
    {
        return '`' . (new Exporter())->shortened_export($other) . '` ' . $this->to_string();
    }
}
// phpcs:disable
class_alias(\Cake\Console\Test_Suite\Constraint\Contents_Contain_Row::class, 'Cake\TestSuite\Constraint\Console\ContentsContainRow');
// phpcs:enable