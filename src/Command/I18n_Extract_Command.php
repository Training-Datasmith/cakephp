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
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Command;

use Cake\Command\Helper\Progress_Helper;
use Cake\Console\Arguments;
use Cake\Console\Console_Io;
use Cake\Console\Console_Option_Parser;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Plugin;
use Cake\Utility\Filesystem;
use Cake\Utility\Inflector;
/**
 * Language string extractor
 */
class I18n_Extract_Command extends Command
{
    /**
     * Paths to use when looking for strings
     *
     * @var array<string>
     */
    protected array $_paths = [];
    /**
     * Files from where to extract
     *
     * @var array<string>
     */
    protected array $_files = [];
    /**
     * Merge all domain strings into the default.pot file
     */
    protected bool $_merge = false;
    /**
     * Current file being processed
     */
    protected string $_file = '';
    /**
     * Contains all content waiting to be written
     *
     * @var array<string, mixed>
     */
    protected array $_storage = [];
    /**
     * Extracted tokens
     */
    protected array $_tokens = [];
    /**
     * Extracted strings indexed by domain.
     *
     * @var array<string, mixed>
     */
    protected array $_translations = [];
    /**
     * Destination path
     */
    protected string $_output = '';
    /**
     * An array of directories to exclude.
     *
     * @var array<string>
     */
    protected array $_exclude = [];
    /**
     * Holds whether this call should extract the CakePHP Lib messages
     */
    protected bool $_extract_core = false;
    /**
     * Displays marker error(s) if true
     */
    protected bool $_marker_error = false;
    /**
     * Count number of marker errors found
     */
    protected int $_count_marker_error = 0;
    /**
     * @inheritDoc
     */
    public static function default_name(): string
    {
        return 'i18n extract';
    }
    /**
     * @inheritDoc
     */
    public static function get_description(): string
    {
        return 'Extract i18n POT files from application source files.';
    }
    /**
     * Method to interact with the user and get path selections.
     *
     * @param \Cake\Console\ConsoleIo $io The io instance.
     */
    protected function _get_paths(Console_Io $io): void
    {
        $default_paths = array_merge([APP], array_values(App::path('templates')), ['D']);
        $default_path_index = 0;
        while (true) {
            $current_paths = $this->_paths !== [] ? $this->_paths : ['None'];
            $message = sprintf("Current paths: %s\nWhat is the path you would like to extract?\n[Q]uit [D]one", implode(', ', $current_paths));
            $response = $io->ask($message, $default_paths[$default_path_index] ?? 'D');
            if (strtoupper($response) === 'Q') {
                $io->error('Extract Aborted');
                $this->abort();
            }
            if (strtoupper($response) === 'D' && count($this->_paths)) {
                $io->out();
                return;
            }
            if (strtoupper($response) === 'D') {
                $io->warning('No directories selected. Please choose a directory.');
            } elseif (is_dir($response)) {
                $this->_paths[] = $response;
                $default_path_index++;
            } else {
                $io->error('The directory path you supplied was not found. Please try again.');
            }
            $io->out();
        }
    }
    /**
     * Execute the command
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, Console_Io $io): ?int
    {
        $plugin = '';
        if ($args->get_option('exclude')) {
            $this->_exclude = explode(',', (string) $args->get_option('exclude'));
        }
        if ($args->get_option('files')) {
            $this->_files = explode(',', (string) $args->get_option('files'));
        }
        if ($args->get_option('paths')) {
            $this->_paths = explode(',', (string) $args->get_option('paths'));
        }
        if ($args->get_option('plugin')) {
            $plugin = Inflector::camelize((string) $args->get_option('plugin'));
            if ($this->_paths === []) {
                $this->_paths = [Plugin::class_path($plugin), Plugin::template_path($plugin)];
            }
        } elseif (!$args->get_option('paths')) {
            $this->_get_paths($io);
        }
        if ($args->has_option('extract-core')) {
            $this->_extract_core = strtolower((string) $args->get_option('extract-core')) !== 'no';
        } else {
            $response = $io->ask_choice('Would you like to extract the messages from the CakePHP core?', ['y', 'n'], 'n');
            $this->_extract_core = strtolower($response) === 'y';
        }
        if ($args->has_option('exclude-plugins') && $this->_is_extracting_app()) {
            $this->_exclude = array_merge($this->_exclude, array_values(App::path('plugins')));
        }
        if ($this->_extract_core) {
            $this->_paths[] = CAKE;
        }
        if ($args->has_option('output')) {
            $this->_output = (string) $args->get_option('output');
        } elseif ($args->has_option('plugin')) {
            $this->_output = Plugin::path($plugin) . 'resources' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR;
        } else {
            $message = "What is the path you would like to output?\n[Q]uit";
            $locale_paths = array_values(App::path('locales'));
            if (!$locale_paths) {
                $locale_paths[] = ROOT . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR;
            }
            while (true) {
                $response = $io->ask($message, $locale_paths[0]);
                if (strtoupper($response) === 'Q') {
                    $io->error('Extract Aborted');
                    return static::CODE_ERROR;
                }
                if ($this->_is_path_usable($response)) {
                    $this->_output = $response . DIRECTORY_SEPARATOR;
                    break;
                }
                $io->err('');
                $io->error('The directory path you supplied was ' . 'not found. Please try again.');
                $io->err('');
            }
        }
        if ($args->has_option('merge')) {
            $this->_merge = strtolower((string) $args->get_option('merge')) !== 'no';
        } else {
            $io->out();
            $response = $io->ask_choice('Would you like to merge all domain strings into the default.pot file?', ['y', 'n'], 'n');
            $this->_merge = strtolower($response) === 'y';
        }
        $this->_marker_error = (bool) $args->get_option('marker-error');
        if (!$this->_files) {
            $this->_search_files();
        }
        $this->_output = rtrim($this->_output, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!$this->_is_path_usable($this->_output)) {
            $io->error(sprintf('The output directory `%s` was not found or writable.', $this->_output));
            return static::CODE_ERROR;
        }
        $this->_extract($args, $io);
        return static::CODE_SUCCESS;
    }
    /**
     * Add a translation to the internal translations property
     *
     * Takes care of duplicate translations
     *
     * @param string $domain The domain
     * @param string $msgid The message string
     * @param array<string, mixed> $details Context and plural form if any, file and line references
     */
    protected function _add_translation(string $domain, string $msgid, array $details = []): void
    {
        $context = $details['msgctxt'] ?? '';
        if (empty($this->_translations[$domain][$msgid][$context])) {
            $this->_translations[$domain][$msgid][$context] = ['msgid_plural' => false];
        }
        if (isset($details['msgid_plural'])) {
            $this->_translations[$domain][$msgid][$context]['msgid_plural'] = $details['msgid_plural'];
        }
        if (isset($details['file'])) {
            $line = $details['line'] ?? 0;
            $this->_translations[$domain][$msgid][$context]['references'][$details['file']][] = $line;
        }
    }
    /**
     * Extract text
     *
     * @param \Cake\Console\Arguments $args The Arguments instance
     * @param \Cake\Console\ConsoleIo $io The io instance
     */
    protected function _extract(Arguments $args, Console_Io $io): void
    {
        $io->out();
        $io->out();
        $io->out('Extracting...');
        $io->hr();
        $io->out('Paths:');
        foreach ($this->_paths as $path) {
            $io->out('   ' . $path);
        }
        $io->out('Output Directory: ' . $this->_output);
        $io->hr();
        $this->_extract_tokens($args, $io);
        $this->_build_files($args);
        $this->_write_files($args, $io);
        $this->_paths = [];
        $this->_files = [];
        $this->_storage = [];
        $this->_translations = [];
        $this->_tokens = [];
        $io->out();
        if ($this->_count_marker_error) {
            $io->error("{$this->_count_marker_error} marker error(s) detected.");
            $io->err(' => Use the --marker-error option to display errors.');
        }
        $io->out('Done.');
    }
    /**
     * Gets the option parser instance and configures it.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to configure
     */
    public function build_option_parser(Console_Option_Parser $parser): Console_Option_Parser
    {
        $parser->set_description([static::get_description(), 'Source files are parsed and string literal format strings ' . 'provided to the <info>__</info> family of functions are extracted.'])->add_option('app', ['help' => 'Directory where your application is located.'])->add_option('paths', ['help' => 'Comma separated list of paths that are searched for source files.'])->add_option('merge', ['help' => 'Merge all domain strings into a single default.po file.', 'default' => 'no', 'choices' => ['yes', 'no']])->add_option('output', ['help' => 'Full path to output directory.'])->add_option('files', ['help' => 'Comma separated list of files to parse.'])->add_option('exclude-plugins', ['boolean' => true, 'default' => true, 'help' => 'Ignores all files in plugins if this command is run inside from the same app directory.'])->add_option('plugin', ['help' => 'Extracts tokens only from the plugin specified and ' . "puts the result in the plugin's `locales` directory.", 'short' => 'p'])->add_option('exclude', ['help' => 'Comma separated list of directories to exclude.' . ' Any path containing a path segment with the provided values will be skipped. E.g. test,vendors'])->add_option('overwrite', ['boolean' => true, 'default' => false, 'help' => 'Always overwrite existing .pot files.'])->add_option('extract-core', ['help' => 'Extract messages from the CakePHP core libraries.', 'choices' => ['yes', 'no']])->add_option('no-location', ['boolean' => true, 'default' => false, 'help' => 'Do not write file locations for each extracted message.'])->add_option('marker-error', ['boolean' => true, 'default' => false, 'help' => 'Do not display marker error.']);
        return $parser;
    }
    /**
     * Extract tokens out of all files to be processed
     *
     * @param \Cake\Console\Arguments $args The io instance
     * @param \Cake\Console\ConsoleIo $io The io instance
     */
    protected function _extract_tokens(Arguments $args, Console_Io $io): void
    {
        $progress = $io->helper('progress');
        assert($progress instanceof Progress_Helper);
        $progress->init(['total' => count($this->_files)]);
        $is_verbose = $args->get_option('verbose');
        $functions = ['__' => ['singular'], '__n' => ['singular', 'plural'], '__d' => ['domain', 'singular'], '__dn' => ['domain', 'singular', 'plural'], '__x' => ['context', 'singular'], '__xn' => ['context', 'singular', 'plural'], '__dx' => ['domain', 'context', 'singular'], '__dxn' => ['domain', 'context', 'singular', 'plural']];
        $pattern = '/(' . implode('|', array_keys($functions)) . ')\s*\(/';
        foreach ($this->_files as $file) {
            $this->_file = $file;
            if ($is_verbose) {
                $io->verbose(sprintf('Processing %s...', $file));
            }
            $code = (string) file_get_contents($file);
            if (preg_match($pattern, $code) === 1) {
                $all_tokens = token_get_all($code);
                $this->_tokens = [];
                foreach ($all_tokens as $token) {
                    if (!is_array($token) || $token[0] !== T_WHITESPACE && $token[0] !== T_INLINE_HTML) {
                        $this->_tokens[] = $token;
                    }
                }
                unset($all_tokens);
                foreach ($functions as $function_name => $map) {
                    $this->_parse($io, $function_name, $map);
                }
            }
            if (!$is_verbose) {
                $progress->increment(1);
                $progress->draw();
            }
        }
    }
    /**
     * Parse tokens
     *
     * @param \Cake\Console\ConsoleIo $io The io instance
     * @param string $functionName Function name that indicates translatable string (e.g: '__')
     * @param array $map Array containing what variables it will find (e.g: domain, singular, plural)
     */
    protected function _parse(Console_Io $io, string $function_name, array $map): void
    {
        $count = 0;
        $token_count = count($this->_tokens);
        while ($token_count - $count > 1) {
            $count_token = $this->_tokens[$count];
            $first_parenthesis = $this->_tokens[$count + 1];
            if (!is_array($count_token)) {
                $count++;
                continue;
            }
            [$type, $string, $line] = $count_token;
            if ($type === T_STRING && $string === $function_name && $first_parenthesis === '(') {
                $position = $count;
                $depth = 0;
                while (!$depth) {
                    if ($this->_tokens[$position] === '(') {
                        $depth++;
                    } elseif ($this->_tokens[$position] === ')') {
                        $depth--;
                    }
                    $position++;
                }
                $map_count = count($map);
                $strings = $this->_get_strings($position, $map_count);
                if ($map_count === count($strings)) {
                    $singular = '';
                    $vars = array_combine($map, $strings);
                    extract($vars);
                    $domain ??= 'default';
                    $details = ['file' => $this->_file, 'line' => $line];
                    $details['file'] = '.' . str_replace(ROOT, '', $details['file']);
                    if (isset($plural)) {
                        $details['msgid_plural'] = $plural;
                    }
                    if (isset($context)) {
                        $details['msgctxt'] = $context;
                    }
                    $this->_add_translation($domain, $singular, $details);
                } else {
                    $this->_marker_error($io, $this->_file, $line, $function_name, $count);
                }
            }
            $count++;
        }
    }
    /**
     * Build the translate template file contents out of obtained strings
     *
     * @param \Cake\Console\Arguments $args Console arguments
     */
    protected function _build_files(Arguments $args): void
    {
        $paths = $this->_paths;
        $paths[] = realpath(APP) . DIRECTORY_SEPARATOR;
        usort($paths, fn(string $a, string $b) => strlen($a) - strlen($b));
        foreach ($this->_translations as $domain => $translations) {
            foreach ($translations as $msgid => $contexts) {
                foreach ($contexts as $context => $details) {
                    $plural = $details['msgid_plural'];
                    $files = $details['references'];
                    $header = '';
                    if (!$args->get_option('no-location')) {
                        $occurrences = [];
                        foreach ($files as $file => $lines) {
                            $lines = array_unique($lines);
                            foreach ($lines as $line) {
                                $occurrences[] = $file . ':' . $line;
                            }
                        }
                        $occurrences = implode("\n#: ", $occurrences);
                        $header = '#: ' . str_replace(DIRECTORY_SEPARATOR, '/', $occurrences) . "\n";
                    }
                    $sentence = '';
                    if ($context !== '') {
                        $sentence .= "msgctxt \"{$context}\"\n";
                    }
                    if ($plural === false) {
                        $sentence .= "msgid \"{$msgid}\"\n";
                        $sentence .= "msgstr \"\"\n\n";
                    } else {
                        $sentence .= "msgid \"{$msgid}\"\n";
                        $sentence .= "msgid_plural \"{$plural}\"\n";
                        $sentence .= "msgstr[0] \"\"\n";
                        $sentence .= "msgstr[1] \"\"\n\n";
                    }
                    if ($domain !== 'default' && $this->_merge) {
                        $this->_store('default', $header, $sentence);
                    } else {
                        $this->_store($domain, $header, $sentence);
                    }
                }
            }
        }
    }
    /**
     * Prepare a file to be stored
     *
     * @param string $domain The domain
     * @param string $header The header content.
     * @param string $sentence The sentence to store.
     */
    protected function _store(string $domain, string $header, string $sentence): void
    {
        $this->_storage[$domain] ??= [];
        if (!isset($this->_storage[$domain][$sentence])) {
            $this->_storage[$domain][$sentence] = $header;
        } else {
            $this->_storage[$domain][$sentence] .= $header;
        }
    }
    /**
     * Write the files that need to be stored
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     */
    protected function _write_files(Arguments $args, Console_Io $io): void
    {
        $io->out();
        $overwrite_all = false;
        if ($args->get_option('overwrite')) {
            $overwrite_all = true;
        }
        foreach ($this->_storage as $domain => $sentences) {
            $output = $this->_write_header($domain);
            $header_length = strlen($output);
            foreach ($sentences as $sentence => $header) {
                $output .= $header . $sentence;
            }
            $filename = str_replace('/', '_', $domain) . '.pot';
            $output_path = $this->_output . $filename;
            if ($this->check_unchanged($output_path, $header_length, $output)) {
                $io->out($filename . ' is unchanged. Skipping.');
                continue;
            }
            $response = '';
            while ($overwrite_all === false && file_exists($output_path) && strtoupper($response) !== 'Y') {
                $io->out();
                $response = $io->ask_choice(sprintf('Error: %s already exists in this location. Overwrite? [Y]es, [N]o, [A]ll', $filename), ['y', 'n', 'a'], 'y');
                if (strtoupper($response) === 'N') {
                    $response = '';
                    while (!$response) {
                        $response = $io->ask('What would you like to name this file?', 'new_' . $filename);
                        $filename = $response;
                    }
                } elseif (strtoupper($response) === 'A') {
                    $overwrite_all = true;
                }
            }
            $fs = new Filesystem();
            $fs->dump_file($this->_output . $filename, $output);
        }
    }
    /**
     * Build the translation template header
     *
     * @param string $domain Domain
     * @return string Translation template header
     */
    protected function _write_header(string $domain): string
    {
        $project_id_version = $domain === 'cake' ? 'CakePHP ' . Configure::version() : 'PROJECT VERSION';
        $output = "# LANGUAGE translation of CakePHP Application\n";
        $output .= "# Copyright YEAR NAME <EMAIL@ADDRESS>\n";
        $output .= "#\n";
        $output .= "#, fuzzy\n";
        $output .= "msgid \"\"\n";
        $output .= "msgstr \"\"\n";
        $output .= '"Project-Id-Version: ' . $project_id_version . "\\n\"\n";
        $output .= '"POT-Creation-Date: ' . date('Y-m-d H:iO') . "\\n\"\n";
        $output .= "\"PO-Revision-Date: YYYY-mm-DD HH:MM+ZZZZ\\n\"\n";
        $output .= "\"Last-Translator: NAME <EMAIL@ADDRESS>\\n\"\n";
        $output .= "\"Language-Team: LANGUAGE <EMAIL@ADDRESS>\\n\"\n";
        $output .= "\"MIME-Version: 1.0\\n\"\n";
        $output .= "\"Content-Type: text/plain; charset=utf-8\\n\"\n";
        $output .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
        return $output . "\"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n\"\n\n";
    }
    /**
     * Check whether the old and new output are the same, thus unchanged
     *
     * Compares the sha1 hashes of the old and new file without header.
     *
     * @param string $oldFile The existing file.
     * @param int $headerLength The length of the file header in bytes.
     * @param string $newFileContent The content of the new file.
     * @return bool Whether the old and new file are unchanged.
     */
    protected function check_unchanged(string $old_file, int $header_length, string $new_file_content): bool
    {
        if (!file_exists($old_file)) {
            return false;
        }
        $old_file_content = file_get_contents($old_file);
        if ($old_file_content === false) {
            throw new Cake_Exception(sprintf('Cannot read file content of `%s`', $old_file));
        }
        $old_checksum = sha1(substr($old_file_content, $header_length));
        $new_checksum = sha1(substr($new_file_content, $header_length));
        return $old_checksum === $new_checksum;
    }
    /**
     * Get the strings from the position forward
     *
     * @param int $position Actual position on tokens array
     * @param int $target Number of strings to extract
     * @return array Strings extracted
     */
    protected function _get_strings(int &$position, int $target): array
    {
        $strings = [];
        $count = 0;
        while ($count < $target && ($this->_tokens[$position] === ',' || $this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING || $this->_tokens[$position][0] === T_LNUMBER)) {
            $count = count($strings);
            if ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING && $this->_tokens[$position + 1] === '.') {
                $string = '';
                while ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING || $this->_tokens[$position] === '.') {
                    if ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $string .= $this->_format_string($this->_tokens[$position][1]);
                    }
                    $position++;
                }
                $strings[] = $string;
            } elseif ($this->_tokens[$position][0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = $this->_format_string($this->_tokens[$position][1]);
            } elseif ($this->_tokens[$position][0] === T_LNUMBER) {
                $strings[] = $this->_tokens[$position][1];
            }
            $position++;
        }
        return $strings;
    }
    /**
     * Format a string to be added as a translatable string
     *
     * @param string $string String to format
     * @return string Formatted string
     */
    protected function _format_string(string $string): string
    {
        $quote = substr($string, 0, 1);
        $string = substr($string, 1, -1);
        if ($quote === '"') {
            $string = stripcslashes($string);
        } else {
            $string = strtr($string, ["\\'" => "'", '\\\\' => '\\']);
        }
        $string = str_replace("\r\n", "\n", $string);
        return addcslashes($string, "\x00..\x1f\\\"");
    }
    /**
     * Indicate an invalid marker on a processed file
     *
     * @param \Cake\Console\ConsoleIo $io The io instance.
     * @param string $file File where invalid marker resides
     * @param int $line Line number
     * @param string $marker Marker found
     * @param int $count Count
     */
    protected function _marker_error(Console_Io $io, string $file, int $line, string $marker, int $count): void
    {
        if (!str_contains($this->_file, CAKE_CORE_INCLUDE_PATH)) {
            $this->_count_marker_error++;
        }
        if (!$this->_marker_error) {
            return;
        }
        $io->error(sprintf("Invalid marker content in %s:%s\n* %s(", $file, $line, $marker));
        $count += 2;
        $token_count = count($this->_tokens);
        $parenthesis = 1;
        while ($token_count - $count > 0 && $parenthesis) {
            if (is_array($this->_tokens[$count])) {
                $io->err($this->_tokens[$count][1], 0);
            } else {
                $io->err($this->_tokens[$count], 0);
                if ($this->_tokens[$count] === '(') {
                    $parenthesis++;
                }
                if ($this->_tokens[$count] === ')') {
                    $parenthesis--;
                }
            }
            $count++;
        }
        $io->err("\n");
    }
    /**
     * Search files that may contain translatable strings
     */
    protected function _search_files(): void
    {
        $pattern = false;
        if ($this->_exclude) {
            $exclude = [];
            foreach ($this->_exclude as $e) {
                if (DIRECTORY_SEPARATOR !== '\\' && !str_starts_with($e, DIRECTORY_SEPARATOR)) {
                    $e = DIRECTORY_SEPARATOR . $e;
                }
                $exclude[] = preg_quote($e, '/');
            }
            $pattern = '/' . implode('|', $exclude) . '/';
        }
        foreach ($this->_paths as $path) {
            $path = realpath($path);
            if ($path === false) {
                continue;
            }
            $path .= DIRECTORY_SEPARATOR;
            $fs = new Filesystem();
            $files = $fs->find_recursive($path, '/\.php$/');
            $files = array_keys(iterator_to_array($files));
            sort($files);
            if ($pattern) {
                $files = preg_grep($pattern, $files, PREG_GREP_INVERT) ?: [];
                $files = array_values($files);
            }
            $this->_files = array_merge($this->_files, $files);
        }
        $this->_files = array_unique($this->_files);
    }
    /**
     * Returns whether this execution is meant to extract string only from directories in folder represented by the
     * APP constant, i.e. this task is extracting strings from same application.
     */
    protected function _is_extracting_app(): bool
    {
        return $this->_paths === [APP];
    }
    /**
     * Checks whether a given path is usable for writing.
     *
     * @param string $path Path to folder
     * @return bool true if it exists and is writable, false otherwise
     */
    protected function _is_path_usable(string $path): bool
    {
        if (!is_dir($path)) {
            mkdir($path, 0777 ^ umask(), true);
        }
        return is_dir($path) && is_writable($path);
    }
}