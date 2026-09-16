<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Progress;

/**
 * Emits `{label} {n}%` each time progress crosses a 10% boundary.
 */
final class PercentReporter
{
    private int $done = 0;

    private int $lastEmitted = 0;

    /**
     * @param callable(string): void $emit
     */
    public function __construct(
        private readonly int $total,
        private readonly string $label,
        private $emit,
        private readonly int $step = 10,
    ) {
        if ($this->step < 1 || $this->step > 100) {
            throw new \InvalidArgumentException('Progress step must be between 1 and 100');
        }
    }

    public function add(int $n): void
    {
        if ($n <= 0) {
            return;
        }

        $this->set($this->done + $n);
    }

    public function set(int $done): void
    {
        $this->done = max(0, $done);
        $this->flush();
    }

    public function finish(): void
    {
        if ($this->total <= 0) {
            $this->emitOnce(100);

            return;
        }

        $this->done = $this->total;
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->total <= 0) {
            return;
        }

        $pct = (int) min(100, intdiv($this->done * 100, $this->total));
        $bucket = intdiv($pct, $this->step) * $this->step;
        if ($this->done >= $this->total) {
            $bucket = 100;
        }

        if ($bucket > $this->lastEmitted && $bucket >= $this->step) {
            $this->emitOnce($bucket);
        }
    }

    private function emitOnce(int $pct): void
    {
        if ($pct <= $this->lastEmitted) {
            return;
        }

        $this->lastEmitted = $pct;
        ($this->emit)(sprintf('%s %d%%', $this->label, $pct));
    }
}
