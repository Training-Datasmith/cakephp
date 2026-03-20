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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\I18n;

use Cake\Core\App;
use Cake\Core\Exception\Cake_Exception;
use Cake\Core\Plugin;
use function Cake\Core\Plugin_Split;
use Cake\Utility\Inflector;
use Locale;
/**
 * A generic translations package factory that will load translations files
 * based on the file extension and the package name.
 *
 * This class is a callable, so it can be used as a package loader argument.
 */
class Messages_File_Loader
{
    /**
     * The package (domain) plugin
     */
    protected ?string $_plugin = null;
    /**
     * Creates a translation file loader. The file to be loaded corresponds to
     * the following rules:
     *
     * - The locale is a folder under the `resources/locales/` directory, a fallback will be
     *   used if the folder is not found.
     * - The $name corresponds to the file name to load
     * - If there is a loaded plugin with the underscored version of $name, the
     *   translation file will be loaded from such plugin.
     *
     * ### Examples:
     *
     * Load and parse resources/locales/fr/validation.po
     *
     * ```
     * $loader = new MessagesFileLoader('validation', 'fr_FR', 'po');
     * $package = $loader();
     * ```
     *
     * Load and parse resources/locales/fr_FR/validation.mo
     *
     * ```
     * $loader = new MessagesFileLoader('validation', 'fr_FR', 'mo');
     * $package = $loader();
     * ```
     *
     * Load the plugins/MyPlugin/resources/locales/fr/my_plugin.po file:
     *
     * ```
     * $loader = new MessagesFileLoader('my_plugin', 'fr_FR', 'mo');
     * $package = $loader();
     *
     * Vendor prefixed plugins are expected to use `my_prefix_my_plugin` syntax.
     * ```
     *
     * @param string $_name The name (domain) of the translations package.
     * @param string $_locale The locale to load, this will be mapped to a folder
     * in the system.
     * @param string $_extension The file extension to use. This will also be mapped
     * to a messages parser class.
     */
    public function __construct(
        /**
         * The package (domain) name.
         */
        protected string $_name,
        /**
         * The locale to load for the given package.
         */
        protected string $_locale,
        /**
         * The extension name.
         */
        protected string $_extension = 'po'
    )
    {
        // If space is not added after slash, the character after it remains lowercased
        $plugin_name = Inflector::camelize(str_replace('/', '/ ', $this->_name));
        if (strpos($this->_name, '.')) {
            [$this->_plugin, $this->_name] = plugin_split($plugin_name);
        } elseif (Plugin::is_loaded($plugin_name)) {
            $this->_plugin = $plugin_name;
        }
    }
    /**
     * Loads the translation file and parses it. Returns an instance of a translations
     * package containing the messages loaded from the file.
     *
     * @return \Cake\I18n\Package|false
     * @throws \Cake\Core\Exception\CakeException if no file parser class could be found for the specified
     * file extension.
     */
    public function __invoke(): Package|false
    {
        $folders = $this->translations_folders();
        $file = $this->translation_file($folders, $this->_name, $this->_extension);
        if (!$file) {
            return false;
        }
        $name = ucfirst($this->_extension);
        $class = App::class_name($name, 'I18n\Parser', 'FileParser');
        if (!$class) {
            throw new Cake_Exception(sprintf('Could not find class `%s`.', "{$name}FileParser"));
        }
        /** @var \Cake\I18n\Parser\MoFileParser|\Cake\I18n\Parser\PoFileParser $object */
        $object = new $class();
        $messages = $object->parse($file);
        $package = new Package('default');
        $package->set_messages($messages);
        return $package;
    }
    /**
     * Returns the folders where the file should be looked for according to the locale
     * and package name.
     *
     * @return array<string> The list of folders where the translation file should be looked for
     */
    public function translations_folders(): array
    {
        $locale = Locale::parse_locale($this->_locale) + ['region' => null];
        $folders = [
            $locale['language'],
            // gettext compatible paths, see https://www.php.net/manual/en/function.gettext.php
            $locale['language'] . DIRECTORY_SEPARATOR . 'LC_MESSAGES',
        ];
        if ($locale['region']) {
            $language_region = implode('_', [$locale['language'], $locale['region']]);
            $folders[] = $language_region;
            // gettext compatible paths, see https://www.php.net/manual/en/function.gettext.php
            $folders[] = $language_region . DIRECTORY_SEPARATOR . 'LC_MESSAGES';
        }
        $search_paths = [];
        $locale_paths = App::path('locales');
        if (!$locale_paths && defined('ROOT')) {
            $locale_paths[] = ROOT . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR;
        }
        if ($this->_plugin && Plugin::is_loaded($this->_plugin)) {
            $locale_paths[] = App::path('locales', $this->_plugin)[0];
        }
        foreach ($locale_paths as $path) {
            foreach ($folders as $folder) {
                $search_paths[] = $path . $folder . DIRECTORY_SEPARATOR;
            }
        }
        return $search_paths;
    }
    /**
     * @param array<string> $folders Folders
     * @param string $name File name
     * @param string $ext File extension
     * @return string|null File if found
     */
    protected function translation_file(array $folders, string $name, string $ext): ?string
    {
        $file = null;
        $name = str_replace('/', '_', $name);
        foreach ($folders as $folder) {
            $path = "{$folder}{$name}.{$ext}";
            if (is_file($path)) {
                $file = $path;
                break;
            }
        }
        return $file;
    }
}