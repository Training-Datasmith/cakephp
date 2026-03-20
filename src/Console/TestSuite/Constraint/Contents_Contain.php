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

/**
 * ContentsContain
 *
 * @internal
 */
class Contents_Contain extends Contents_Base
{
    /**
     * Checks if contents contain expected
     *
     * @param mixed $other Expected
     */
    public function matches(mixed $other): bool
    {
        return mb_strpos($this->contents, (string) $other) !== false;
    }
    /**
     * Assertion message
     */
    public function to_string(): string
    {
        return sprintf('is in %s.', $this->output);
    }
    /**
     * @inheritDoc
     */
    protected function additional_failure_description(mixed $other): string
    {
        return sprintf("actual result:\n%s", $this->contents);
    }
}
// phpcs:disable
class_alias(\Cake\Console\Test_Suite\Constraint\Contents_Contain::class, 'Cake\TestSuite\Constraint\Console\ContentsContain');
// phpcs:enable