<?php

declare(strict_types=1);
$this->start('non closing block');
throw new \Exception('Exception with open buffers');
