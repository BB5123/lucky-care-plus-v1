<?php

namespace App\Modules\Documents\Infrastructure\Scanning;

use Closure;

/**
 * ClamAV adapter that speaks the clamd `INSTREAM` protocol over a unix socket.
 *
 * Why a socket and not `clamdscan`: a subprocess adds path quoting, PATH and
 * environment differences to the trust boundary, and its exit code is not a
 * verdict. The daemon protocol is small, has one null-terminated response, and
 * every operation below is bounded by the configured timeout.
 *
 * Protocol (clamd(8)):
 *   -> "zINSTREAM\0"
 *   -> repeated [uint32 network-order length][chunk bytes]
 *   -> [uint32 0]
 *   <- "stream: OK\0" | "stream: <signature> FOUND\0" | "... ERROR\0"
 *
 * `readResponse()` is public so the protocol can be exercised against an
 * in-memory stream in tests; it never talks to a real network by itself.
 */
final class ClamAvScanner implements MalwareScanner
{
    public const NAME = 'clamav';

    public const DEFAULT_SOCKET = '/var/run/clamav/clamd.ctl';

    private const CHUNK_BYTES = 8192;

    /**
     * The optional connector is a test seam: production passes nothing and the
     * adapter opens a real `unix://` stream socket.
     *
     * @param  Closure(string, float): (resource|false)|null  $connector
     */
    public function __construct(
        private readonly string $socketPath = self::DEFAULT_SOCKET,
        private readonly float $timeoutSeconds = 10.0,
        private readonly ?Closure $connector = null,
    ) {}

    public function scan(string $absolutePath): ScanVerdict
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return ScanVerdict::error(self::NAME, 'file_unreadable');
        }

        try {
            $socket = $this->connect();

            if ($socket === false) {
                return ScanVerdict::error(self::NAME, 'connection_failed');
            }

            try {
                $this->applyTimeout($socket);

                if (! $this->sendStream($socket, $handle)) {
                    return ScanVerdict::error(self::NAME, 'write_failed');
                }

                return $this->readResponse($socket);
            } finally {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read and parse one NUL-terminated clamd response from an open stream.
     *
     * A closed resource, an empty response and a response without a terminator
     * are all errors: none of them may ever be interpreted as `clean` (F-09).
     */
    public function readResponse(mixed $socket): ScanVerdict
    {
        if (! is_resource($socket)) {
            return ScanVerdict::error(self::NAME, 'connection_closed');
        }

        $this->applyTimeout($socket);

        $deadline = microtime(true) + $this->timeoutSeconds;
        $buffer = '';

        while (true) {
            $chunk = @fread($socket, 4096);

            if ($chunk === false) {
                return ScanVerdict::error(self::NAME, 'read_failed', $buffer);
            }

            $buffer .= $chunk;
            $terminator = strpos($buffer, "\0");

            if ($terminator !== false) {
                return $this->parse(substr($buffer, 0, $terminator), $buffer);
            }

            $meta = @stream_get_meta_data($socket);

            if (is_array($meta) && ($meta['timed_out'] ?? false) === true) {
                return ScanVerdict::error(self::NAME, 'timeout', $buffer);
            }

            if (microtime(true) > $deadline) {
                return ScanVerdict::error(self::NAME, 'timeout', $buffer);
            }

            if ($chunk === '' && feof($socket)) {
                return $buffer === ''
                    ? ScanVerdict::error(self::NAME, 'connection_closed')
                    : ScanVerdict::error(self::NAME, 'incomplete_response', $buffer);
            }
        }
    }

    private function parse(string $response, string $rawOutput): ScanVerdict
    {
        $line = trim($response);

        if ($line === '') {
            return ScanVerdict::error(self::NAME, 'empty_response', $rawOutput);
        }

        if (str_starts_with($line, 'stream:')) {
            $line = trim(substr($line, 7));
        }

        if (strcasecmp($line, 'OK') === 0) {
            return ScanVerdict::clean(self::NAME, $rawOutput);
        }

        if (preg_match('/^(.+)\s+FOUND$/i', $line, $matches) === 1) {
            $signature = trim($matches[1]);

            if ($signature !== '') {
                return ScanVerdict::infected(self::NAME, $signature, $rawOutput);
            }
        }

        if (str_ends_with(strtoupper($line), 'ERROR')) {
            return ScanVerdict::error(self::NAME, 'clamd_error', $rawOutput);
        }

        return ScanVerdict::error(self::NAME, 'unexpected_response', $rawOutput);
    }

    /**
     * @return resource|false
     */
    private function connect()
    {
        if ($this->connector instanceof Closure) {
            return ($this->connector)($this->socketPath, $this->timeoutSeconds);
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        return $socket === false ? false : $socket;
    }

    /**
     * @param  resource  $socket
     */
    private function applyTimeout(mixed $socket): bool
    {
        if (! is_resource($socket)) {
            return false;
        }

        $seconds = (int) floor($this->timeoutSeconds);
        $microseconds = (int) round(($this->timeoutSeconds - $seconds) * 1_000_000);

        return @stream_set_timeout($socket, $seconds, $microseconds);
    }

    /**
     * @param  resource  $socket
     * @param  resource  $handle
     */
    private function sendStream(mixed $socket, mixed $handle): bool
    {
        if (! $this->writeAll($socket, "zINSTREAM\0")) {
            return false;
        }

        while (! feof($handle)) {
            $chunk = @fread($handle, self::CHUNK_BYTES);

            if ($chunk === false) {
                return false;
            }

            if ($chunk === '') {
                break;
            }

            if (! $this->writeAll($socket, pack('N', strlen($chunk)).$chunk)) {
                return false;
            }
        }

        return $this->writeAll($socket, pack('N', 0));
    }

    /**
     * @param  resource  $socket
     */
    private function writeAll(mixed $socket, string $bytes): bool
    {
        if (! is_resource($socket)) {
            return false;
        }

        $deadline = microtime(true) + $this->timeoutSeconds;
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = @fwrite($socket, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;

            $meta = @stream_get_meta_data($socket);

            if (! is_array($meta) || ($meta['timed_out'] ?? false) === true) {
                return false;
            }

            if (microtime(true) > $deadline) {
                return false;
            }
        }

        return true;
    }
}
