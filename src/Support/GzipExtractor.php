<?php

namespace DcyphrDigital\Helpers\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GzipExtractor
{
    private string $source = '';

    private string $destination = '';

    public function extract(): bool
    {
        if (! $this->source()) {
            throw new InvalidArgumentException('Please provide a source!');
        }

        try {
            $file = gzopen($this->source(), 'r');
        } catch (\Throwable) {
            throw new RuntimeException('File could not be opened');
        }

        if ($file === false) {
            throw new RuntimeException('File could not be opened');
        }
        $output = fopen($this->destination(), 'w');

        if ($output === false) {
            gzclose($file);
            throw new RuntimeException('Destination file could not be opened');
        }

        while (! feof($file)) {
            fwrite($output, fread($file, 1024 * 1024));
        }

        fclose($output);
        gzclose($file);

        $this->source = '';
        $this->destination = '';

        return true;
    }

    public function setSource(string $source = ''): self
    {
        if (! Str::endsWith($source, '.gz')) {
            throw new InvalidArgumentException('Can only process a Gzip file, needs to have a .gz extension');
        }

        $this->source = $source;

        return $this;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function setDestination(string $destination = ''): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function destination(): string
    {
        return $this->destination ?: dirname($this->source).'/extracted-'.now()->timestamp.'.txt';
    }
}
