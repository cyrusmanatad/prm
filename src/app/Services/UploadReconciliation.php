<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * PRIV-05 (P4, D40): compares stored onboarding uploads with their document records and records findings. It never
 * deletes or moves a stored file. Deletion of an eligible orphan needs separately approved authority.
 */
class UploadReconciliation
{
    /** Exact key shape written by OnboardingDocuments::upload(); anything else is reported but never eligible. */
    public const KEY = '#^onboarding/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9a-f-]{36}$#';

    /** A stored file is written before its record commits, so files younger than this are not judged yet. */
    public const GRACE_SECONDS = 3600;

    /** Scan states whose upload stays under review while its request can still be edited. */
    private const HELD_STATES = ['pending', 'rejected'];

    /**
     * @return array{status: string, listed: int, referenced: int, skipped: int, counts: array<string, int>}
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $id = (string) Str::uuid();
        try {
            $documents = collect(json_decode(DB::selectOne('SELECT app_upload_reconcile_documents() AS data')->data, true))->keyBy('key');
            $files = $this->files();
        } catch (Throwable $error) {
            // Storage or the database could not be read: record the failure and leave earlier findings untouched.
            $failure = $error instanceof FilesystemException ? 'Storage could not be listed' : 'Document records could not be read';
            DB::select('SELECT app_upload_reconcile_record(?::uuid,?,?::jsonb,0,0,0,?)', [$id, 'failed', '[]', $failure]);

            return ['status' => 'failed', 'listed' => 0, 'referenced' => 0, 'skipped' => 0, 'counts' => []];
        }

        $findings = [];
        $referenced = 0;
        $skipped = 0;
        foreach ($files as $key => $file) {
            $document = $documents->get($key);
            if ($document) {
                $referenced++;
                if ($document['editable'] && in_array($document['state'], self::HELD_STATES, true)) {
                    $findings[] = ['key' => $key, 'kind' => 'held', 'size' => $file['size'], ...$this->owner($document)];
                }

                continue;
            }
            if (! preg_match(self::KEY, $key)) {
                $findings[] = ['key' => $key, 'kind' => 'unrecognised', 'size' => $file['size']];

                continue;
            }
            if ($file['modified'] === null || $now - $file['modified'] < self::GRACE_SECONDS) {
                $skipped++;

                continue;
            }
            $findings[] = ['key' => $key, 'kind' => 'orphaned', 'size' => $file['size']];
        }
        foreach ($documents as $key => $document) {
            if (! isset($files[$key])) {
                $findings[] = ['key' => $key, 'kind' => 'missing', 'size' => null, ...$this->owner($document)];
            }
        }
        DB::select('SELECT app_upload_reconcile_record(?::uuid,?,?::jsonb,?,?,?,NULL)', [$id, 'completed', json_encode($findings), count($files), $referenced, $skipped]);

        return ['status' => 'completed', 'listed' => count($files), 'referenced' => $referenced, 'skipped' => $skipped,
            'counts' => array_count_values(array_column($findings, 'kind'))];
    }

    /** @return array{org: string, request: string, document: string} the record's identifiers, whatever its key looks like */
    private function owner(array $document): array
    {
        return ['org' => $document['org'], 'request' => $document['request'], 'document' => $document['document']];
    }

    /** @return array<string, array{size: int|null, modified: int|null}> every stored file under the managed prefix */
    private function files(): array
    {
        $files = [];
        foreach (Storage::disk('onboarding')->getDriver()->listContents('onboarding', true) as $item) {
            if ($item instanceof FileAttributes) {
                $files[$item->path()] = ['size' => $item->fileSize(), 'modified' => $item->lastModified()];
            }
        }

        return $files;
    }
}
