<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Monolog\Utils;

/**
 * Original code by Jordi Boggiano <j.boggiano@seld.be>
 */
class LogMessageProcessor implements ProcessorInterface
{
    private string $pattern;

    public function __construct(
        private string $tagStart = '%(',
        private string $tagEnd = ')'
    ) {
        $this->pattern = '#' . preg_quote($this->tagStart, '#') . '([\w_.]+)' . preg_quote(
                $this->tagEnd,
                '#'
            ) . '#is';
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (false === str_contains($record['message'], $this->tagStart)) {
            return $record;
        }

        $status = preg_match_all($this->pattern, $record['message'], $matches);

        if (false === $status || $status < 1) {
            return $record;
        }

        $recordArr = $record->toArray();

        $replacements = [];

        foreach ($matches[1] as $key) {
            $placeholder = $this->tagStart . $key . $this->tagEnd;

            if (false === str_contains($recordArr['message'], $placeholder)) {
                continue;
            }

            if (false === ag_exists($recordArr['context'] ?? [], $key)) {
                continue;
            }

            $val = ag($recordArr['context'], $key);

            $recordArr['context'] = ag_delete($recordArr['context'], $key);

            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements[$placeholder] = $val;
            } elseif (is_object($val)) {
                $replacements[$placeholder] = '[object ' . Utils::getClass($val) . ']';
            } elseif (is_array($val)) {
                $replacements[$placeholder] = 'array' . Utils::jsonEncode($val, null, true);
            } else {
                $replacements[$placeholder] = '[' . gettype($val) . ']';
            }
        }

        // -- This might be problem in multibyte context.
        $recordArr['message'] = strtr($recordArr['message'], $replacements);

        unset($recordArr['level_name']);

        $recordArr['level'] = $record->level;

        return $record->with(...$recordArr);
    }
}
