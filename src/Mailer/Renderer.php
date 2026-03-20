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
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Mailer;

use function Cake\Core\Plugin_Split;
use Cake\View\View;
use Cake\View\View_Vars_Trait;
/**
 * Class for rendering email message.
 */
class Renderer
{
    use View_Vars_Trait;
    /**
     * Constant for folder name containing email templates.
     *
     * @var string
     */
    public const TEMPLATE_FOLDER = 'email';
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->reset();
    }
    /**
     * Render text/HTML content.
     *
     * If there is no template set, the $content will be returned in a hash
     * of the specified content types for the email.
     *
     * @param string $content The content.
     * @param array<string> $types Content types to render. Valid array values are {@link Message::MESSAGE_HTML}, {@link Message::MESSAGE_TEXT}.
     * @return array<string, string> The rendered content with "html" and/or "text" keys.
     * @phpstan-param array<\Cake\Mailer\Message::MESSAGE_HTML|\Cake\Mailer\Message::MESSAGE_TEXT> $types
     * @phpstan-return array{html?: string, text?: string}
     */
    public function render(string $content, array $types = []): array
    {
        $rendered = [];
        $template = $this->view_builder()->get_template();
        if (!$template) {
            foreach ($types as $type) {
                $rendered[$type] = $content;
            }
            return $rendered;
        }
        $view = $this->create_view();
        [$template_plugin] = plugin_split($view->get_template());
        [$layout_plugin] = plugin_split($view->get_layout());
        if ($template_plugin) {
            $view->set_plugin($template_plugin);
        } elseif ($layout_plugin) {
            $view->set_plugin($layout_plugin);
        }
        if ($view->get('content') === null) {
            $view->set('content', $content);
        }
        foreach ($types as $type) {
            $view->set_template_path(static::TEMPLATE_FOLDER . DIRECTORY_SEPARATOR . $type);
            $view->set_layout_path(static::TEMPLATE_FOLDER . DIRECTORY_SEPARATOR . $type);
            $rendered[$type] = $view->render();
        }
        return $rendered;
    }
    /**
     * Reset view builder to defaults.
     *
     * @return $this
     */
    public function reset(): static
    {
        $this->_view_builder = null;
        $this->view_builder()->set_class_name(View::class)->set_layout('default')->set_helpers(['Html']);
        return $this;
    }
    /**
     * Clone ViewBuilder instance when renderer is cloned.
     */
    public function __clone()
    {
        if ($this->_view_builder !== null) {
            $this->_view_builder = clone $this->_view_builder;
        }
    }
}