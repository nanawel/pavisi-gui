<?php
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/vendor/autoload.php';

define('PAGE_SIZE', getenv('PAVISIGUI_PAGE_SIZE') ?: 50);

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
        \Psr\Http\Message\ResponseInterface $response,
        $args
    ) use ($config) {
        $view = Twig::fromRequest($request);

        $query = $request->getQueryParams()['query'] ?? '';
        $page = max(1, $request->getQueryParams()['page'] ?? 1);

        $client = \Elasticsearch\ClientBuilder::create()
            ->setHosts([$config['elasticsearch']['url']])
            ->build();

        try {
            $results = $client->search([
                'index' => $config['elasticsearch']['index'],
                'body'  => [
                    'from' => ($page - 1) * PAGE_SIZE,
                    'size' => PAGE_SIZE,
                    'query' => [
                        'query_string' => [
                            'query' => $query,
                            // Search into "filepath" and "text" but boost filepath x5
                            'fields' => ['filepath^5', 'text']
                        ]
                    ],
                    '_source' => false,
                    'fields' => [
                        'filepath',
                        'filemtime',
                        'filesize',
                    ]
                ]
            ]);
            $processedResults = [];
            foreach ($results['hits']['hits'] as $hit) {
                $processedResults[] = [
                    'id' => $hit['_id'],
                    'file' => $hit['fields']['filepath'][0],
                    'filepath' => $hit['fields']['filepath'][0],
                    'filemtime' => $hit['fields']['filemtime'][0],
                    'filesize' => $hit['fields']['filesize'][0],
                    'score' => round($hit['_score'], 2)
                ];
            }

            $hitsTotal = $results['hits']['total']['value'] ?? 0;
            $lastPage = (int) ($hitsTotal / PAGE_SIZE) + 1;
            $intermediatePages = [];
            for ($i = max($page - 1, 2); $i <= min($page + 1, $lastPage - 1); $i++) {
                $intermediatePages[] = $i;
            }
            $secondPage = current($intermediatePages);
            end($intermediatePages);
            $secondToLastPage = current($intermediatePages);
            reset($intermediatePages);

            return $view->render($response, 'search.html', [
                'query' => $query,
                'hits_total' => $hitsTotal,
                'hits_shown' => count($results['hits']['hits']),
                'hits' => $processedResults,

                // Pagination
                'currentPage' => $page,
                'previousPage' => $page > 1 ? $page - 1 : null,
                'nextPage' => ($page * PAGE_SIZE) < $hitsTotal ? $page + 1 : null,
                'lastPage' => $lastPage,
                'secondPage' => $secondPage,
                'intermediatePages' => $intermediatePages,
                'secondToLastPage' => $secondToLastPage,

                // Config
                '_config' => $config
            ]);
        } catch (\Throwable $e) {
            return $view->render($response, 'search.html', [
                'query' => $query,
                'error' => [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                '_config' => $config
            ]);
        }
    })
    ->setName('search')
;

$app->run();
