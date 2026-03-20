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
namespace Cake\Console\Test_Suite;

use Cake\Console\Command_Interface;
use Cake\Console\Command_Runner;
use Cake\Console\Console_Io;
use Cake\Console\Console_Output;
use Cake\Console\Exception\Stop_Exception;
use Cake\Console\Test_Suite\Constraint\Contents_Contain;
use Cake\Console\Test_Suite\Constraint\Contents_Contain_Row;
use Cake\Console\Test_Suite\Constraint\Contents_Empty;
use Cake\Console\Test_Suite\Constraint\Contents_Not_Contain;
use Cake\Console\Test_Suite\Constraint\Contents_Reg_Exp;
use Cake\Console\Test_Suite\Constraint\Exit_Code;
use Cake\Core\Console_Application_Interface;
use Cake\Core\Test_Suite\Container_Stub_Trait;
use Cake\Error\Debugger;
use Php_Unit\Framework\Attributes\After;
/**
 * A bundle of methods that makes testing commands
 * and shell classes easier.
 *
 * Enables you to call commands/shells with a
 * full application context.
 */
trait Console_Integration_Test_Trait
{
    use Container_Stub_Trait;
    /**
     * Last exit code
     */
    protected ?int $_exit_code = null;
    /**
     * Console output stub
     */
    protected ?Stub_Console_Output $_out = null;
    /**
     * Console error output stub
     */
    protected ?Stub_Console_Output $_err = null;
    /**
     * Console input mock
     */
    protected ?Stub_Console_Input $_in = null;
    /**
     * Runs CLI integration test
     *
     * @param string $command Command to run
     * @param array $input Input values to pass to an interactive shell
     * @throws \Cake\Console\TestSuite\MissingConsoleInputException
     * @throws \InvalidArgumentException
     */
    public function exec(string $command, array $input = []): void
    {
        $runner = $this->make_runner();
        $this->_out ??= new Stub_Console_Output();
        $this->_err ??= new Stub_Console_Output();
        if ($this->_in === null || $input) {
            $this->_in = new Stub_Console_Input($input);
        }
        $this->_out->clear();
        $this->_err->clear();
        $args = $this->command_string_to_args("cake {$command}");
        $io = new Console_Io($this->_out, $this->_err, $this->_in);
        try {
            $this->_exit_code = $runner->run($args, $io);
        } catch (Missing_Console_Input_Exception $e) {
            $messages = $this->_out->messages();
            if ($messages !== []) {
                $e->set_question($messages[count($messages) - 1]);
            }
            throw $e;
        } catch (Stop_Exception $exception) {
            $this->_exit_code = $exception->get_code();
        }
    }
    /**
     * Cleans state to get ready for the next test
     */
    #[After]
    public function cleanup_console_trait(): void
    {
        $this->_exit_code = null;
        $this->_out = null;
        $this->_err = null;
        $this->_in = null;
    }
    /**
     * Asserts shell exited with the expected code
     *
     * @param int $expected Expected exit code
     * @param string $message Failure message
     */
    public function assert_exit_code(int $expected, string $message = ''): void
    {
        $this->assert_that($expected, new Exit_Code($this->_exit_code, $this->_out->messages(), $this->_err->messages()), $message);
    }
    /**
     * Asserts shell exited with the CommandInterface::CODE_SUCCESS
     *
     * @param string $message Failure message
     */
    public function assert_exit_success(string $message = ''): void
    {
        $this->assert_that(Command_Interface::CODE_SUCCESS, new Exit_Code($this->_exit_code, $this->_out->messages(), $this->_err->messages()), $message);
    }
    /**
     * Asserts shell exited with CommandInterface::CODE_ERROR
     *
     * @param string $message Failure message
     */
    public function assert_exit_error(string $message = ''): void
    {
        $this->assert_that(Command_Interface::CODE_ERROR, new Exit_Code($this->_exit_code, $this->_out->messages(), $this->_err->messages()), $message);
    }
    /**
     * Asserts that `stdout` is empty
     *
     * @param string $message The message to output when the assertion fails.
     */
    public function assert_output_empty(string $message = ''): void
    {
        $this->assert_that(null, new Contents_Empty($this->_out->messages(), 'output'), $message);
    }
    /**
     * Asserts `stdout` contains expected output
     *
     * @param string $expected Expected output
     * @param string $message Failure message
     */
    public function assert_output_contains(string $expected, string $message = ''): void
    {
        $this->assert_that($expected, new Contents_Contain($this->_out->messages(), 'output'), $message);
    }
    /**
     * Asserts `stdout` does not contain expected output
     *
     * @param string $expected Expected output
     * @param string $message Failure message
     */
    public function assert_output_not_contains(string $expected, string $message = ''): void
    {
        $this->assert_that($expected, new Contents_Not_Contain($this->_out->messages(), 'output'), $message);
    }
    /**
     * Asserts `stdout` contains expected regexp
     *
     * @param string $pattern Expected pattern
     * @param string $message Failure message
     */
    public function assert_output_reg_exp(string $pattern, string $message = ''): void
    {
        $this->assert_that($pattern, new Contents_Reg_Exp($this->_out->messages(), 'output'), $message);
    }
    /**
     * Check that a row of cells exists in the output.
     *
     * @param array $row Row of cells to ensure exist in the output.
     * @param string $message Failure message.
     */
    protected function assert_output_contains_row(array $row, string $message = ''): void
    {
        $this->assert_that($row, new Contents_Contain_Row($this->_out->messages(), 'output'), $message);
    }
    /**
     * Asserts `stderr` contains expected output
     *
     * @param string $expected Expected output
     * @param string $message Failure message
     */
    public function assert_error_contains(string $expected, string $message = ''): void
    {
        $this->assert_that($expected, new Contents_Contain($this->_err->messages(), 'error output'), $message);
    }
    /**
     * Asserts `stderr` contains expected regexp
     *
     * @param string $pattern Expected pattern
     * @param string $message Failure message
     */
    public function assert_error_reg_exp(string $pattern, string $message = ''): void
    {
        $this->assert_that($pattern, new Contents_Reg_Exp($this->_err->messages(), 'error output'), $message);
    }
    /**
     * Asserts that `stderr` is empty
     *
     * @param string $message The message to output when the assertion fails.
     */
    public function assert_error_empty(string $message = ''): void
    {
        $this->assert_that(null, new Contents_Empty($this->_err->messages(), 'error output'), $message);
    }
    /**
     * Dump the exit code, stdout and stderr from the most recently run command
     *
     * @param resource|null $stream The stream to write to. Defaults to STDOUT
     */
    public function debug_output($stream = null): void
    {
        $output = new Console_Output($stream ?? 'php://stdout');
        if (class_exists(Debugger::class)) {
            $trace = Debugger::trace(['start' => 0, 'depth' => 1, 'format' => 'array']);
            $file = $trace[0]['file'];
            $line = $trace[0]['line'];
            $output->write("{$file} on {$line}");
        }
        $output->write('########## debugOutput() ##########');
        if ($this->_exit_code !== null) {
            $output->write('<info>Exit Code</info>');
            $output->write((string) $this->_exit_code, 2);
        }
        $output->write('<info>STDOUT</info>');
        $output->write($this->_out->messages(), 2);
        $output->write('<info>STDERR</info>');
        $output->write($this->_err->messages());
        $output->write('###################################');
    }
    /**
     * Builds the appropriate command dispatcher
     */
    protected function make_runner(): Command_Runner
    {
        $app = $this->create_app();
        assert($app instanceof Console_Application_Interface);
        return new Command_Runner($app);
    }
    /**
     * Creates an $argv array from a command string
     *
     * @param string $command Command string
     * @return array<string>
     */
    protected function command_string_to_args(string $command): array
    {
        $char_count = strlen($command);
        $argv = [];
        $arg = '';
        $in_d_quote = false;
        $in_s_quote = false;
        for ($i = 0; $i < $char_count; $i++) {
            $char = substr($command, $i, 1);
            // end of argument
            if ($char === ' ' && !$in_d_quote && !$in_s_quote) {
                if ($arg !== '') {
                    $argv[] = $arg;
                }
                $arg = '';
                continue;
            }
            // exiting single quote
            if ($in_s_quote && $char === "'") {
                $in_s_quote = false;
                continue;
            }
            // exiting double quote
            if ($in_d_quote && $char === '"') {
                $in_d_quote = false;
                continue;
            }
            // entering double quote
            if ($char === '"' && !$in_s_quote) {
                $in_d_quote = true;
                continue;
            }
            // entering single quote
            if ($char === "'" && !$in_d_quote) {
                $in_s_quote = true;
                continue;
            }
            $arg .= $char;
        }
        $argv[] = $arg;
        return $argv;
    }
}
// phpcs:disable
class_alias(\Cake\Console\Test_Suite\Console_Integration_Test_Trait::class, 'Cake\TestSuite\ConsoleIntegrationTestTrait');
// phpcs:enable