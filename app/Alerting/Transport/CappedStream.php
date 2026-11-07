<?php

namespace App\Alerting\Transport;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A write sink that keeps at most a fixed number of bytes and and aborts the transfer by reporting a short write once the rest would exceed it.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $written = 0;

    private bool $overflowed = false;

    public function __construct(private StreamInterface $stream, private readonly int $cap) {}

    public function write(string $string): int
    {
        $room = max(0, $this->cap - $this->written);
        if ($room > 0) {
            $kept = substr($string, 0, $room);
            $this->stream->write($kept);
            $this->written += strlen($kept);
        }

        if (strlen($string) > $room) {
            $this->overflowed = true;

            // A short write aborts the transfer.
            return $room;
        }

        return strlen($string);
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function contents(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }
}
