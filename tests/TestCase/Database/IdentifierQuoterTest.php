<?php
declare(strict_types=1);

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
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Database;

use Cake\Database\Expression\ComparisonExpression;
use Cake\Database\IdentifierQuoter;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

/**
 * Tests for IdentifierQuoter identifier rewriting.
 */
class IdentifierQuoterTest extends TestCase
{
    private function quoteComparisonField(string $field): string
    {
        $quoter = new IdentifierQuoter('"', '"');
        $expression = new ComparisonExpression($field, 1, 'integer', '=');
        $method = new ReflectionMethod(IdentifierQuoter::class, '_quoteComparison');
        $method->invoke($quoter, $expression);

        $field = $expression->getField();

        return is_string($field) ? $field : '';
    }

    public function testQuoteComparisonLeavesFunctionCallFieldUnquoted(): void
    {
        $expressionField = 'CONCAT(first_name, " ", last_name)';

        $this->assertSame($expressionField, $this->quoteComparisonField($expressionField));
    }

    public function testQuoteComparisonQuotesPlainIdentifierField(): void
    {
        $this->assertSame('"published"', $this->quoteComparisonField('published'));
    }

    public function testQuoteComparisonQuotesDottedIdentifierField(): void
    {
        $this->assertSame('"articles"."id"', $this->quoteComparisonField('articles.id'));
    }

    public function testQuoteComparisonQuotesSpacedIdentifierField(): void
    {
        $this->assertSame(
            '"Column with spaces"',
            $this->quoteComparisonField('Column with spaces'),
        );
    }
}
