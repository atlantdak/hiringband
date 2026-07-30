<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'src/Config.php',
    'src/CreateDraftHandler.php',
    'src/Controller/CliController.php',
    'src/Controller/HttpController.php',
    'views/form.php',
] as $file) {
    $expect(is_file($root . '/' . $file), sprintf('%s must exist', $file));
}

foreach (['src/Controller/CliController.php', 'src/Controller/HttpController.php'] as $controllerFile) {
    $controller = is_file($root . '/' . $controllerFile)
        ? (string) file_get_contents($root . '/' . $controllerFile)
        : '';
    $expect(!str_contains($controller, 'new CreateDraftRequest'), $controllerFile . ' must delegate request creation');
}

$index = (string) file_get_contents($root . '/index.php');
$expect(!str_contains($index, '<!doctype html>'), 'index.php must not contain HTML');
$expect(!str_contains($index, '$_GET'), 'index.php must not read credentials from GET');

$viewPath = $root . '/views/form.php';
if (is_file($viewPath)) {
    $view = (string) file_get_contents($viewPath);
    $expect(str_contains($view, 'type="password"'), 'view must use a password input');
    $expect(!preg_match('/type="password"[^>]*\bvalue=/i', $view), 'password input must not have a value');
    $expect(str_contains($view, 'action="<?= $escape($formAction) ?>"'), 'form action must be escaped and query-free');
}

require $root . '/vendor/autoload.php';

use App\Config;
use App\Controller\CliController;
use App\Controller\HttpController;
use App\CreateDraftHandler;
use App\CreateDraftRequest;
use App\RestEndpointResolver;
use App\WordPressDraftCreator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$history = [];
$mock = new MockHandler([
    new Response(200, [], json_encode(['routes' => [Config::POSTS_ROUTE => []]], JSON_THROW_ON_ERROR)),
    new Response(201, [], json_encode(['id' => 123], JSON_THROW_ON_ERROR)),
]);
$client = new Client([
    'handler' => \GuzzleHttp\Middleware::history($history)(HandlerStack::create($mock)),
]);
$creator = new WordPressDraftCreator($client, new RestEndpointResolver($client));
$result = $creator->create(new CreateDraftRequest('https://example.com/', 'admin', 'literal-password'));
$expect($result->success && $result->postId === 123, 'creator must return the post ID');
$expect(count($history) === 2, 'creator must issue one discovery GET and one POST');
if (isset($history[1]['request'])) {
    $authorization = $history[1]['request']->getHeaderLine('Authorization');
    $expect(
        $authorization === 'Basic ' . base64_encode('admin:literal-password'),
        'password must be sent unchanged through HTTP Basic Authentication',
    );
}

$makeCreator = static function (array $queue, array &$requests): WordPressDraftCreator {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($requests));
    $mockClient = new Client(['handler' => $stack]);
    return new WordPressDraftCreator($mockClient, new RestEndpointResolver($mockClient));
};

$plainHistory = [];
$plainCreator = $makeCreator([
    new Response(404),
    new Response(200, [], json_encode(['routes' => [Config::POSTS_ROUTE => []]], JSON_THROW_ON_ERROR)),
    new Response(201, [], '{"id":321}'),
], $plainHistory);
$plainResult = $plainCreator->create(new CreateDraftRequest('https://example.com', 'admin', 'password'));
$expect($plainResult->success && $plainResult->postId === 321, 'plain permalink discovery must create a draft');
$expect(
    isset($plainHistory[2]['request'])
    && (string) $plainHistory[2]['request']->getUri() === 'https://example.com/?rest_route=/wp/v2/posts',
    'plain permalink POST endpoint must use rest_route',
);

foreach ([
    401 => ['authentication', 'Authentication failed.'],
    403 => ['permission', 'The user is not allowed'],
    404 => ['upstream', 'posts endpoint was not found'],
    500 => ['upstream', 'internal error'],
] as $status => [$expectedType, $messagePart]) {
    $errorHistory = [];
    $errorCreator = $makeCreator([
        new Response(200, [], json_encode(['routes' => [Config::POSTS_ROUTE => []]], JSON_THROW_ON_ERROR)),
        new Response($status, [], json_encode([
            'code' => 'wp_error_' . $status,
            'message' => 'Safe WordPress reason.',
        ], JSON_THROW_ON_ERROR)),
    ], $errorHistory);
    $errorResult = $errorCreator->create(new CreateDraftRequest('https://example.com', 'admin', 'password'));
    $expect(
        !$errorResult->success
        && $errorResult->errorType === $expectedType
        && $errorResult->httpStatus === $status
        && $errorResult->wordpressCode === 'wp_error_' . $status
        && str_contains($errorResult->message, $messagePart)
        && str_contains($errorResult->message, 'Safe WordPress reason.'),
        sprintf('HTTP %d must be normalized safely', $status),
    );
}

$request = new Request('GET', 'https://example.com');
foreach ([
    'network' => [
        new ConnectException('network', $request),
        'network',
        'Unable to connect',
    ],
    'tls' => [
        new RequestException('tls', $request, null, null, ['errno' => CURLE_SSL_CONNECT_ERROR]),
        'network',
        'secure connection',
    ],
    'timeout' => [
        new RequestException('timeout', $request, null, null, ['errno' => CURLE_OPERATION_TIMEDOUT]),
        'timeout',
        'Request timed out.',
    ],
] as $case => [$exception, $expectedType, $messagePart]) {
    $discoveryHistory = [];
    $discoveryCreator = $makeCreator([$exception, $exception], $discoveryHistory);
    $discoveryResult = $discoveryCreator->create(
        new CreateDraftRequest('https://example.com', 'admin', 'password'),
    );
    $expect(
        !$discoveryResult->success
        && $discoveryResult->errorType === $expectedType
        && str_contains($discoveryResult->message, $messagePart)
        && count($discoveryHistory) === 2,
        $case . ' discovery failure must be classified after both candidates',
    );
}

$postTimeoutHistory = [];
$postTimeout = new RequestException(
    'timeout',
    new Request('POST', 'https://example.com/wp-json/wp/v2/posts'),
    null,
    null,
    ['errno' => CURLE_OPERATION_TIMEDOUT],
);
$postTimeoutCreator = $makeCreator([
    new Response(200, [], json_encode(['routes' => [Config::POSTS_ROUTE => []]], JSON_THROW_ON_ERROR)),
    $postTimeout,
], $postTimeoutHistory);
$postTimeoutResult = $postTimeoutCreator->create(
    new CreateDraftRequest('https://example.com', 'admin', 'password'),
);
$expect(
    !$postTimeoutResult->success
    && $postTimeoutResult->errorType === 'timeout'
    && str_contains($postTimeoutResult->message, 'may have processed it')
    && str_contains($postTimeoutResult->message, 'was not retried')
    && count($postTimeoutHistory) === 2,
    'final POST timeout must not be retried and must explain ambiguity',
);

$cliController = new CliController(new CreateDraftHandler($creator));
$passwordMethod = new ReflectionMethod($cliController, 'password');
putenv('WP_PASSWORD=environment-secret');
$resolvedPassword = $passwordMethod->invoke($cliController, ['password' => 'argument-secret']);
putenv('WP_PASSWORD');
$expect($resolvedPassword === 'argument-secret', '--password must take priority over WP_PASSWORD');

$siteUrlCases = [
    ['https://example.com', true],
    ['http://example.local:8080/wordpress/', true],
    ['https://example.com/wp-json/', false],
    ['https://example.com/wp-json/wp/v2/posts', false],
    ['https://example.com/?rest_route=/wp/v2/posts', false],
];
foreach ($siteUrlCases as [$siteUrl, $shouldBeValid]) {
    try {
        CreateDraftRequest::validateSiteUrl($siteUrl);
        $isValid = true;
    } catch (InvalidArgumentException $exception) {
        $isValid = false;
        if (!$shouldBeValid) {
            $expect(
                str_contains($exception->getMessage(), 'installation root'),
                'REST endpoint input must explain that the installation root URL is required',
            );
        }
    }
    $expect($isValid === $shouldBeValid, 'site URL classification failed: ' . $siteUrl);
}

$command = sprintf(
    '%s %s --site=invalid --username=test --password=%s 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/index.php'),
    escapeshellarg('argument-secret'),
);
exec($command, $cliOutput, $cliStatus);
$expect($cliStatus === 1, 'invalid CLI request must fail');
$expect(!str_contains(implode("\n", $cliOutput), 'argument-secret'), 'CLI output must not expose passwords');

$helpCommand = sprintf('%s %s --help', escapeshellarg(PHP_BINARY), escapeshellarg($root . '/index.php'));
exec($helpCommand, $helpOutput, $helpStatus);
$helpText = implode("\n", $helpOutput);
$expect($helpStatus === 0, 'CLI help must succeed');
$expect(
    str_contains($helpText, 'WordPress installation root URL')
    && str_contains($helpText, 'Do not pass'),
    'CLI help must distinguish the site URL from a REST endpoint',
);

$cliValidationCases = [
    [[], 'Site URL is required.'],
    [['--site=https://example.com'], 'Username is required.'],
    [[
        '--site=https://example.com',
        '--username=admin',
    ], 'WordPress Application Password is required.'],
];
foreach ($cliValidationCases as [$arguments, $expectedMessage]) {
    $validationCommand = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/index.php'),
        implode(' ', array_map('escapeshellarg', $arguments)),
    );
    exec($validationCommand, $validationOutput, $validationStatus);
    $expect(
        $validationStatus === 1 && str_contains(implode("\n", $validationOutput), $expectedMessage),
        'CLI validation must report errors in site, username, password order: ' . $expectedMessage,
    );
    $validationOutput = [];
}

foreach (['//attacker.example/collect', '/\\attacker.example/collect'] as $hostileScriptName) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = $hostileScriptName;
    $_GET = [
        'site' => 'https://attacker.example',
        'username' => 'query-user',
        'password' => 'query-secret',
    ];
    ob_start();
    (new HttpController(new CreateDraftHandler($creator), $viewPath))->run();
    $hostileHtml = (string) ob_get_clean();
    $expect(str_contains($hostileHtml, 'action="/index.php"'), 'hostile SCRIPT_NAME must use safe form action');
    $expect(!str_contains($hostileHtml, 'attacker.example'), 'hostile SCRIPT_NAME and GET site must not be rendered');
    $expect(!str_contains($hostileHtml, 'query-user'), 'GET username must not be consumed');
    $expect(!str_contains($hostileHtml, 'query-secret'), 'GET password must not be consumed');
}

if ($failures !== []) {
    fwrite(STDERR, "Smoke checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Smoke checks passed.\n");
