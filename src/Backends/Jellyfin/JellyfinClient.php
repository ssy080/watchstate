<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Cache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\Backup;
use App\Backends\Jellyfin\Action\Export;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\GetInfo;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\GetSessions;
use App\Backends\Jellyfin\Action\GetUsersList;
use App\Backends\Jellyfin\Action\GetVersion;
use App\Backends\Jellyfin\Action\Import;
use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\Action\Progress;
use App\Backends\Jellyfin\Action\Push;
use App\Backends\Jellyfin\Action\SearchId;
use App\Backends\Jellyfin\Action\SearchQuery;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Exceptions\Backends\UnexpectedVersionException;
use App\Libs\Exceptions\HttpException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use DateTimeInterface as iDate;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface as iLogger;

/**
 * Class JellyfinClient
 *
 * This class is responsible for facilitating communication with jellyfin Server backend.
 *
 * @implements iClient
 */
class JellyfinClient implements iClient
{
    public const string CLIENT_NAME = 'Jellyfin';

    public const string TYPE_MOVIE = 'Movie';
    public const string TYPE_SHOW = 'Series';
    public const string TYPE_EPISODE = 'Episode';
    public const string COLLECTION_TYPE_SHOWS = 'tvshows';
    public const string COLLECTION_TYPE_MOVIES = 'movies';

    /**
     * @var array<string> This constant represents a list of extra fields tobe included in the request.
     */
    public const array EXTRA_FIELDS = [
        'ProviderIds',
        'DateCreated',
        'OriginalTitle',
        'SeasonUserData',
        'DateLastSaved',
        'PremiereDate',
        'ProductionYear',
        'Path',
        'UserDataLastPlayedDate',
    ];
    /**
     * @var array<string> Map the Jellyfin types to our own types.
     */
    public const array TYPE_MAPPER = [
        JellyfinClient::TYPE_SHOW => iState::TYPE_SHOW,
        JellyfinClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        JellyfinClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];
    /**
     * @var Context Backend context.
     */
    private Context $context;
    /**
     * @var iGuid Guid parser.
     */
    private iGuid $guid;
    /**
     * @var iLogger Logger instance.
     */
    private iLogger $logger;
    /**
     * @var Cache Cache instance.
     */
    private Cache $cache;

    /**
     * Class constructor.
     *
     * @param Cache $cache The cache instance.
     * @param iLogger $logger The logger instance.
     * @param JellyfinGuid $guid The Jellyfin GUID instance.
     */
    public function __construct(Cache $cache, iLogger $logger, JellyfinGuid $guid)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: static::CLIENT_NAME,
            backendUrl: new Uri('http://localhost'),
            cache: $this->cache,
        );
        $this->guid = $guid->withContext($this->context);
    }

    /**
     * @inheritdoc
     */
    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = new Context(
            clientName: static::CLIENT_NAME,
            backendName: $context->backendName,
            backendUrl: $context->backendUrl,
            cache: $this->cache->withData(static::CLIENT_NAME . '_' . $context->backendName, $context->options),
            backendId: $context->backendId,
            backendToken: $context->backendToken,
            backendUser: $context->backendUser,
            backendHeaders: array_replace_recursive([
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => r(
                        'MediaBrowser Token="{token}", Client="{app}", Device="{os}", DeviceId="{id}", Version="{version}", UserId="{user}"',
                        [
                            'token' => $context->backendToken,
                            'app' => Config::get('name') . '/' . static::CLIENT_NAME,
                            'os' => PHP_OS,
                            'id' => md5(Config::get('name') . '/' . static::CLIENT_NAME . $context->backendUser),
                            'version' => getAppVersion(),
                            'user' => $context->backendUser,
                        ]
                    ),
                ],
            ], ag($context->options, 'client', [])),
            trace: true === ag($context->options, Options::DEBUG_TRACE),
            options: array_replace_recursive($context->options, [
                Options::LIBRARY_SEGMENT => (int)ag(
                    $context->options,
                    Options::LIBRARY_SEGMENT,
                    Config::get('library.segment')
                ),
            ])
        );

        $cloned->guid = $cloned->guid->withContext($cloned->context);

        return $cloned;
    }

    /**
     * @inheritdoc
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->context?->backendName ?? static::CLIENT_NAME;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        return $response->isSuccessful() ? $response->response : $request;
    }

    /**
     * @inheritdoc
     */
    public function parseWebhook(ServerRequestInterface $request): iState
    {
        $response = Container::get(ParseWebhook::class)(
            context: $this->context,
            guid: $this->guid,
            request: $request
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new HttpException(
                ag($response->extra, 'message', fn() => $response->error->format()),
                ag($response->extra, 'http_code', 400),
            );
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function pull(iImport $mapper, iDate|null $after = null): array
    {
        $response = Container::get(Import::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
            opts: [
                Options::DISABLE_GUID => (bool)Config::get('episodes.disable.guid'),
            ]
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function backup(iImport $mapper, StreamInterface|null $writer = null, array $opts = []): array
    {
        $response = Container::get(Backup::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            opts: $opts + [
                'writer' => $writer,
                Options::DISABLE_GUID => (bool)Config::get('episodes.disable.guid'),
            ]
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function export(iImport $mapper, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Export::class)(
            context: $this->context,
            guid: $this->guid,
            mapper: $mapper,
            after: $after,
            opts: [
                'queue' => $queue,
                Options::DISABLE_GUID => (bool)Config::get('episodes.disable.guid'),
            ],
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function push(array $entities, QueueRequests $queue, iDate|null $after = null): array
    {
        $response = Container::get(Push::class)(
            context: $this->context,
            entities: $entities,
            queue: $queue,
            after: $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function progress(array $entities, QueueRequests $queue, iDate|null $after = null): array
    {
        $version = $this->getVersion();

        if (false === version_compare($version, '10.9', '>=')) {
            $this->throwError(
                response: new Response(
                    status: false,
                    error: new Error(
                        message: 'Jellyfin play progress support works on Jellyfin version {version.required} and above. You are currently running {version.current}.',
                        context: [
                            'version' => [
                                'current' => $version,
                                'required' => '10.9.x',
                            ],
                        ],
                        level: Levels::ERROR,
                    )
                ),
                className: UnexpectedVersionException::class
            );
        }

        $response = Container::get(Progress::class)(
            context: $this->context,
            guid: $this->guid,
            entities: $entities,
            queue: $queue,
            after: $after
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $response = Container::get(SearchQuery::class)(
            context: $this->context,
            query: $query,
            limit: $limit,
            opts: $opts
        );

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function searchId(string|int $id, array $opts = []): array
    {
        $response = Container::get(SearchId::class)(context: $this->context, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getMetadata(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(
            context: $this->context,
            id: $id,
            opts: $opts
        );

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getLibrary(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetLibrary::class)(context: $this->context, guid: $this->guid, id: $id, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getIdentifier(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->context->backendId) {
            return $this->context->backendId;
        }

        $response = Container::get(GetIdentifier::class)(context: $this->context);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        return $response->isSuccessful() ? $response->response : null;
    }

    /**
     * @inheritdoc
     */
    public function getUsersList(array $opts = []): array
    {
        $response = Container::get(GetUsersList::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getSessions(array $opts = []): array
    {
        $response = Container::get(GetSessions::class)($this->context, $opts);

        if (false === $response->isSuccessful()) {
            if ($response->hasError()) {
                $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
            }

            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getUserToken(int|string $userId, string $username): string|bool
    {
        return $this->context->backendToken;
    }

    /**
     * @inheritdoc
     */
    public function listLibraries(array $opts = []): array
    {
        $response = Container::get(GetLibrariesList::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getInfo(array $opts = []): array
    {
        $response = Container::get(GetInfo::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function getVersion(array $opts = []): string
    {
        $response = Container::get(GetVersion::class)(context: $this->context, opts: $opts);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            $this->throwError($response);
        }

        return $response->response;
    }

    /**
     * @inheritdoc
     */
    public function fromRequest(ServerRequestInterface $request): Context
    {
        return $this->context;
    }

    /**
     * @inheritdoc
     */
    public function validateContext(Context $context): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function manage(array $backend, array $opts = []): array
    {
        return Container::get(JellyfinManage::class)->manage(backend: $backend, opts: $opts);
    }

    /**
     * Throws an exception with the specified message and previous exception.
     *
     * @template T
     * @param Response $response The response object containing the error details.
     * @param class-string<T> $className The exception class name.
     * @param int $code The exception code.
     *
     * @throws T Always throws the specified exception.
     */
    private function throwError(Response $response, string $className = RuntimeException::class, int $code = 0): void
    {
        throw new $className(
            message: ag($response->extra, 'message', fn() => $response->error->format()),
            code: $code,
            previous: $response->error->previous
        );
    }
}
