<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/bootstrap.php';
require_once dirname(__DIR__).'/includes/helpers.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nAllow: /\nSitemap: ".roomshift_base_url()."/sitemap.xml\n";
