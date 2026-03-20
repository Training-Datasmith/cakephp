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

use Php_Unit\Framework\Constraint\Constraint;
/**
 * ExitCode constraint
 *
 * @internal
 */
class Exit_Code extends Constraint
{
    /**
     * Constructor
     *
     * @param int|null $exitCode Exit code
     * @param array $out stdout stream
     * @param array $err stderr stream
     */
    public function __construct(private readonly ?int $exit_code, private readonly array $out, private readonly array $err)
    {
    }
    /**
     * Checks if event is in fired array
     *
     * @param mixed $other Constraint check
     */
    public function matches(mixed $other): bool
    {
        return $other === $this->exit_code;
    }
    /**
     * Assertion message string
     */
    public function to_string(): string
    {
        return sprintf('matches exit code `%s`', $this->exit_code ?? 'null');
    }
    /**
     * Returns the description of the failure.
     *
     * @param mixed $other Expected
     */
    public function failure_description(mixed $other): string
    {
        return '`' . $other . '` ' . $this->to_string();
    }
    /**
     * @inheritDoc
     */
    public function additional_failure_description(mixed $other): string
    {
        return sprintf("STDOUT\n%s\n\nSTDERR\n%s\n", implode("\n", $this->out), implode("\n", $this->err));
    }
}
// phpcs:disable
class_alias(\Cake\Console\Test_Suite\Constraint\Exit_Code::class, 'Cake\TestSuite\Constraint\Console\ExitCode');
// phpcs:enable