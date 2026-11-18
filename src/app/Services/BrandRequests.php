<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An applicant's own Brand workspace requests. Rules are enforced in PostgreSQL; a request never creates a workspace. */
class BrandRequests
{
    public const FIELDS = ['brand_name', 'website', 'applicant_role', 'intended_use'];

    public function __construct(private TenantContext $context) {}

    public function listing(int $actorId): array
    {
        return $this->context->run($actorId, null, fn () => $this->json('SELECT app_brand_requests_mine() AS data'));
    }

    public function detail(int $actorId, string $id): array
    {
        return $this->context->run($actorId, null, fn () => $this->json('SELECT app_brand_request_mine(?::uuid) AS data', [$id]));
    }

    public function submit(int $actorId, array $fields): string
    {
        $id = (string) Str::uuid();
        $this->context->run($actorId, null, function () use ($actorId, $id, $fields): void {
            DB::select('SELECT app_brand_request_submit(?::uuid,?,?,?,?)', [$id, ...$this->values($fields)]);
            // The Brand registration intent is fulfilled by the request, in the same transaction.
            DB::table('users')->where('id', $actorId)->where('registration_workspace_kind', 'brand')
                ->update(['registration_workspace_id' => null, 'registration_workspace_kind' => null, 'registration_workspace_experience' => null]);
        });

        return $id;
    }

    public function revise(int $actorId, string $id, int $expected, array $fields): int
    {
        return $this->context->run($actorId, null, fn () => (int) DB::selectOne('SELECT app_brand_request_revise(?::uuid,?,?,?,?,?) AS version', [$id, $expected, ...$this->values($fields)])->version);
    }

    private function values(array $fields): array
    {
        return array_map(fn (string $field) => trim((string) $fields[$field]), self::FIELDS);
    }

    private function json(string $sql, array $bindings = []): array
    {
        return json_decode(DB::selectOne($sql, $bindings)->data, true, flags: JSON_THROW_ON_ERROR);
    }
}
