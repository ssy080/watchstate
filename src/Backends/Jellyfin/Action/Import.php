<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use Closure;
use DateTimeInterface as iDate;
use InvalidArgumentException;
use JsonException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

class Import
{
    use CommonTrait;
    use JellyfinActionTrait;

    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

    /**
     * @param Context $context
     * @param iGuid $guid
     * @param iImport $mapper
     * @param iDate|null $after
     * @param array $opts
     *
     * @return Response
     */
    public function __invoke(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        iDate|null $after = null,
        array $opts = []
    ): Response {
        return $this->tryResponse($context, fn() => $this->getLibraries(
            context: $context,
            handle: fn(array $logContext = []) => fn(iResponse $response) => $this->handle(
                context: $context,
                response: $response,
                callback: fn(array $item, array $logContext = []) => $this->process(
                    context: $context,
                    guid: $guid,
                    mapper: $mapper,
                    item: $item,
                    logContext: $logContext,
                    opts: $opts + ['after' => $after],
                ),
                logContext: $logContext
            ),
            error: fn(array $logContext = []) => fn(Throwable $e) => $this->logger->error(
                'Unhandled Exception was thrown during [%(backend)] library [%(library.title)] request.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]
            ),
        ));
    }

    protected function getLibraries(Context $context, Closure $handle, Closure $error): array
    {
        try {
            $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser));

            $this->logger->debug('Requesting [%(backend)] libraries.', [
                'backend' => $context->backendName,
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

            $payload = $response->getContent(false);

            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] response.', [
                    'backend' => $context->backendName,
                    'url' => (string)$url,
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(false),
                    'response' => $payload,
                ]);
            }

            if (200 !== $response->getStatusCode()) {
                $logContext = [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(false),
                ];

                if ($context->trace) {
                    $logContext['trace'] = $response->getInfo('debug');
                }

                $this->logger->error(
                    'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    $logContext
                );
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }

            $json = json_decode(
                json: $response->getContent(),
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->warning('Request for [%(backend)] libraries returned with empty list.', [
                    'backend' => $context->backendName,
                    'body' => $json,
                ]);
                Message::add("{$context->backendName}.has_errors", true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Request for [%(backend)] libraries has failed.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $context->trace ? $e->getTrace() : [],
                ],
            ]);
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error('Request for [%(backend)] libraries returned with invalid body.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                    'trace' => $context->trace ? $e->getTrace() : [],
                ],
            ]);
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception was thrown during request for [%(backend)] libraries.', [
                'backend' => $context->backendName,
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $context->trace ? $e->getTrace() : [],
                ],
            ]);
            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        $requests = $total = [];
        $ignored = $unsupported = 0;

        // -- Get library items count.
        foreach ($listDirs as $section) {
            $logContext = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
            ];

            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MOVIES])) {
                continue;
            }

            $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser))->withQuery(
                http_build_query(
                    [
                        'sortBy' => 'DateCreated',
                        'sortOrder' => 'Ascending',
                        'parentId' => ag($logContext, 'library.id'),
                        'recursive' => 'true',
                        'excludeLocationTypes' => 'Virtual',
                        'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                        'startIndex' => 0,
                        'limit' => 0,
                    ]
                )
            );

            $logContext['library']['url'] = (string)$url;

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] items count.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, [
                        'user_data' => $logContext
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error('Request for [%(backend)] [%(library.title)] items count has failed.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]);
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during [%(backend)] [%(library.title)] items count request.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            }
        }

        // -- Parse libraries items count.
        foreach ($requests as $response) {
            $logContext = ag($response->getInfo('user_data'), []);

            try {
                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        'Request for [%(backend)] [%(library.title)] items count returned with unexpected [%(status_code)] status code.',
                        [
                            'backend' => $context->backendName,
                            'status_code' => $response->getStatusCode(),
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                $json = json_decode($response->getContent(), true);

                $totalCount = (int)(ag($json, 'TotalRecordCount', 0));

                if ($totalCount < 1) {
                    $this->logger->warning(
                        'Request for [%(backend)] [%(library.title)] items count returned with total number of 0.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'body' => $json,
                        ]
                    );
                    continue;
                }

                $total[ag($logContext, 'library.id')] = $totalCount;
            } catch (ExceptionInterface $e) {
                $this->logger->error('Request for [%(backend)] [%(library.title)] total items has failed.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]);
                continue;
            }
        }

        $requests = [];

        // -- Episodes Parent external ids.
        foreach ($listDirs as $section) {
            $logContext = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
                'segment' => [
                    'number' => 1,
                    'of' => 1,
                ],
            ];

            if (JFC::COLLECTION_TYPE_SHOWS !== ag($logContext, 'library.type')) {
                continue;
            }

            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                continue;
            }

            if (true === array_key_exists(ag($logContext, 'library.id'), $total)) {
                $logContext['library']['totalRecords'] = $total[ag($logContext, 'library.id')];
            }

            $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser))->withQuery(
                http_build_query(
                    [
                        'parentId' => ag($logContext, 'library.id'),
                        'recursive' => 'false',
                        'enableUserData' => 'false',
                        'enableImages' => 'false',
                        'fields' => implode(',', JFC::EXTRA_FIELDS),
                        'excludeLocationTypes' => 'Virtual',
                    ]
                )
            );

            $logContext['library']['url'] = (string)$url;

            $this->logger->debug('Requesting [%(backend)] [%(library.title)] series external ids.', [
                'backend' => $context->backendName,
                ...$logContext,
            ]);

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, [
                        'user_data' => [
                            'ok' => $handle($logContext),
                            'error' => $error($logContext),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    'Request for [%(backend)] [%(library.title)] series external ids has failed.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unhandled exception was thrown during [%(backend)] [%(library.title)] series external ids request.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            }
        }

        // -- get paginated movies/episodes.
        foreach ($listDirs as $section) {
            $logContext = [
                'library' => [
                    'id' => (string)ag($section, 'Id'),
                    'title' => ag($section, 'Name', '??'),
                    'type' => ag($section, 'CollectionType', 'unknown'),
                ],
            ];


            if (true === in_array(ag($logContext, 'library.id'), $ignoreIds ?? [])) {
                $ignored++;
                $this->logger->info('Ignoring [%(backend)] [%(library.title)]. Requested by user config.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            if (!in_array(ag($logContext, 'library.type'), [JFC::COLLECTION_TYPE_SHOWS, JFC::COLLECTION_TYPE_MOVIES])) {
                $unsupported++;
                $this->logger->info(
                    'Ignoring [%(backend)] [%(library.title)]. Library type [%(library.type)] is not supported.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            if (false === array_key_exists(ag($logContext, 'library.id'), $total)) {
                $ignored++;
                $this->logger->warning('Ignoring [%(backend)] [%(library.title)]. No items count was found.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            $logContext['library']['totalRecords'] = $total[ag($logContext, 'library.id')];

            $segmentTotal = (int)$total[ag($logContext, 'library.id')];
            $segmentSize = (int)ag($context->options, Options::LIBRARY_SEGMENT, 1000);
            $segmented = ceil($segmentTotal / $segmentSize);

            for ($i = 0; $i < $segmented; $i++) {
                try {
                    $logContext['segment'] = [
                        'number' => $i + 1,
                        'of' => $segmented,
                        'size' => $segmentSize,
                    ];

                    $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser))
                        ->withQuery(
                            http_build_query(
                                [
                                    'sortBy' => 'DateCreated',
                                    'sortOrder' => 'Ascending',
                                    'parentId' => ag($logContext, 'library.id'),
                                    'recursive' => 'true',
                                    'enableUserData' => 'true',
                                    'enableImages' => 'false',
                                    'excludeLocationTypes' => 'Virtual',
                                    'fields' => implode(',', JFC::EXTRA_FIELDS),
                                    'includeItemTypes' => implode(',', [JFC::TYPE_MOVIE, JFC::TYPE_EPISODE]),
                                    'limit' => $segmentSize,
                                    'startIndex' => $i < 1 ? 0 : ($segmentSize * $i),
                                ]
                            )
                        );

                    $logContext['library']['url'] = (string)$url;

                    $this->logger->debug(
                        'Requesting [%(backend)] [%(library.title)] [%(segment.number)/%(segment.of)] content list.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );

                    $requests[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, [
                            'user_data' => [
                                'ok' => $handle($logContext),
                                'error' => $error($logContext),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        'Request for [%(backend)] [%(library.title)] [%(segment.number)/%(segment.of)] content list has failed.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                } catch (Throwable $e) {
                    $this->logger->error(
                        'Unhandled exception was thrown during [%(backend)] [%(library.title)] [%(segment.number)/%(segment.of)] content list request.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $context->trace ? $e->getTrace() : [],
                            ],
                        ]
                    );
                    continue;
                }
            }
        }

        if (0 === count($requests)) {
            $this->logger->warning('No requests for [%(backend)] libraries were queued.', [
                'backend' => $context->backendName,
                'context' => [
                    'total' => count($listDirs),
                    'ignored' => $ignored,
                    'unsupported' => $unsupported,
                ],
            ]);

            Message::add("{$context->backendName}.has_errors", true);
            return [];
        }

        return $requests;
    }

    /**
     * @throws TransportExceptionInterface
     */
    protected function handle(Context $context, iResponse $response, Closure $callback, array $logContext = []): void
    {
        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                'Request for [%(backend)] [%(library.title)] content returned with unexpected [%(status_code)] status code.',
                [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    ...$logContext,
                ]
            );
            return;
        }

        $start = makeDate();
        $this->logger->info(
            'Parsing [%(backend)] library [%(library.title)] [%(segment.number)/%(segment.of)] response.',
            [
                'backend' => $context->backendName,
                ...$logContext,
                'time' => [
                    'start' => $start,
                ],
            ]
        );

        try {
            $it = Items::fromIterable(
                iterable: httpClientChunks($this->http->stream($response)),
                options: [
                    'pointer' => '/Items',
                    'decoder' => new ErrorWrappingDecoder(
                        innerDecoder: new ExtJsonDecoder(
                            assoc: true,
                            options: JSON_INVALID_UTF8_IGNORE
                        )
                    )
                ]
            );

            foreach ($it as $entity) {
                if ($entity instanceof DecodingError) {
                    $this->logger->warning(
                        'Failed to decode one item of [%(backend)] [%(library.title)] [%(segment.number)/%(segment.of)] content.',
                        [
                            'backend' => $context->backendName,
                            ...$logContext,
                            'error' => [
                                'message' => $entity->getErrorMessage(),
                                'body' => $entity->getMalformedJson(),
                            ],
                        ]
                    );
                    continue;
                }

                if (null !== ($indexNumberEnd = ag($entity, 'IndexNumberEnd'))) {
                    foreach (range((int)ag($entity, 'IndexNumber'), $indexNumberEnd) as $i) {
                        $entity['IndexNumber'] = $i;
                        $callback(item: $entity, logContext: $logContext);
                    }
                } else {
                    $callback(item: $entity, logContext: $logContext);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during parsing of [%(backend)] library [%(library.title)] [%(segment.number)/%(segment.of)] response.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
        }

        $end = makeDate();
        $this->logger->info(
            'Parsing [%(backend)] library [%(library.title)] [%(segment.number)/%(segment.of)] response is complete.',
            [
                'backend' => $context->backendName,
                ...$logContext,
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => number_format($end->getTimestamp() - $start->getTimestamp()),
                ],
            ]
        );

        Message::increment('response.size', (int)$response->getInfo('size_download'));
    }

    protected function processShow(Context $context, iGuid $guid, array $item, array $logContext = []): void
    {
        $logContext['item'] = [
            'id' => ag($item, 'Id'),
            'title' => sprintf(
                '%s (%s)',
                ag($item, ['Name', 'OriginalTitle'], '??'),
                ag($item, 'ProductionYear', '0000')
            ),
            'year' => ag($item, 'ProductionYear', null),
            'type' => ag($item, 'Type'),
        ];

        if ($context->trace) {
            $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title) (%(item.year))] payload.', [
                'backend' => $context->backendName,
                ...$logContext,
                'body' => $item,
            ]);
        }

        $providersId = (array)ag($item, 'ProviderIds', []);

        if (false === $guid->has(guids: $providersId, context: $logContext)) {
            $message = 'Ignoring [%(backend)] [%(item.title)]. %(item.type) has no valid/supported external ids.';

            if (empty($providersId)) {
                $message .= ' Most likely unmatched %(item.type).';
            }

            $this->logger->info($message, [
                'backend' => $context->backendName,
                ...$logContext,
                'guids' => !empty($providersId) ? $providersId : 'None'
            ]);

            return;
        }

        $context->cache->set(
            JFC::TYPE_SHOW . '.' . ag($logContext, 'item.id'),
            Guid::fromArray(
                payload: $guid->get(guids: $providersId, context: $logContext),
                context: [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]
            )->getAll()
        );
    }

    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = []
    ): void {
        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $mappedType = JFC::TYPE_MAPPER[$type] ?? $type;

        try {
            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item
                ]);
            }

            Message::increment("{$context->backendName}.{$mappedType}.total");

            try {
                $logContext['item'] = [
                    'id' => ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => sprintf(
                            '%s (%d)',
                            ag($item, ['Name', 'OriginalTitle'], '??'),
                            ag($item, 'ProductionYear', 0000)
                        ),
                        JFC::TYPE_EPISODE => trim(
                            sprintf(
                                '%s - (%sx%s)',
                                ag($item, 'SeriesName', '??'),
                                str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                                str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                            )
                        ),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'type' => ag($item, 'Type'),
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->error($e->getMessage(), [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
                return;
            }

            $isPlayed = true === (bool)ag($item, 'UserData.Played');
            $dateKey = true === $isPlayed ? 'UserData.LastPlayedDate' : 'DateCreated';

            if (null === ag($item, $dateKey)) {
                $this->logger->debug('Ignoring [%(backend)] %(item.type) [%(item.title)]. No Date is set on object.', [
                    'backend' => $context->backendName,
                    'date_key' => $dateKey,
                    ...$logContext,
                    'body' => $item,
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_date_is_set");
                return;
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: $opts + [
                    iState::COLUMN_META_LIBRARY => ag($logContext, 'library.id'),
                    'override' => [
                        iState::COLUMN_EXTRA => [
                            $context->backendName => [
                                iState::COLUMN_EXTRA_EVENT => 'task.import',
                                iState::COLUMN_EXTRA_DATE => makeDate('now'),
                            ],
                        ],
                    ]
                ],
            );

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                $providerIds = (array)ag($item, 'ProviderIds', []);

                $message = 'Ignoring [%(backend)] [%(item.title)]. No valid/supported external ids.';

                if (empty($providerIds)) {
                    $message .= ' Most likely unmatched %(item.type).';
                }

                $this->logger->info($message, [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'guids' => !empty($providerIds) ? $providerIds : 'None'
                ]);

                Message::increment("{$context->backendName}.{$mappedType}.ignored_no_supported_guid");
                return;
            }

            $mapper->add(entity: $entity, opts: [
                'after' => ag($opts, 'after', null),
                Options::IMPORT_METADATA_ONLY => true === (bool)ag($context->options, Options::IMPORT_METADATA_ONLY),
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during handling of [%(backend)] [%(library.title)] [%(item.title)] import.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
        }
    }
}
