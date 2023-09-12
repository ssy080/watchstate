<?php

declare(strict_types=1);

namespace Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\PlexClient;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GetLibrariesListTest extends TestCase
{
    protected TestHandler|null $handler = null;
    protected LoggerInterface|null $logger = null;

    public function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler], [new LogMessageProcessor()]);

        $this->context = new Context(
            clientName: PlexClient::CLIENT_NAME,
            backendName: PlexClient::CLIENT_NAME,
            backendUrl: new Uri('http://plex-test.example.com'),
            cache: new Cache($this->logger, new Psr16Cache(new ArrayAdapter())),
            backendId: 'za3g3543d0d637b4f9144099965f8f785cdf9f26',
            backendToken: 'fake-plex-token',
            backendUser: 1,
        );
    }

    public function test_correct_response(): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/plex_data.json'), true);

        $json = ag($data, 'sections_get_200');

        $resp = new MockResponse(json_encode(ag($json, 'response.body')), [
            'http_code' => (int)ag($json, 'response.http_code'),
            'response_headers' => ag($json, 'response.headers', [])
        ]);

        $client = new MockHttpClient($resp);
        $list = new GetLibrariesList($client, $this->logger);
        $response = $list($this->context);

        $this->assertTrue($response->status);
        $this->assertNull($response->error);
        $this->assertIsArray($response->response);
        $this->assertCount(3, $response->response);

        $expected = [];

        foreach (ag($json, 'response.body.MediaContainer.Directory', []) as $item) {
            $key = (int)ag($item, 'key');
            $type = ag($item, 'type', 'unknown');
            $agent = ag($item, 'agent', 'unknown');
            $supportedType = PlexClient::TYPE_MOVIE === $type || PlexClient::TYPE_SHOW === $type;

            $expected[$key] = [
                'id' => $key,
                'title' => ag($item, 'title'),
                'type' => ucfirst($type),
                'ignored' => false,
                'supported' => $supportedType && true === in_array($agent, PlexClient::SUPPORTED_AGENTS),
                'agent' => $agent,
                'scanner' => ag($item, 'scanner'),
            ];
        }

        $x = 0;
        foreach ($response->response as $item) {
            $this->assertSame(
                ag($expected, $item['id']),
                $item,
                r('Assert response item payload:{number} is formatted as expected', ['number' => $x])
            );
            $x++;
        }
    }

    public function test_401_response(): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/plex_data.json'), true);

        $json = ag($data, 'sections_get_401');

        $resp = new MockResponse(json_encode(ag($json, 'response.body')), [
            'http_code' => (int)ag($json, 'response.http_code'),
            'response_headers' => ag($json, 'response.headers', [])
        ]);

        $client = new MockHttpClient($resp);
        $list = new GetLibrariesList($client, $this->logger);
        $response = $list($this->context);

        $this->assertFalse($response->status);
        $this->assertNotNull($response->error);
        $this->assertSame(
            'ERROR: Request for [Plex] libraries returned with unexpected [401] status code.',
            (string)$response->error
        );

        $this->assertNull($response->response);
        $this->assertFalse($response->error->hasException());
    }
}
