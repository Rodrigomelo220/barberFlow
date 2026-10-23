<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/scheduling.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/view.php';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
