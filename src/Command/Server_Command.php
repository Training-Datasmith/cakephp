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
 * @since         2.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Core\Configure;
use function Cake\Core\env;
/**
 * built-in Server command
 */
class Server_Command extends Command
{
    /**
     * Default ServerHost
     *
     * @var string
     */
    public const DEFAULT_HOST = 'localhost';
    /**
     * Default ListenPort
     *
     * @var int
     */
    public const DEFAULT_PORT = 8765;
    /**
     * server host
     */
    protected string $_host = self::DEFAULT_HOST;
    /**
     * listen port
     */
    protected int $_port = self::DEFAULT_PORT;
    /**
     * document root
     */
    protected string $_document_root = WWW_ROOT;
    /**
     * ini path
     */
    protected string $_ini_path = '';
    /**
     * The server type.
     */
    protected string $server = 'php';
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return "Start PHP's built-in server or FrankenPHP for CakePHP";
    }
    /**
     * Starts up the Command and displays the welcome message.
     * Allows for checking and configuring prior to command or main execution
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @link https://book.cakephp.org/5/en/console-commands/commands.html#lifecycle-callbacks
     */
    protected function startup(Arguments $args, Console_Io $io): void
    {
        if ($args->get_option('host')) {
            $this->_host = (string) $args->get_option('host');
        }
        if ($args->get_option('port')) {
            $this->_port = (int) $args->get_option('port');
        }
        if ($args->get_option('document_root')) {
            $this->_document_root = (string) $args->get_option('document_root');
        }
        if ($args->get_option('ini_path')) {
            $this->_ini_path = (string) $args->get_option('ini_path');
        }
        if ($args->get_option('frankenphp')) {
            $this->server = 'frankenphp';
        }
        // For Windows
        if (substr($this->_document_root, -1, 1) === DIRECTORY_SEPARATOR) {
            $this->_document_root = substr($this->_document_root, 0, strlen($this->_document_root) - 1);
        }
        if (preg_match("/^([a-z]:)[\\\\]+(.+)\$/i", $this->_document_root, $m)) {
            $this->_document_root = $m[1] . '\\' . $m[2];
        }
        $this->_ini_path = rtrim($this->_ini_path, DIRECTORY_SEPARATOR);
        if (preg_match("/^([a-z]:)[\\\\]+(.+)\$/i", $this->_ini_path, $m)) {
            $this->_ini_path = $m[1] . '\\' . $m[2];
        }
        $io->out();
        $io->out(sprintf('<info>Welcome to CakePHP %s Console</info>', 'v' . Configure::version()));
        $io->hr();
        $io->out(sprintf('App : %s', Configure::read('App.dir')));
        $io->out(sprintf('Path: %s', APP));
        $io->out(sprintf('DocumentRoot: %s', $this->_document_root));
        $io->out(sprintf('Ini Path: %s', $this->_ini_path));
        $io->hr();
    }
    /**
     * Execute.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int The exit code
     */
    public function execute(Arguments $args, Console_Io $io): int
    {
        $this->startup($args, $io);
        $io->out(sprintf('%s server is running at http://%s:%s/', $this->server, $this->_host, $this->_port));
        $io->out('You can exit with <info>`CTRL-C`</info>');
        return $this->run_command($this->{$this->server . 'Command'}());
    }
    /**
     * Runs the command.
     *
     * @param string $command The command to run
     * @return int The exit code
     */
    protected function run_command(string $command): int
    {
        if (system($command) === false) {
            return static::CODE_ERROR;
        }
        return static::CODE_SUCCESS;
    }
    /**
     * Returns the command to run PHP's built-in server.
     */
    protected function php_command(): string
    {
        $command = sprintf('%s -S %s:%d -t %s', (string) env('PHP', 'php'), $this->_host, $this->_port, escapeshellarg($this->_document_root));
        if ($this->_ini_path) {
            $command = sprintf('%s -c %s', $command, escapeshellarg($this->_ini_path));
        }
        return sprintf('%s %s', $command, escapeshellarg($this->_document_root . '/index.php'));
    }
    /**
     * Returns the command to run frankenphp's server.
     */
    protected function frankenphp_command(): string
    {
        return sprintf('%s php-server -a -l %s:%d -r %s', (string) env('FRANKENPHP', 'frankenphp'), $this->_host, $this->_port, escapeshellarg($this->_document_root));
    }
    /**
     * Hook method for defining this command's option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to update
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description([static::get_description(), "<warning>[WARN] Don't use this in a production environment</warning>"])->add_option('host', ['short' => 'H', 'help' => 'ServerHost'])->add_option('port', ['short' => 'p', 'help' => 'ListenPort'])->add_option('ini_path', ['short' => 'I', 'help' => 'php.ini path'])->add_option('document_root', ['short' => 'd', 'help' => 'DocumentRoot'])->add_option('frankenphp', ['boolean' => true, 'short' => 'f', 'help' => "Use frankenphp instead of PHP's built-in server"]);
        return $parser;
    }
}