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

    public function testQuoteComparisonQuotesFunctionCallWithStringLiteral(): void
    {
        $this->assertSame(
            'CONCAT("first_name", " ", "last_name")',
            $this->quoteComparisonField('CONCAT(first_name, " ", last_name)'),
        );
    }

    public function testQuoteComparisonQuotesIdentifiersInsideFunctionCall(): void
    {
        $this->assertSame(
            'FUNC("Authors"."id")',
            $this->quoteComparisonField('FUNC( Authors.id)'),
        );
    }

    public function testQuoteIdentifierLeavesNumericLiteral(): void
    {
        $quoter = new IdentifierQuoter('"', '"');

        $this->assertSame('1.5', $quoter->quoteIdentifier('1.5'));
        $this->assertSame('1.5 AS "a"', $quoter->quoteIdentifier('1.5 AS a'));
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

    public function testQuoteComparisonQuotesNestedFunctionCall(): void
    {
        $this->assertSame(
            'CONCAT(CONCAT("first_name", "last_name"), " ")',
            $this->quoteComparisonField('CONCAT(CONCAT(first_name, last_name), " ")'),
        );
    }

    public function testQuoteComparisonPreservesSingleQuotedLiteralWithCommaAndParenthesis(): void
    {
        $this->assertSame(
            'CONCAT("first_name", \', (x)\')',
            $this->quoteComparisonField("CONCAT(first_name, ', (x)')"),
        );
    }
}
