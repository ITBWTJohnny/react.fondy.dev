<?php

require __DIR__ . '/../vendor/autoload.php';

use React\Http\Response;

define('__ROOT__', dirname(__DIR__));
define('__VIEWS__', __ROOT__ . '/src/views');

$dotenv = Dotenv\Dotenv::createImmutable(__ROOT__);
$dotenv->load();

$loop = React\EventLoop\Factory::create();
$client = new React\HttpClient\Client($loop);
$paymentSignature = new \App\PaymentSignature();

$main = function (Psr\Http\Message\ServerRequestInterface $request) {
    return new Response(
        200,
        array('Content-Type' => 'text/html'),
        'Hello world'
    );
};


$paymentRedirect = function (Psr\Http\Message\ServerRequestInterface $request) use ($client, $paymentSignature) {
    $params = [
        'merchant_id' => getenv('FONDY_MERCHANT_ID'),
        'order_id' => random_int(10000, 999999),
        'order_desc' => 'Test order',
        'amount' => 3333,
        'currency' => 'UAH',
        'response_url' => '176.32.13.135/payment-redirect',
    ];

    try {
        $params['signature'] = $paymentSignature->getSignature(getenv('FONDY_MERCHANT_PASSWORD'), $params);
        var_dump($params);
    } catch (InvalidArgumentException $e) {
        return new Response(
            400,
            array('Content-Type' => 'text/html'),
            $e->getMessage()
        );
    }

    $deffered = new \React\Promise\Deferred();

    $fondyRequestParams['request'] = $params;
    $fondyRequestBody = json_encode($fondyRequestParams);
    $fondyRequest = $client->request('POST', getenv('FONDY_REQUEST_URL'), [
        'Content-Type' => 'application/json',
        'Content-Length' => strlen($fondyRequestBody),
    ]);

    $fondyRequest->on('response', function ($response) use ($deffered) {
        $response->on('data', function ($chunk) use ($deffered) {
            $fondyData = json_decode($chunk, true);
            var_dump('chunk', $chunk);
            if (!empty($fondyData['response']['response_status']) && $fondyData['response']['response_status'] === 'success') {
                $deffered->resolve(new Response(301, [
                    'Location' => $fondyData['response']['checkout_url'],
                ]));
            } else {
                $deffered->resolve(new Response(200, [
                        'Content-Type' => 'text/html'
                    ],
                    (string)$chunk
                ));
            }
        });
    });
    $fondyRequest->on('error', function (\Exception $e) use ($deffered) {
        $deffered->reject(new Response(200, [
                'Content-Type' => 'text/html',
            ],
            $e->getMessage()
        ));
    });
    $fondyRequest->end($fondyRequestBody);

    return $deffered->promise();
};

$requestFromFondy = function (Psr\Http\Message\ServerRequestInterface $request) {
    $body = (string)$request->getBody();
    var_dump('redirect body', $body);
    return new React\Http\Response(
        200,
        array('Content-Type' => 'text/html'),
        $body
    );
};

$dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $routes) use ($main, $paymentRedirect, $requestFromFondy) {
    $routes->addRoute('GET', '/', $main);
    $routes->addRoute('GET', '/payment', $paymentRedirect);
    $routes->addRoute('POST', '/payment-redirect', $requestFromFondy);
});

$server = new React\Http\Server(function (\Psr\Http\Message\ServerRequestInterface $request) use ($dispatcher) {
    $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

    switch ($routeInfo[0]) {
        case FastRoute\Dispatcher::NOT_FOUND:
            return new Response(404, ['Content-Type' => 'text/plain'],  'Not found');
        case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            return new Response(405, ['Content-Type' => 'text/plain'], 'Method not allowed');
        case FastRoute\Dispatcher::FOUND:
            return $routeInfo[1]($request);
    }

    throw new LogicException('Something went wrong in routing.');
});

$socket = new React\Socket\Server('0.0.0.0:8080', $loop);
$server->listen($socket);

$server->on('error', function ($e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;

    if ($e->getPrevious() !== null) {
        echo 'Error: ' . $e->getPrevious()->getMessage() . PHP_EOL;
    }
});

echo "Server running at http://127.0.0.1:8080\n";

$loop->run();