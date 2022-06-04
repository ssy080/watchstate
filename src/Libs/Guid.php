<?php

declare(strict_types=1);

namespace App\Libs;

use Psr\Log\LoggerInterface;

final class Guid
{
    public const GUID_IMDB = 'guid_imdb';
    public const GUID_TVDB = 'guid_tvdb';
    public const GUID_TMDB = 'guid_tmdb';
    public const GUID_TVMAZE = 'guid_tvmaze';
    public const GUID_TVRAGE = 'guid_tvrage';
    public const GUID_ANIDB = 'guid_anidb';

    private const SUPPORTED = [
        Guid::GUID_IMDB => 'string',
        Guid::GUID_TVDB => 'string',
        Guid::GUID_TMDB => 'string',
        Guid::GUID_TVMAZE => 'string',
        Guid::GUID_TVRAGE => 'string',
        Guid::GUID_ANIDB => 'string',
    ];

    private const VALIDATE_GUID = [
        Guid::GUID_IMDB => [
            'pattern' => '/tt(\d+)/i',
            'example' => 'tt(number)',
        ]
    ];

    private const BACKEND_GUID = 'guidv_';

    private const LOOKUP_KEY = '%s://%s';

    private array $data = [];

    private static LoggerInterface|null $logger = null;

    /**
     * Create List of db => external id list.
     *
     * @param array $guids Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param bool $includeVirtual Whether to consider virtual guids.
     */
    public function __construct(array $guids, bool $includeVirtual = true)
    {
        $supported = self::getSupported(includeVirtual: $includeVirtual);

        foreach ($guids as $key => $value) {
            if (null === $value || null === ($supported[$key] ?? null)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (!is_string($key)) {
                $this->getLogger()->error(
                    sprintf(
                        'Unexpected key type was given. Expecting \'string\' but got \'%s\'.',
                        get_debug_type($key)
                    ),
                );
                continue;
            }

            if (null === ($supported[$key] ?? null)) {
                $this->getLogger()->error(
                    sprintf(
                        'Unexpected key \'%s\'. Expecting \'%s\'.',
                        $key,
                        implode(', ', array_keys($supported))
                    ),
                );
                continue;
            }

            if ($supported[$key] !== ($valueType = get_debug_type($value))) {
                $this->getLogger()->error(
                    sprintf(
                        'Unexpected value type for \'%s\'. Expecting \'%s\' but got \'%s\'.',
                        $key,
                        $supported[$key],
                        $valueType
                    )
                );
                continue;
            }

            if (null !== (self::VALIDATE_GUID[$key] ?? null)) {
                if (1 !== preg_match(self::VALIDATE_GUID[$key]['pattern'], $value)) {
                    $this->getLogger()->error(
                        sprintf(
                            'Unexpected value for \'%s\'. Expecting \'%s\' but got \'%s\'.',
                            $key,
                            self::VALIDATE_GUID[$key]['example'],
                            $value
                        )
                    );
                    continue;
                }
            }

            $this->data[$key] = $value;
        }
    }

    /**
     * Set Logger.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Make Virtual external id that point to backend://(backend_id)
     *
     * @param string $backend backend name.
     * @param string $value backend id
     *
     * @return array<string,string>
     */
    public static function makeVirtualGuid(string $backend, string $value): array
    {
        return [self::BACKEND_GUID . $backend => $value];
    }

    /**
     * Get Supported External ids sources.
     *
     * @param bool $includeVirtual Whether to include virtual ids.
     *
     * @return array<string,string>
     */
    public static function getSupported(bool $includeVirtual = false): array
    {
        static $list = null;

        if (false === $includeVirtual) {
            return self::SUPPORTED;
        }

        if (null !== $list) {
            return $list;
        }

        $list = self::SUPPORTED;

        foreach (array_keys((array)Config::get('servers', [])) as $name) {
            $list[self::BACKEND_GUID . $name] = 'string';
        }

        return $list;
    }

    /**
     * Create new instance from array.
     *
     * @param array $payload array of [ 'key' => 'value' ] pairs of [ 'db_source' => 'external id' ].
     * @param bool $includeVirtual Whether to include parsing of Virtual guids.
     *
     * @return self
     */
    public static function fromArray(array $payload, bool $includeVirtual = true): self
    {
        return new self(guids: $payload, includeVirtual: $includeVirtual);
    }

    /**
     * Return suitable pointers to link entity to external id.
     *
     * @return array
     */
    public function getPointers(): array
    {
        $arr = [];

        foreach ($this->data as $key => $value) {
            $arr[] = sprintf(self::LOOKUP_KEY, $key, $value);
        }

        return $arr;
    }

    /**
     * Return list of external ids.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->data;
    }

    /**
     * Get Instance of logger.
     *
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        if (null === self::$logger) {
            self::$logger = Container::get(LoggerInterface::class);
        }

        return self::$logger;
    }
}
