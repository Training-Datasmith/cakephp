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
 * @link          https://cakephp.org CakePHP Project
 * @since         5.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command\Helper;

use Cake\Console\Helper;
use InvalidArgumentException;
/**
 * Banner command helper.
 *
 * Formats one or more lines of text into a large banner with
 * padding and a blank line below.
 */
class Banner_Helper extends Helper
{
    /**
     * @var int The horizontal padding that is added to the longest line.
     */
    private int $padding = 2;
    /**
     * @var string The console output style to use on the banner.
     */
    private string $style = 'success.bg';
    /**
     * Modify the padding of the helper
     *
     * @param int $padding The padding value to use.
     * @return $this
     */
    public function with_padding(int $padding): static
    {
        if ($padding < 0) {
            throw new InvalidArgumentException('padding must be greater than 0');
        }
        $this->padding = $padding;
        return $this;
    }
    /**
     * Modify the padding of the helper
     *
     * @param string $style The style value to use.
     * @return $this
     */
    public function with_style(string $style): static
    {
        $this->style = $style;
        return $this;
    }
    /**
     * Output a banner
     *
     * @param array $args The messages to output
     */
    public function output(array $args): void
    {
        if ($args === []) {
            throw new InvalidArgumentException('At least one argument is required');
        }
        $lengths = array_map(mb_strlen(...), $args);
        $max_length = max($lengths);
        $banner_length = $max_length + $this->padding * 2;
        $start = "<{$this->style}>";
        $end = "</{$this->style}>";
        $lines = ['', $start . str_repeat(' ', $banner_length) . $end];
        foreach ($args as $line) {
            $line_length = mb_strlen((string) $line);
            $line_padding = max($this->padding, $banner_length - $line_length - $this->padding);
            $lines[] = $start . str_repeat(' ', $this->padding) . $line . str_repeat(' ', $line_padding) . $end;
        }
        $lines[] = $start . str_repeat(' ', $banner_length) . $end;
        $lines[] = '';
        $this->_io->out($lines);
    }
}