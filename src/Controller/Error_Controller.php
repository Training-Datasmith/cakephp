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
 * @since         2.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller;

use Cake\Event\Event_Interface;
use Cake\View\Json_View;
/**
 * Error Handling Controller
 *
 * Controller used by ErrorHandler to render error views.
 */
class Error_Controller extends Controller
{
    /**
     * Get alternate view classes that can be used in
     * content-type negotiation.
     *
     * @return array<string>
     */
    public function view_classes(): array
    {
        return [Json_View::class];
    }
    /**
     * beforeRender callback.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     */
    public function before_render(Event_Interface $event): void
    {
        $builder = $this->view_builder();
        $template_path = 'Error';
        if ($this->request->get_param('prefix') && in_array($builder->get_template(), ['error400', 'error500'], true)) {
            $parts = explode(DIRECTORY_SEPARATOR, (string) $builder->get_template_path(), -1);
            $template_path = implode(DIRECTORY_SEPARATOR, $parts) . DIRECTORY_SEPARATOR . 'Error';
        }
        $builder->set_template_path($template_path);
    }
}