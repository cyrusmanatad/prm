<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OnboardingStorageTransfer
{
    /** Copies only referenced objects; never deletes or overwrites conflicting bytes. */
    public function run(iterable $documents, string $from, string $to, bool $copy): array
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $count = $size = 0;
        foreach ($documents as $row) {
            $key = 'onboarding/'.$row->org_id.'/'.$row->request_id.'/'.$row->id;
            if ($row->object_key !== $key) {
                throw new RuntimeException('Unexpected document key; no switch is safe.');
            }
            $bytes = $source->get($key);
            $this->verify($bytes, $row);
            if ($target->exists($key)) {
                $this->verify($target->get($key), $row);
            } elseif ($copy) {
                if (! $target->put($key, $bytes)) {
                    throw new RuntimeException('Storage write failed.');
                }
                $this->verify($target->get($key), $row);
            } else {
                throw new RuntimeException('Referenced destination object is missing.');
            }
            $count++;
            $size += (int) $row->size;
        }

        return ['documents' => $count, 'bytes' => $size];
    }

    private function verify(?string $bytes, object $row): void
    {
        if ($bytes === null || strlen($bytes) !== (int) $row->size || ! hash_equals($row->checksum, hash('sha256', $bytes))) {
            throw new RuntimeException('Document size or SHA-256 mismatch; no switch is safe.');
        }
    }
}
