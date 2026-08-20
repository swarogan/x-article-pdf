<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(new XArticlePdf\App(dirname(__DIR__)))->run();
