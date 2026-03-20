<?php

declare (strict_types=1);
use function Cake\Core\Deprecation_Warning;
use Cake\I18n\Date;
deprecation_warning('5.0.0', 'Cake\I18n\FrozenDate is deprecated. Use Cake\I18n\Date instead');
class_exists(Date::class);