<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use DateInterval;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Webhooks
{
    use APITraits;

    private iLogger $accesslog;

    public function __construct(private iCache $cache)
    {
        $this->accesslog = new Logger(name: 'http', processors: [new LogMessageProcessor()]);

        $level = Config::get('webhook.debug') ? Level::Debug : Level::Info;

        if (null !== ($logfile = Config::get('webhook.logfile'))) {
            $this->accesslog = $this->accesslog->pushHandler(new StreamHandler($logfile, $level, true));
        }

        if (true === inContainer()) {
            $this->accesslog->pushHandler(new StreamHandler('php://stderr', $level, true));
        }
    }

    /**
     * Receive a webhook request from a backend.
     *
     * @param iRequest $request The incoming request object.
     * @param array $args The request path arguments.
     *
     * @return iResponse The response object.
     * @throws InvalidArgumentException if cache key is invalid.
     */
    #[Route(['POST', 'PUT'], Index::URL . '/{name:backend}/webhook[/]', name: 'webhooks.receive')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $backend = $this->getBackends(name: $name);
            if (empty($backend)) {
                throw new RuntimeException(r("Backend '{backend}' not found.", ['backend ' => $name]));
            }

            $backend = array_pop($backend);

            $client = $this->getClient(name: $name);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (true === Config::get('webhook.dumpRequest')) {
            saveRequestPayload(clone $request);
        }

        $request = $client->processRequest($request);
        $attr = $request->getAttributes();

        if (null !== ($userId = ag($backend, 'user', null)) && true === (bool)ag($backend, 'webhook.match.user')) {
            if (null === ($requestUser = ag($attr, 'user.id'))) {
                $message = "Request payload didn't contain a user id. Backend requires a user check.";
                $this->write($request, Level::Info, $message);
                return api_error($message, HTTP_STATUS::HTTP_BAD_REQUEST)->withHeader('X-Log-Response', '0');
            }

            if (false === hash_equals((string)$userId, (string)$requestUser)) {
                $message = r('Request user id [{req_user}] does not match configured value [{config_user}]', [
                    'req_user' => $requestUser ?? 'NOT SET',
                    'config_user' => $userId,
                ]);
                $this->write($request, Level::Info, $message);
                return api_error($message, HTTP_STATUS::HTTP_BAD_REQUEST)->withHeader('X-Log-Response', '0');
            }
        }

        if (null !== ($uuid = ag($backend, 'uuid', null)) && true === (bool)ag($backend, 'webhook.match.uuid')) {
            if (null === ($requestBackendId = ag($attr, 'backend.id'))) {
                $message = "Request payload didn't contain the backend unique id.";
                $this->write($request, Level::Info, $message);
                return api_error($message, HTTP_STATUS::HTTP_BAD_REQUEST)->withHeader('X-Log-Response', '0');
            }

            if (false === hash_equals((string)$uuid, (string)$requestBackendId)) {
                $message = r('Request backend unique id [{req_uid}] does not match backend uuid [{config_uid}].', [
                    'req_uid' => $requestBackendId ?? 'NOT SET',
                    'config_uid' => $uuid,
                ]);
                $this->write($request, Level::Info, $message);
                return api_error($message, HTTP_STATUS::HTTP_BAD_REQUEST)->withHeader('X-Log-Response', '0');
            }
        }

        if (true === (bool)ag($backend, 'import.enabled')) {
            if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $metadataOnly = true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY);

        if (true !== $metadataOnly && true !== (bool)ag($backend, 'import.enabled')) {
            $response = api_response(HTTP_STATUS::HTTP_NOT_ACCEPTABLE);
            $this->write($request, Level::Error, r('Import are disabled for [{backend}].', [
                'backend' => $client->getName(),
            ]), forceContext: true);

            return $response->withHeader('X-Log-Response', '0');
        }

        $entity = $client->parseWebhook($request);

        if (true === (bool)ag($backend, 'options.' . Options::DUMP_PAYLOAD)) {
            saveWebhookPayload($entity, $request);
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->write(
                $request,
                Level::Info,
                'Ignoring [{backend}] {item.type} [{item.title}]. No valid/supported external ids.',
                [
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                    ],
                ]
            );

            return api_response(HTTP_STATUS::HTTP_NOT_MODIFIED)->withHeader('X-Log-Response', '0');
        }

        if ((0 === (int)$entity->episode || null === $entity->season) && $entity->isEpisode()) {
            $this->write(
                $request,
                Level::Notice,
                'Ignoring [{backend}] {item.type} [{item.title}]. No episode/season number present.',
                [
                    'backend' => $entity->via,
                    'item' => [
                        'title' => $entity->getName(),
                        'type' => $entity->type,
                        'season' => (string)($entity->season ?? 'None'),
                        'episode' => (string)($entity->episode ?? 'None'),
                    ]
                ]
            );

            return api_response(HTTP_STATUS::HTTP_NOT_MODIFIED)->withHeader('X-Log-Response', '0');
        }

        $items = $this->cache->get('requests', []);

        $itemId = r('{type}://{id}:{tainted}@{backend}', [
            'type' => $entity->type,
            'backend' => $entity->via,
            'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
            'id' => ag($entity->getMetadata($entity->via), iState::COLUMN_ID, '??'),
        ]);

        $items[$itemId] = [
            'options' => [
                Options::IMPORT_METADATA_ONLY => $metadataOnly,
            ],
            'entity' => $entity,
        ];

        $this->cache->set('requests', $items, new DateInterval('P3D'));

        if (false === $metadataOnly && true === $entity->hasPlayProgress()) {
            $progress = $this->cache->get('progress', []);
            $progress[$itemId] = $entity;
            $this->cache->set('progress', $progress, new DateInterval('P1D'));
        }

        $this->write($request, Level::Info, 'Queued [{backend}: {event}] {item.type} [{item.title}].', [
                'backend' => $entity->via,
                'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT),
                'has_progress' => $entity->hasPlayProgress() ? 'Yes' : 'No',
                'item' => [
                    'title' => $entity->getName(),
                    'type' => $entity->type,
                    'played' => $entity->isWatched() ? 'Yes' : 'No',
                    'queue_id' => $itemId,
                    'progress' => $entity->hasPlayProgress() ? $entity->getPlayProgress() : null,
                ]
            ]
        );

        return api_response(HTTP_STATUS::HTTP_OK)->withHeader('X-Log-Response', '0');
    }

    /**
     * Write a log entry to the access log.
     *
     * @param iRequest $request The incoming request object.
     * @param int|string|Level $level The log level or priority.
     * @param string $message The log message.
     * @param array $context Additional data/context for the log entry.
     */
    private function write(
        iRequest $request,
        int|string|Level $level,
        string $message,
        array $context = [],
        bool $forceContext = false
    ): void {
        $params = $request->getServerParams();

        $uri = new Uri((string)ag($params, 'REQUEST_URI', '/'));

        if (false === empty($uri->getQuery())) {
            $query = [];
            parse_str($uri->getQuery(), $query);
            if (true === ag_exists($query, 'apikey')) {
                $query['apikey'] = 'api_key_removed';
                $uri = $uri->withQuery(http_build_query($query));
            }
        }

        $context = array_replace_recursive([
            'request' => [
                'method' => $request->getMethod(),
                'id' => ag($params, 'X_REQUEST_ID'),
                'ip' => getClientIp($request),
                'agent' => ag($params, 'HTTP_USER_AGENT'),
                'uri' => (string)$uri,
            ],
        ], $context);

        if (($attributes = $request->getAttributes()) && count($attributes) >= 1) {
            $context['attributes'] = $attributes;
        }

        if (true === (Config::get('logs.context') || $forceContext)) {
            $this->accesslog->log($level, $message, $context);
        } else {
            $this->accesslog->log($level, r($message, $context));
        }
    }
}
