<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OnboardingDocuments
{
    public function __construct(private Onboarding $onboarding, private UploadScanner $scanner) {}

    public function upload(string $org, string $request, int $version, UploadedFile $file): string
    {
        $r = $this->onboarding->locked($org, $request, $version);
        abort_unless(in_array($r->status, ['draft', 'correction'], true), 409);
        if (DB::table('onboarding_documents')->where('org_id', $org)->where('request_id', $request)->count() >= 5) {
            throw ValidationException::withMessages(['file' => 'Up to five files per request. Remove unused files first.']);
        }
        $id = (string) Str::uuid();
        $key = 'onboarding/'.$org.'/'.$request.'/'.$id;
        $bytes = $file->get();
        $name = mb_substr(preg_replace('/[\\x00-\\x1f\\x7f\\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 120);
        abort_unless(Storage::disk('onboarding')->put($key, $bytes), 503);
        try {
            DB::table('onboarding_documents')->insert(['id' => $id, 'org_id' => $org, 'request_id' => $request, 'name' => $name ?: 'attachment',
                'object_key' => $key, 'checksum' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mime' => $file->getMimeType(), 'scan_state' => 'pending']);
            DB::table('onboarding_requests')->where('org_id', $org)->where('id', $request)->update(['version' => $version + 1]);
        } catch (Throwable $e) {
            Storage::disk('onboarding')->delete($key);
            throw $e;
        }
        $this->scan($org, $request, $id);

        return $id;
    }

    public function scan(string $org, string $request, string $id): string
    {
        $row = $this->row($org, $request, $id);
        $bytes = Storage::disk('onboarding')->get($row->object_key);
        $state = hash_equals($row->checksum, hash('sha256', $bytes)) ? $this->scanner->scan($bytes) : 'rejected';
        DB::table('onboarding_documents')->where('org_id', $org)->where('id', $id)->update(['scan_state' => $state]);

        return $state;
    }

    public function download(string $org, string $request, string $id): array
    {
        $row = $this->row($org, $request, $id);
        abort_unless($row->scan_state === 'clean', 403);
        $bytes = Storage::disk('onboarding')->get($row->object_key);
        abort_unless(hash_equals($row->checksum, hash('sha256', $bytes)), 403);

        return [$bytes, $row->name];
    }

    public function remove(string $org, string $request, string $id, int $version): void
    {
        $r = $this->onboarding->locked($org, $request, $version);
        abort_unless(in_array($r->status, ['draft', 'correction'], true), 409);
        $row = $this->row($org, $request, $id);
        $used = DB::table('onboarding_submissions')->where('org_id', $org)->where('request_id', $request)
            ->whereRaw("snapshot->'documents' @> ?::jsonb", [json_encode([['id' => $id]])])->exists();
        abort_if($used, 409);
        DB::table('onboarding_documents')->where('org_id', $org)->where('id', $id)->delete();
        DB::table('onboarding_requests')->where('org_id', $org)->where('id', $request)->update(['version' => $version + 1]);
        DB::afterCommit(fn () => Storage::disk('onboarding')->delete($row->object_key));
    }

    private function row(string $org, string $request, string $id): object
    {
        $row = DB::table('onboarding_documents')->where('org_id', $org)->where('request_id', $request)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
