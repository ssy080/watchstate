<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use App\Libs\StreamClosure;
use JsonException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Command
{
    public const string URL = '%{api.prefix}/system/command';
    private int $counter = 1;

    public function __construct()
    {
        set_time_limit(0);
    }

    #[Get(self::URL . '[/]', name: 'system.command')]
    public function __invoke(iRequest $request): iResponse
    {
        if (null === ($json = ag($request->getQueryParams(), 'json'))) {
            return api_error('No command was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $json = json_decode(base64_decode(rawurldecode($json)), true, flags: JSON_THROW_ON_ERROR);
            $data = DataUtil::fromArray($json);
            if (null === ($command = $data->get('command'))) {
                return api_error('No command was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }
        } catch (JsonException $e) {
            return api_error(
                r('Unable to decode json data. {error}', ['error' => $e->getMessage()]),
                HTTP_STATUS::HTTP_BAD_REQUEST
            );
        }

        if (!is_string($command)) {
            return api_error('Command is invalid.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $callable = function () use ($command, $data, $request) {
            ignore_user_abort(true);

            $path = realpath(__DIR__ . '/../../../');

            try {
                $process = Process::fromShellCommandline(
                    command: "{$path}/bin/console {$command}",
                    cwd: $path,
                    env: $_ENV,
                    timeout: $data->get('timeout', 3600),
                );

                $process->start(callback: function ($type, $data) use ($process) {
                    echo "id: " . hrtime(true) . "\n";
                    echo "event: data\n";
                    $data = (string)$data;
                    echo implode(
                        PHP_EOL,
                        array_map(
                            function ($data) {
                                if (!is_string($data)) {
                                    return null;
                                }
                                return 'data: ' . $data;
                            },
                            (array)preg_split("/\R/", $data)
                        )
                    );
                    echo "\n\n";

                    flush();

                    $this->counter = 3;

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                });

                while ($process->isRunning()) {
                    usleep(500000);
                    $this->counter--;

                    if ($this->counter > 1) {
                        continue;
                    }

                    $this->counter = 3;

                    echo "id: " . hrtime(true) . "\n";
                    echo "event: ping\n";
                    echo 'data: ' . makeDate() . "\n\n";
                    flush();

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                }
            } catch (ProcessTimedOutException) {
            }

            if (!connection_aborted()) {
                echo "id: " . hrtime(true) . "\n";
                echo "event: close\n";
                echo 'data: ' . makeDate() . "\n\n";
                flush();

                if (ob_get_length() > 0) {
                    ob_end_flush();
                }
            }
            exit;
        };

        return new Response(
            status: HTTP_STATUS::HTTP_OK->value,
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
                'Last-Event-Id' => time(),
            ],
            body: StreamClosure::create($callable)
        );
    }
}
