<?php

declare(strict_types=1);

use App\Controller\CliController;
use App\Controller\HttpController;
use App\CreateDraftHandler;
use App\RestEndpointResolver;
use App\WordPressDraftCreator;
use GuzzleHttp\Client;

require __DIR__ . '/vendor/autoload.php';

$client = new Client();
$creator = new WordPressDraftCreator($client, new RestEndpointResolver($client));
$handler = new CreateDraftHandler($creator);

if (PHP_SAPI === 'cli') {
    exit((new CliController($handler))->run());
}

(new HttpController($handler, __DIR__ . '/views/form.php'))->run();
