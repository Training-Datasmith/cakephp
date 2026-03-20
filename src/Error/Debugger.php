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
namespace Cake\Error;

use Cake\Core\Configure;
use Cake\Core\Exception\Cake_Exception;
use function Cake\Core\h;
use Cake\Core\Instance_Config_Trait;
use function Cake\Core\pr;
use Cake\Error\Debug\Array_Item_Node;
use Cake\Error\Debug\Array_Node;
use Cake\Error\Debug\Class_Node;
use Cake\Error\Debug\Console_Formatter;
use Cake\Error\Debug\Debug_Context;
use Cake\Error\Debug\Formatter_Interface;
use Cake\Error\Debug\Html_Formatter;
use Cake\Error\Debug\Node_Interface;
use Cake\Error\Debug\Property_Node;
use Cake\Error\Debug\Reference_Node;
use Cake\Error\Debug\Scalar_Node;
use Cake\Error\Debug\Special_Node;
use Cake\Error\Debug\Text_Formatter;
use Cake\Log\Log;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Closure;
use Exception;
use InvalidArgumentException;
use Reflection_Object;
use ReflectionProperty;
use Throwable;
/**
 * Provide custom logging and error handling.
 *
 * Debugger extends PHP's default error handling and gives
 * simpler to use more powerful interfaces.
 *
 * @link https://book.cakephp.org/5/en/development/debugging.html#using-the-debugger-class
 */
class Debugger
{
    use Instance_Config_Trait;
    /**
     * Default configuration
     *
     * @var array<string, mixed>
     */
    protected array $_default_config = ['outputMask' => [], 'exportFormatter' => null, 'editor' => 'phpstorm', 'editorBasePath' => null];
    /**
     * A map of editors to their link templates.
     *
     * @var array<string, string|callable>
     */
    protected array $editors = ['atom' => 'atom://core/open/file?filename={file}&line={line}', 'emacs' => 'emacs://open?url=file://{file}&line={line}', 'macvim' => 'mvim://open/?url=file://{file}&line={line}', 'phpstorm' => 'phpstorm://open?file={file}&line={line}', 'sublime' => 'subl://open?url=file://{file}&line={line}', 'textmate' => 'txmt://open?url=file://{file}&line={line}', 'vscode' => 'vscode://file/{file}:{line}', 'vscodium' => 'vscodium://file/{file}:{line}'];
    /**
     * Holds current output data when outputFormat is false.
     */
    protected array $_data = [];
    /**
     * Constructor.
     */
    public function __construct()
    {
        $doc_ref = ini_get('docref_root');
        if (!$doc_ref && function_exists('ini_set')) {
            ini_set('docref_root', 'https://secure.php.net/');
        }
        if (!defined('E_RECOVERABLE_ERROR')) {
            define('E_RECOVERABLE_ERROR', 4096);
        }
        $config = array_intersect_key((array) Configure::read('Debugger'), $this->_default_config);
        $this->set_config($config);
    }
    /**
     * Returns a reference to the Debugger singleton object instance.
     *
     * @param class-string<\Cake\Error\Debugger>|null $class Class name.
     */
    public static function get_instance(?string $class = null): static
    {
        /** @var array<int, static> $instance */
        static $instance = [];
        if ($class && (!$instance || strtolower($class) !== strtolower($instance[0]::class))) {
            $instance[0] = new $class();
        }
        if (!$instance) {
            $instance[0] = new Debugger();
        }
        return $instance[0];
    }
    /**
     * Read or write configuration options for the Debugger instance.
     *
     * @param array<string, mixed>|string|null $key The key to get/set, or a complete array of configs.
     * @param mixed|null $value The value to set.
     * @param bool $merge Whether to recursively merge or overwrite existing config, defaults to true.
     * @return mixed Config value being read, or the object itself on write operations.
     * @throws \Cake\Core\Exception\CakeException When trying to set a key that is invalid.
     */
    public static function config_instance(array|string|null $key = null, mixed $value = null, bool $merge = true): mixed
    {
        if ($key === null) {
            return static::get_instance()->get_config($key);
        }
        if (is_array($key) || func_num_args() >= 2) {
            return static::get_instance()->set_config($key, $value, $merge);
        }
        return static::get_instance()->get_config($key);
    }
    /**
     * Reads the current output masking.
     *
     * @return array<string, string>
     */
    public static function output_mask(): array
    {
        return static::config_instance('outputMask');
    }
    /**
     * Sets configurable masking of debugger output by property name and array key names.
     *
     * ### Example
     *
     * Debugger::setOutputMask(['password' => '[*************]']);
     *
     * @param array<string, string> $value An array where keys are replaced by their values in output.
     * @param bool $merge Whether to recursively merge or overwrite existing config, defaults to true.
     */
    public static function set_output_mask(array $value, bool $merge = true): void
    {
        static::config_instance('outputMask', $value, $merge);
    }
    /**
     * Add an editor link format
     *
     * Template strings can use the `{file}` and `{line}` placeholders.
     * Closures templates must return a string, and accept two parameters:
     * The file and line.
     *
     * @param string $name The name of the editor.
     * @param \Closure|string $template The string template or closure
     */
    public static function add_editor(string $name, Closure|string $template): void
    {
        $instance = static::get_instance();
        $instance->editors[$name] = $template;
    }
    /**
     * Choose the editor link style you want to use.
     *
     * @param string $name The editor name.
     */
    public static function set_editor(string $name): void
    {
        $instance = static::get_instance();
        if (!isset($instance->editors[$name])) {
            $known = implode(', ', array_keys($instance->editors));
            throw new InvalidArgumentException(sprintf('Unknown editor `%s`. Known editors are `%s`.', $name, $known));
        }
        $instance->set_config('editor', $name);
    }
    /**
     * Get a formatted URL for the active editor.
     *
     * @param string $file The file to create a link for.
     * @param int $line The line number to create a link for.
     * @return string The formatted URL.
     */
    public static function editor_url(string $file, int $line): string
    {
        $instance = static::get_instance();
        $editor = $instance->get_config('editor');
        if (!isset($instance->editors[$editor])) {
            throw new InvalidArgumentException(sprintf('Cannot format editor URL `%s` is not a known editor.', $editor));
        }
        $editor_base_path = $instance->get_config('editorBasePath');
        if ($editor_base_path !== null && is_string($editor_base_path)) {
            $file = str_replace(ROOT, $editor_base_path, $file);
        }
        $template = $instance->editors[$editor];
        if (is_string($template)) {
            return str_replace(['{file}', '{line}'], [$file, (string) $line], $template);
        }
        return $template($file, $line);
    }
    /**
     * Recursively formats and outputs the contents of the supplied variable.
     *
     * @param mixed $var The variable to dump.
     * @param int $maxDepth The depth to output to. Defaults to 3.
     * @see \Cake\Error\Debugger::exportVar()
     * @link https://book.cakephp.org/5/en/development/debugging.html#outputting-values
     */
    public static function dump(mixed $var, int $max_depth = 3): void
    {
        pr(static::export_var($var, $max_depth));
    }
    /**
     * Creates an entry in the log file. The log entry will contain a stack trace from where it was called.
     * as well as export the variable using exportVar. By default, the log is written to the debug log.
     *
     * @param mixed $var Variable or content to log.
     * @param string|int $level Type of log to use. Defaults to 'debug'.
     * @param int $maxDepth The depth to output to. Defaults to 3.
     */
    public static function log(mixed $var, string|int $level = 'debug', int $max_depth = 3): void
    {
        /** @var string $source */
        $source = static::trace(['start' => 1]);
        $source .= "\n";
        Log::write($level, "\n" . $source . static::export_var_as_plain_text($var, $max_depth));
    }
    /**
     * Get the frames from $exception that are not present in $parent
     *
     * @param \Throwable $exception The exception to get frames from.
     * @param \Throwable|null $parent The parent exception to compare frames with.
     * @return array An array of frame structures.
     */
    public static function get_unique_frames(Throwable $exception, ?Throwable $parent): array
    {
        if ($parent === null) {
            return $exception->get_trace();
        }
        $parent_frames = $parent->get_trace();
        $frames = $exception->get_trace();
        $parent_count = count($parent_frames) - 1;
        $frame_count = count($frames) - 1;
        // Reverse loop through both traces removing frames that
        // are the same.
        for ($i = $frame_count, $p = $parent_count; $i >= 0 && $p >= 0; $p--) {
            $parent_tail = $parent_frames[$p];
            $tail = $frames[$i];
            // Frames without file/line are never equal to another frame.
            $is_equal = isset($tail['file']) && isset($tail['line']) && isset($parent_tail['file']) && isset($parent_tail['line']) && $tail['file'] === $parent_tail['file'] && $tail['line'] === $parent_tail['line'];
            if ($is_equal) {
                unset($frames[$i]);
                $i--;
            }
        }
        return $frames;
    }
    /**
     * Outputs a stack trace based on the supplied options.
     *
     * ### Options
     *
     * - `depth` - The number of stack frames to return. Defaults to 999
     * - `format` - The format you want the return. Defaults to the currently selected format. If
     *    format is 'array', 'points', or 'shortPoints' the return will be an array.
     * - `args` - Should arguments for functions be shown? If true, the arguments for each method call
     *   will be displayed.
     * - `start` - The stack frame to start generating a trace from. Defaults to 0
     *
     * @param array<string, mixed> $options Format for outputting stack trace.
     * @return array|string Formatted stack trace.
     * @link https://book.cakephp.org/5/en/development/debugging.html#generating-stack-traces
     */
    public static function trace(array $options = []): array|string
    {
        // Remove the frame for Debugger::trace()
        $backtrace = debug_backtrace();
        array_shift($backtrace);
        return Debugger::format_trace($backtrace, $options);
    }
    /**
     * Formats a stack trace based on the supplied options.
     *
     * ### Options
     *
     * - `depth` - The number of stack frames to return. Defaults to 999
     * - `format` - The format you want the return. Defaults to 'text'. If
     *    format is 'array', 'points', or 'shortPoints' the return will be an array.
     * - `args` - Should arguments for functions be shown? If true, the arguments for each method call
     *   will be displayed.
     * - `start` - The stack frame to start generating a trace from. Defaults to 0
     *
     * @param \Throwable|array $backtrace Trace as array or an exception object.
     * @param array<string, mixed> $options Format for outputting stack trace.
     * @return array|string Formatted stack trace.
     * @link https://book.cakephp.org/5/en/development/debugging.html#generating-stack-traces
     */
    public static function format_trace(Throwable|array $backtrace, array $options = []): array|string
    {
        if ($backtrace instanceof Throwable) {
            $backtrace = $backtrace->get_trace();
        }
        $defaults = ['depth' => 999, 'format' => 'text', 'args' => false, 'start' => 0, 'scope' => null, 'exclude' => ['call_user_func_array', 'trigger_error'], 'shortPath' => false];
        $options = Hash::merge($defaults, $options);
        $count = count($backtrace) + 1;
        $back = [];
        for ($i = $options['start']; $i < $count && $i < $options['depth']; $i++) {
            $frame = ['file' => '[main]', 'line' => ''];
            if (isset($backtrace[$i])) {
                $frame = $backtrace[$i] + ['file' => '[internal]', 'line' => '??'];
            }
            $signature = $frame['file'];
            $reference = $frame['file'];
            if (!empty($frame['class'])) {
                $signature = $frame['class'] . $frame['type'] . $frame['function'];
                $reference = $signature . '(';
                if ($options['args'] && isset($frame['args'])) {
                    $args = [];
                    foreach ($frame['args'] as $arg) {
                        $args[] = Debugger::export_var($arg);
                    }
                    $reference .= implode(', ', $args);
                }
                $reference .= ')';
            }
            if (in_array($signature, $options['exclude'], true)) {
                continue;
            }
            $format = $options['format'];
            if ($format === 'shortPoints') {
                $back[] = ['file' => self::trim_path($frame['file']), 'line' => $frame['line'], 'reference' => $reference];
            } elseif ($format === 'points') {
                $back[] = ['file' => $frame['file'], 'line' => $frame['line'], 'reference' => $reference];
            } elseif ($format === 'array') {
                if (!$options['args']) {
                    unset($frame['args']);
                }
                $back[] = $frame;
            } elseif ($format === 'text') {
                $path = static::trim_path($frame['file']);
                $back[] = sprintf('%s - %s, line %d', $reference, $path, $frame['line']);
            } else {
                throw new InvalidArgumentException("Invalid trace format of `{$format}` chosen. Must be one of `array`, `points` or `text`.");
            }
        }
        if (in_array($options['format'], ['array', 'points', 'shortPoints'])) {
            return $back;
        }
        /**
         * @phpstan-ignore-next-line
         */
        return implode("\n", $back);
    }
    /**
     * Shortens file paths by replacing the application base path with 'APP', and the CakePHP core
     * path with 'CORE'.
     *
     * @param string $path Path to shorten.
     * @return string Normalized path
     */
    public static function trim_path(string $path): string
    {
        if (defined('APP') && str_starts_with($path, APP)) {
            return str_replace(APP, 'APP/', $path);
        }
        if (defined('CAKE_CORE_INCLUDE_PATH') && str_starts_with($path, (string) CAKE_CORE_INCLUDE_PATH)) {
            return str_replace(CAKE_CORE_INCLUDE_PATH, 'CORE', $path);
        }
        if (defined('ROOT') && str_starts_with($path, (string) ROOT)) {
            return str_replace(ROOT, 'ROOT', $path);
        }
        return $path;
    }
    /**
     * Grabs an excerpt from a file and highlights a given line of code.
     *
     * Usage:
     *
     * ```
     * Debugger::excerpt('/path/to/file', 100, 4);
     * ```
     *
     * The above would return an array of 8 items. The 4th item would be the provided line,
     * and would be wrapped in `<span class="code-highlight"></span>`. All the lines
     * are processed with highlight_string() as well, so they have basic PHP syntax highlighting
     * applied.
     *
     * @param string $file Absolute path to a PHP file.
     * @param int $line Line number to highlight.
     * @param int $context Number of lines of context to extract above and below $line.
     * @return array<string> Set of lines highlighted
     * @see https://secure.php.net/highlight_string
     * @link https://book.cakephp.org/5/en/development/debugging.html#getting-an-excerpt-from-a-file
     */
    public static function excerpt(string $file, int $line, int $context = 2): array
    {
        $lines = [];
        if (!file_exists($file)) {
            return [];
        }
        $data = file_get_contents($file);
        if (!$data) {
            return $lines;
        }
        if (str_contains($data, "\n")) {
            $data = explode("\n", $data);
        }
        $line--;
        if (!isset($data[$line])) {
            return $lines;
        }
        for ($i = $line - $context; $i < $line + $context + 1; $i++) {
            if (!isset($data[$i])) {
                continue;
            }
            $string = str_replace(["\r\n", "\n"], '', static::_highlight($data[$i]));
            if ($i === $line) {
                $lines[] = '<span class="code-highlight">' . $string . '</span>';
            } else {
                $lines[] = $string;
            }
        }
        return $lines;
    }
    /**
     * Wraps the highlight_string function in case the server API does not
     * implement the function as it is the case of the HipHop interpreter
     *
     * @param string $str The string to convert.
     */
    protected static function _highlight(string $str): string
    {
        $added = false;
        if (!str_contains($str, '<?php')) {
            $added = true;
            $str = "<?php \n" . $str;
        }
        $highlight = highlight_string($str, true);
        if ($added) {
            return str_replace(['&lt;?php&nbsp;<br/>', '&lt;?php&nbsp;<br />', '&lt;?php '], '', $highlight);
        }
        return $highlight;
    }
    /**
     * Get the configured export formatter or infer one based on the environment.
     *
     * @unstable This method is not stable and may change in the future.
     * @since 4.1.0
     */
    public function get_export_formatter(): Formatter_Interface
    {
        $instance = static::get_instance();
        $class = $instance->get_config('exportFormatter');
        if (!$class) {
            if (Console_Formatter::environment_matches()) {
                $class = Console_Formatter::class;
            } elseif (Html_Formatter::environment_matches()) {
                $class = Html_Formatter::class;
            } else {
                $class = Text_Formatter::class;
            }
        }
        $instance = new $class();
        if (!$instance instanceof Formatter_Interface) {
            throw new Cake_Exception(sprintf('The `%s` formatter does not implement `%s`.', $class, Formatter_Interface::class));
        }
        return $instance;
    }
    /**
     * Converts a variable to a string for debug output.
     *
     * *Note:* The following keys will have their contents
     * replaced with `*****`:
     *
     *  - password
     *  - login
     *  - host
     *  - database
     *  - port
     *  - prefix
     *  - schema
     *
     * This is done to protect database credentials, which could be accidentally
     * shown in an error message if CakePHP is deployed in development mode.
     *
     * @param mixed $var Variable to convert.
     * @param int $maxDepth The depth to output to. Defaults to 3.
     * @return string Variable as a formatted string
     */
    public static function export_var(mixed $var, int $max_depth = 3): string
    {
        $context = new Debug_Context($max_depth);
        $node = static::export($var, $context);
        return static::get_instance()->get_export_formatter()->dump($node);
    }
    /**
     * Converts a variable to a plain text string.
     *
     * @param mixed $var Variable to convert.
     * @param int $maxDepth The depth to output to. Defaults to 3.
     * @return string Variable as a string
     */
    public static function export_var_as_plain_text(mixed $var, int $max_depth = 3): string
    {
        return (new Text_Formatter())->dump(static::export($var, new Debug_Context($max_depth)));
    }
    /**
     * Convert the variable to the internal node tree.
     *
     * The node tree can be manipulated and serialized more easily
     * than many object graphs can.
     *
     * @param mixed $var Variable to convert.
     * @param int $maxDepth The depth to generate nodes to. Defaults to 3.
     * @return \Cake\Error\Debug\NodeInterface The root node of the tree.
     */
    public static function export_var_as_nodes(mixed $var, int $max_depth = 3): Node_Interface
    {
        return static::export($var, new Debug_Context($max_depth));
    }
    /**
     * Protected export function used to keep track of indentation and recursion.
     *
     * @param mixed $var The variable to dump.
     * @param \Cake\Error\Debug\DebugContext $context Dump context
     * @return \Cake\Error\Debug\NodeInterface The dumped variable.
     */
    protected static function export(mixed $var, Debug_Context $context): Node_Interface
    {
        $type = static::get_type($var);
        if (str_starts_with($type, 'resource ')) {
            return new Scalar_Node($type, $var);
        }
        return match ($type) {
            'float', 'string', 'null' => new Scalar_Node($type, $var),
            'bool' => new Scalar_Node('bool', $var),
            'int' => new Scalar_Node('int', $var),
            'array' => static::export_array($var, $context->with_added_depth()),
            'unknown' => new Special_Node('(unknown)'),
            default => static::export_object($var, $context->with_added_depth()),
        };
    }
    /**
     * Export an array type object. Filters out keys used in datasource configuration.
     *
     * The following keys are replaced with ***'s
     *
     * - password
     * - login
     * - host
     * - database
     * - port
     * - prefix
     * - schema
     *
     * @param array $var The array to export.
     * @param \Cake\Error\Debug\DebugContext $context The current dump context.
     * @return \Cake\Error\Debug\ArrayNode Exported array.
     */
    protected static function export_array(array $var, Debug_Context $context): Array_Node
    {
        $items = [];
        $remaining = $context->remaining_depth();
        if ($remaining >= 0) {
            $output_mask = static::output_mask();
            foreach ($var as $key => $val) {
                if (array_key_exists($key, $output_mask)) {
                    $node = new Scalar_Node('string', $output_mask[$key]);
                } elseif ($val !== $var) {
                    // Dump all the items without increasing depth.
                    $node = static::export($val, $context);
                } else {
                    // Likely recursion, so we increase depth.
                    $node = static::export($val, $context->with_added_depth());
                }
                $items[] = new Array_Item_Node(static::export($key, $context), $node);
            }
        } else {
            $items[] = new Array_Item_Node(new Scalar_Node('string', ''), new Special_Node('[maximum depth reached]'));
        }
        return new Array_Node($items);
    }
    /**
     * Handles object to node conversion.
     *
     * @param object $var Object to convert.
     * @param \Cake\Error\Debug\DebugContext $context The dump context.
     * @see \Cake\Error\Debugger::exportVar()
     */
    protected static function export_object(object $var, Debug_Context $context): Node_Interface
    {
        $is_ref = $context->has_reference($var);
        $ref_num = $context->get_reference_id($var);
        $class_name = $var::class;
        if ($is_ref) {
            return new Reference_Node($class_name, $ref_num);
        }
        $node = new Class_Node($class_name, $ref_num);
        $remaining = $context->remaining_depth();
        if ($remaining > 0) {
            if (method_exists($var, '__debugInfo')) {
                try {
                    foreach ((array) $var->__debugInfo() as $key => $val) {
                        $node->add_property(new Property_Node("'{$key}'", null, static::export($val, $context)));
                    }
                    return $node;
                } catch (Exception $e) {
                    return new Special_Node("(unable to export object: {$e->get_message()})");
                }
            }
            $output_mask = static::output_mask();
            $object_vars = get_object_vars($var);
            foreach ($object_vars as $key => $value) {
                if (array_key_exists($key, $output_mask)) {
                    $value = $output_mask[$key];
                }
                $node->add_property(new Property_Node((string) $key, 'public', static::export($value, $context->with_added_depth())));
            }
            $ref = new Reflection_Object($var);
            $filters = [ReflectionProperty::IS_PROTECTED => 'protected', ReflectionProperty::IS_PRIVATE => 'private'];
            foreach ($filters as $filter => $visibility) {
                $reflection_properties = $ref->get_properties($filter);
                foreach ($reflection_properties as $reflection_property) {
                    if (method_exists($reflection_property, 'isInitialized') && !$reflection_property->is_initialized($var)) {
                        $value = new Special_Node('[uninitialized]');
                    } else {
                        $value = static::export($reflection_property->get_value($var), $context->with_added_depth());
                    }
                    $node->add_property(new Property_Node($reflection_property->get_name(), $visibility, $value));
                }
            }
        }
        return $node;
    }
    /**
     * Get the type of the given variable. Will return the class name
     * for objects.
     *
     * @param mixed $var The variable to get the type of.
     * @return string The type of variable.
     */
    public static function get_type(mixed $var): string
    {
        $type = get_debug_type($var);
        if ($type === 'double') {
            return 'float';
        }
        if ($type === 'unknown type') {
            return 'unknown';
        }
        return $type;
    }
    /**
     * Prints out debug information about given variable.
     *
     * @param mixed $var Variable to show debug information for.
     * @param array $location If contains keys "file" and "line" their values will
     *    be used to show location info.
     * @param bool|null $showHtml If set to true, the method prints the debug
     *    data encoded as HTML. If false, plain text formatting will be used.
     *    If null, the format will be chosen based on the configured exportFormatter, or
     *    environment conditions.
     */
    public static function print_var(mixed $var, array $location = [], ?bool $show_html = null): void
    {
        $location += ['file' => null, 'line' => null];
        if ($location['file']) {
            $location['file'] = static::trim_path((string) $location['file']);
        }
        $debugger = static::get_instance();
        $restore = null;
        if ($show_html !== null) {
            $restore = $debugger->get_config('exportFormatter');
            $debugger->set_config('exportFormatter', $show_html ? Html_Formatter::class : Text_Formatter::class);
        }
        $contents = static::export_var($var, 25);
        $formatter = $debugger->get_export_formatter();
        if ($restore) {
            $debugger->set_config('exportFormatter', $restore);
        }
        echo $formatter->format_wrapper($contents, $location);
    }
    /**
     * Format an exception message to be HTML formatted.
     *
     * Does the following formatting operations:
     *
     * - HTML escape the message.
     * - Convert `bool` into `<code>bool</code>`
     * - Convert newlines into `<br>`
     *
     * @param string $message The string message to format.
     * @return string Formatted message.
     */
    public static function format_html_message(string $message): string
    {
        $message = h($message);
        $message = (string) preg_replace('/`([^`]+)`/', '<code>$0</code>', (string) $message);
        return nl2br($message);
    }
    /**
     * Verifies that the application's salt and cipher seed value has been changed from the default value.
     */
    public static function check_security_keys(): void
    {
        $salt = Security::get_salt();
        if ($salt === '__SALT__' || strlen($salt) < 32) {
            trigger_error('Please change the value of `Security.salt` in `ROOT/config/app_local.php` ' . 'to a random value of at least 32 characters.', E_USER_NOTICE);
        }
    }
}