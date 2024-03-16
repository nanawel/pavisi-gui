<?php
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/vendor/autoload.php';

// Assets handling with PHP embedded server
if (PHP_SAPI === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . $url['path'];
    if (str_starts_with(realpath($file), realpath('./assets'))) {
        if (is_file($file)) {
            return false;
        }
    } elseif (str_starts_with($url['path'], '/assets/')) {
        http_response_code(404);
        die('Not found');
    }
}

if (is_file('./config.yml')) {
    $config = \Symfony\Component\Yaml\Yaml::parseFile('./config.yml');
} else {
    $config = \Symfony\Component\Yaml\Yaml::parseFile('./config.yml.dist');
}

$app = AppFactory::create();

$twig = Twig::create('./templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig));

$app->get('/', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'index.html');
})->setName('index');

$app->get(
    '/search',
    function (
        \Psr\Http\Message\RequestInterface $request,
        \Psr\Http\Message\ResponseInterface$response,
        $args
    ) use ($config) {
        $view = Twig::fromRequest($request);

        $query = $request->getQueryParams()['query'] ?? '';

        $client = \Elasticsearch\ClientBuilder::create()
            ->setHosts([$config['elasticsearch']['url']])
            ->build();
        $results = $client->search([
            'index' => $config['elasticsearch']['index'],
            'body'  => [
                'size' => '50',
                'query' => [
                    'query_string' => [
                        'query' => $query,
                        'default_field' => 'text'
                    ]
                ],
                '_source' => false,
                'fields' => ['filepath']
            ]
        ]);

        $processedResults = [];
        foreach ($results['hits']['hits'] as $hit) {
            $processedResults[] = [
                'file' => $hit['filepath'],
                'score' => round($hit['_score'], 2)
            ];
        }

        return $view->render($response, 'search.html', [
            'query' => $query,
            'hits_total' => $results['hits']['total']['value'] ?? 0,
            'hits_shown' => count($results['hits']['hits']),
            'hits' => $processedResults,
            '_config' => $config
        ]);
    })
    ->setName('search')
;

$app->run();
