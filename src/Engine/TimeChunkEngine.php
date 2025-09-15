<?php

declare(strict_types=1);

namespace App\Engine;

class TimeChunkEngine
{

    public function splitEven(int $duration, int $minChunk = 1800, int $maxChunk = 7200): array
    {
        $minChunks = ceil($duration / $maxChunk);
        $maxChunks = floor($duration / $minChunk);

        if ($minChunks > $maxChunks) {
            throw new \Exception("Impossible to split within given min/max constraints");
        }

        // Pick best number of chunks
        $bestChunks = $minChunks;
        $bestDiff = PHP_INT_MAX;

        for ($n = $minChunks; $n <= $maxChunks; $n++) {
            $chunkSize = $duration / $n;
            $diff = abs($chunkSize - (($minChunk + $maxChunk) / 2));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestChunks = $n;
            }
        }

        $base = intdiv($duration, (int)$bestChunks);
        $remainder = $duration % $bestChunks;

        $chunks = array_fill(0, (int)$bestChunks, $base);
        for ($i = 0; $i < $remainder; $i++) {
            $chunks[$i]++;
        }

        return $chunks;
    }

}