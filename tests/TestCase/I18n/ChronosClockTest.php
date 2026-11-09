<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\I18n;

use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use Cake\TestSuite\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Exercises psr/clock through Cake Chronos.
 */
class ChronosClockTest extends TestCase
{
    public function testClockFactoryUsesFrozenChronosNow(): void
    {
        if (!class_exists(ClockFactory::class)) {
            $this->fail('Cake\\Chronos\\ClockFactory is missing; resolve cakephp/chronos >= 3.5.1.');
        }

        $previous = Chronos::getTestNow();
        try {
            $frozen = Chronos::parse('2015-12-12 10:10:10', 'UTC');
            Chronos::setTestNow($frozen);

            $clock = new ClockFactory('UTC');
            $this->assertInstanceOf(ClockInterface::class, $clock);

            $now = $clock->now();
            $this->assertInstanceOf(Chronos::class, $now);
            $this->assertSame('2015-12-12 10:10:10.000000', $now->format('Y-m-d H:i:s.u'));
        } finally {
            Chronos::setTestNow($previous);
        }
    }
}
