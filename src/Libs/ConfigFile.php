<?php

declare(strict_types=1);

namespace App\Libs;

use ArrayAccess;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConfigFile
 *
 * The ConfigFile class represents a configuration file.
 */
final class ConfigFile implements ArrayAccess
{
    private const CONTENT_TYPES = ['yaml', 'json'];
    private array $data = [];
    private array $operations = [];
    private int $file_mtime = 0;
    private int $file_size = 0;

    /**
     * ConfigFile constructor.
     *
     * @param string $file The config file.
     * @param string $type The content type. Default to 'yaml'.
     * @param bool $autoSave Auto save changes. Default to 'true'.
     * @param bool $autoCreate Auto create the file if it does not exist. Default is 'false'.
     * @param array $opts Additional options.
     *
     * @throws InvalidArgumentException If the content type is invalid.
     */
    public function __construct(
        private readonly string $file,
        private readonly string $type = 'yaml',
        private readonly bool $autoSave = true,
        private readonly bool $autoCreate = false,
        private readonly array $opts = [],
    ) {
        if (!in_array($this->type, self::CONTENT_TYPES)) {
            throw new InvalidArgumentException(r('Invalid content type \'{type}\'. Expecting \'{types}\'.', [
                'type' => $type,
                'types' => implode(', ', self::CONTENT_TYPES)
            ]));
        }

        if (!file_exists($this->file)) {
            if (false === $this->autoCreate) {
                throw new InvalidArgumentException(r('File \'{file}\' does not exist.', ['file' => $file]));
            }
            if (false === @touch($this->file)) {
                throw new InvalidArgumentException(r('File \'{file}\' could not be created.', ['file' => $file]));
            }
        }

        $this->loadData();
    }

    /**
     * Get the value of a key.
     *
     * @param string|int $key The key to get.
     * @param mixed $default The default value to return if the key does not exist. Defaults to null.
     *
     * @return mixed The value of the key or the default value if the key does not exist.
     */
    public function get(string|int $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    /**
     * Set the value of a key.
     *
     * @param string $key The key to set.
     * @param mixed $value The value to set.
     *
     * @return $this
     */
    public function set(string $key, mixed $value): self
    {
        $this->operations[] = [
            'type' => 'set',
            'key' => $key,
            'value' => $value
        ];

        $this->data = ag_set($this->data, $key, $value);

        return $this;
    }

    /**
     * Delete a key.
     *
     * @param string|int $key The key to delete.
     *
     * @return $this
     */
    public function delete(string|int $key): self
    {
        $this->operations[] = [
            'type' => 'delete',
            'key' => $key
        ];

        $this->data = ag_delete($this->data, $key);

        return $this;
    }

    /**
     * Persist the changes to the file.
     *
     * @param bool $override Override the file. Default is 'false'.
     *
     * @return $this
     */
    public function persist(bool $override = false): self
    {
        if (empty($this->operations)) {
            return $this;
        }

        if (!$override && (filemtime($this->file) > $this->file_mtime || filesize($this->file) !== $this->file_size)) {
            $this->loadData();
        }

        $json_encode = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT;
        if (ag_exists($this->opts, 'json_encode')) {
            $json_encode = $this->opts['json_encode'];
        }

        $stream = new Stream($this->file, 'w');
        $stream->write(
            match ($this->type) {
                'yaml' => Yaml::dump($this->data),
                'json' => json_encode($this->data, flags: $json_encode),
                default => throw new RuntimeException(r('Invalid content type \'{type}\'.', [
                    'type' => $this->type
                ])),
            }
        );
        $stream->close();

        $this->operations = [];
        $this->file_mtime = filemtime($this->file);
        $this->file_size = filesize($this->file);

        return $this;
    }

    /**
     * Override the file.
     *
     * This method will override the file with the current loaded data. regardless of the state of the file at save
     * time. It should be used with caution.
     *
     * @return $this
     */
    public function override(): self
    {
        return $this->persist(override: true);
    }

    /**
     * On class destruction, persist the changes to the file. if autoSave is set to true.
     */
    public function __destruct()
    {
        if (true === $this->autoSave) {
            $this->persist();
        }
    }

    /**
     * Load the data from the file.
     *
     * This method is responsible for loading the data from the file and re-applying any operations that have
     * been performed on the data.
     *
     * This hopefully will prevent a race condition from occurring when multiple processes are trying to write to
     * the at the same time.
     *
     * This of course will only work if all file operations are done through this class.
     */
    private function loadData(): void
    {
        $jsonOpts = JSON_THROW_ON_ERROR;

        if (ag_exists($this->opts, 'json_decode')) {
            $jsonOpts = $this->opts['json_decode'];
        }

        $stream = $stream ?? new Stream($this->file, 'r');

        if ($stream->isSeekable()) {
            $stream->seek(0);
        }

        $content = $stream->getContents();

        $this->file_size = filesize($this->file);
        $this->file_mtime = filemtime($this->file);
        $this->data = [];

        if (!empty($content)) {
            $this->data = match ($this->type) {
                'yaml' => Yaml::parse($content),
                'json' => json_decode($content, true, flags: $jsonOpts),
                default => throw new RuntimeException(r('Invalid content type \'{type}\'.', ['type' => $this->type])),
            };
        }

        foreach ($this->operations as $operation) {
            $this->data = match ($operation['type']) {
                'set' => ag_set($this->data, $operation['key'], $operation['value']),
                'delete' => ag_delete($this->data, $operation['key']),
                default => throw new RuntimeException(
                    r('Invalid operation type \'{type}\'.', ['type' => $operation['type']])
                )
            };
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return ag_exists($this->data, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->delete($offset);
    }
}
