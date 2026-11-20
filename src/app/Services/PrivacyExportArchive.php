<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Phar;
use PharData;

/** Bounded ZIP without an extra extension. Local archives are separate from uploaded documents. */
class PrivacyExportArchive
{
    public const MAX_BYTES = 48 * 1024 * 1024;

    public function write(string $id, string $scope, array $data): array
    {
        $disk = Storage::disk('privacy-exports');
        $disk->makeDirectory('');
        $path = $disk->path($id.'.part.zip');
        chmod(dirname($path), 0700);
        $zip = new PharData($path, 0, null, Phar::ZIP);
        $files = [];
        $excluded = [];
        $total = 0;
        $add = function (string $name, string $bytes) use ($zip, &$files, &$total) {
            $total += strlen($bytes);
            if ($total > self::MAX_BYTES) {
                throw ValidationException::withMessages(['export' => 'This export exceeds the current safe size limit. Contact the privacy team for an assisted export.']);
            }
            $zip->addFromString($name, $bytes);
            $files[] = ['file' => $name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        };
        $add('README.txt', "Private WeavDash export. See manifest.json for scope, checksums and exclusions.\n");
        chmod($path, 0600);
        foreach ($data as $category => $rows) {
            $add('data/'.$category.'.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $file = fopen('php://temp', 'w+');
            if ($rows !== []) {
                fputcsv($file, array_keys($rows[0]), escape: '');
                foreach ($rows as $row) {
                    fputcsv($file, array_map(function ($value) {
                        $value = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;

                        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
                    }, $row), escape: '');
                }
            }
            rewind($file);
            $csv = stream_get_contents($file);
            fclose($file);
            $add('data/'.$category.'.csv', $csv);
        }
        foreach ($data['onboarding_documents'] ?? [] as $doc) {
            if ($doc['scan_state'] !== 'clean') {
                $excluded[] = ['document_id' => $doc['id'], 'reason' => 'Document is not scanned clean.'];

                continue;
            }
            // Metadata stays in the current authorized snapshot; keys never enter the archive.
            $key = DB::table('onboarding_documents')->where('id', $doc['id'])->value('object_key');
            $store = Storage::disk('onboarding');
            abort_unless($key && $store->size($key) === (int) $doc['size'] && (int) $doc['size'] <= 2097152, 409);
            $bytes = $store->get($key);
            abort_unless(hash_equals($doc['checksum'], hash('sha256', $bytes)), 409, 'A document changed. Create a fresh export.');
            $extension = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt'][$doc['mime']];
            $add('documents/'.$doc['id'].'.'.$extension, $bytes);
        }
        $manifest = [
            'schema_version' => 1, 'scope' => $scope, 'generated_at' => now()->toIso8601String(),
            'counts' => array_map('count', $data), 'files' => $files, 'excluded_documents' => $excluded,
            'exclusions' => [
                'Passwords, MFA secrets, recovery codes, sessions, token hashes and reusable link codes.',
                'Internal mail payloads, storage keys, platform audit/support records and accounting command hashes.',
                $scope === 'workspace' ? 'Members’ account data, private automation/drafts, operational logs and security history.' : 'Workspace business records and records outside current workspace access; request a separate owner export where authorized.',
                'Provider-held mail, off-host backups and copies already downloaded by other people.',
                'Finance/audit records remain in the service under existing retention protections; export does not erase data.',
            ],
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        unset($zip);
        abort_unless($disk->size($id.'.part.zip') <= self::MAX_BYTES + 1024 * 1024, 413);
        $disk->move($id.'.part.zip', $id.'.zip');

        return ['checksum' => hash_file('sha256', $disk->path($id.'.zip')), 'size' => $disk->size($id.'.zip')];
    }
}
