<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit projections only. Called inside a repeatable-read, restricted-role tenant transaction. */
class PrivacyExportData
{
    public const MAX_ROWS = 10000;

    public const MAX_DATA_BYTES = 8 * 1024 * 1024;

    private int $rows = 0;

    private int $bytes = 0;

    public function collect(int $actor, ?string $org): array
    {
        $this->rows = $this->bytes = 0;
        if ($org !== null) {
            return $this->workspace($org);
        }
        $data = [
            'account' => $this->read(DB::table('users')->where('id', $actor), 'id,name,email,email_verified_at,appearance,created_at'),
            'memberships' => $this->read(DB::table('memberships')->where('user_id', $actor)->whereNull('revoked_at'), 'org_id,role,created_at', ['org_id']),
            'security_events' => $this->read(DB::query()->fromRaw('app_privacy_account_events()'), 'id,event,occurred_at'),
            'brand_requests' => $this->read(DB::table('brand_requests')->where('applicant_id', $actor), 'id,brand_name,website,applicant_role,intended_use,status,version,submitted_at,updated_at,decided_at'),
        ];
        foreach ($data['memberships'] as $member) {
            $org = $member['org_id'];
            DB::selectOne("SELECT set_config('app.org_id',?,true)", [$org]);
            // Suspended/revoked workspace data is omitted; the membership record itself belongs to this account.
            if (! DB::selectOne('SELECT app_lock_membership(?::uuid) AS allowed', [$org])->allowed) {
                continue;
            }
            $data['preferences_'.$org] = $this->read(DB::table('automation_preferences')->where('org_id', $org)->where('actor_id', $actor), 'org_id,timezone,reminders,weekly_digest,follow_up_drafts,version,updated_at', ['org_id']);
            foreach ([
                'business_schedules' => 'id,name,kind,subject_id,version,timezone,weekday,local_time,next_due_at,enabled,consented_at,revoked_at,created_at,updated_at',
                'schedule_runs' => 'id,schedule_id,schedule_version,due_at,state,created_at,completed_at',
                'automation_consent_events' => 'id,schedule_id,version,kind,granted,occurred_at',
                'follow_up_drafts' => 'id,pipeline_entry_id,recipient,subject,body,state,version,approved_version,approved_at,created_at,updated_at',
                'notification_outbox' => 'id,kind,state,created_at,started_at,completed_at',
            ] as $table => $columns) {
                $data[$table.'_'.$org] = $this->read(DB::table($table)->where('org_id', $org)->where('actor_id', $actor), $columns);
            }
        }
        DB::selectOne("SELECT set_config('app.org_id','',true)");

        return $data;
    }

    private function workspace(string $org): array
    {
        DB::select('SELECT app_privacy_lock_sharing()');
        $data = [];
        $tables = [
            'organizations' => 'id,name,kind,experience,created_at,updated_at',
            'pipeline_entries' => 'id,brand_name,contact_name,contact_email,notes,stage,value_minor,currency,next_action_due,version,created_at,updated_at',
            'pipeline_activities' => 'id,pipeline_entry_id,kind,body,occurred_at',
            'brand_contacts' => 'id,name,email,company,notes,version,created_at,updated_at',
            'reminders' => 'id,pipeline_entry_id,title,due_at,completed_at,version,created_at,updated_at',
            'pipeline_contacts' => 'pipeline_entry_id,contact_id',
            'outreach_templates' => 'id,name,subject,body,version,created_at,updated_at',
            'media_kits' => 'id,title,bio,website,social_links,published,consented_at,views,version',
            'lead_links' => 'id,expires_at,revoked_at,created_at',
            'onboarding_requests' => 'id,pipeline_entry_id,label,status,business_name,contact_name,business_email,website,brief,correction_note,expires_at,version,created_at',
            'onboarding_submissions' => 'id,request_id,revision,snapshot,consent_at',
            'onboarding_events' => 'id,request_id,kind,occurred_at',
            'onboarding_documents' => 'id,request_id,name,checksum,size,mime,scan_state',
            'partner_shared_profiles' => 'org_id,contact_name,contact_email,website_url,description,version,updated_at',
            'invoice_drafts' => 'id,recipient_name,currency,due_date,status,total_minor,version,created_at,updated_at',
            'invoice_draft_lines' => 'invoice_id,position,description,quantity,unit_price_minor,total_minor',
            'manual_payments' => 'id,currency,amount_minor,received_on,evidence_reference,correction_of,status,version,created_at',
            'payment_allocations' => 'id,payment_id,invoice_id,amount_minor,created_at',
            'payment_allocation_reversals' => 'id,allocation_id,reason,created_at',
            'pilot_assignments' => 'id,plan_id,version,starts_at,ends_at,created_at',
            'audit_logs' => 'id,event,subject_id,occurred_at',
        ];
        foreach ($tables as $table => $columns) {
            $query = DB::table($table)->where($table === 'organizations' ? 'id' : 'org_id', $org);
            $order = match ($table) {
                'pipeline_contacts' => ['pipeline_entry_id', 'contact_id'],
                'invoice_draft_lines' => ['invoice_id', 'position'],
                'partner_shared_profiles' => ['org_id'],
                default => ['id'],
            };
            $data[$table] = $this->read($query, $columns, $order);
        }
        // Never export an unreviewed nested JSON property, even if old/imported snapshots contain one.
        foreach ($data['onboarding_submissions'] as &$submission) {
            $snapshot = json_decode($submission['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $safe = array_intersect_key($snapshot, array_flip(['business_name', 'contact_name', 'business_email', 'website', 'brief']));
            $safe['documents'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'checksum', 'scan_state'])), $snapshot['documents'] ?? []);
            $submission['snapshot'] = $safe;
        }
        unset($submission);
        foreach ($data['media_kits'] as &$kit) {
            $kit['social_links'] = array_map(fn ($row) => array_intersect_key($row, array_flip(['label', 'url'])), json_decode($kit['social_links'], true, flags: JSON_THROW_ON_ERROR));
        }
        unset($kit);
        $data['partner_invitations'] = $this->read(DB::table('partner_invitations')->where('brand_org_id', $org), 'id,recipient_email,status,expires_at,created_at');
        $data['partner_join_links'] = $this->read(DB::table('partner_join_links')->where('brand_org_id', $org), 'id,created_at,revoked_at');
        $data['partnerships'] = $this->read(DB::table('partnerships')->where(fn ($q) => $q->where('brand_org_id', $org)->orWhere('creator_org_id', $org)), 'id,brand_org_id,creator_org_id,brand_name,creator_name,status,accepted_at,revoked_at');
        $data['lead_registrations'] = $this->read(DB::table('lead_registrations')->where(fn ($q) => $q->where('brand_org_id', $org)->orWhere('creator_org_id', $org)), 'id,partnership_id,display_name,summary,share_contact,contact_name,contact_email,stage,state,withdrawn_at,registered_at,shared_updated_at,version');
        // RLS includes owned drafts and currently shared briefs. Preserve older archive fingerprints when empty.
        $campaigns = $this->read(DB::table('campaigns'), 'id,title,brief,due_date,partnership_id,version,created_at');
        if ($campaigns) {
            $data['campaigns'] = $campaigns;
        }
        $data['shared_partner_profiles'] = $this->read(DB::query()->fromRaw('(SELECT p.id,app_partner_shared_profile(p.id) AS profile FROM partnerships p WHERE p.brand_org_id=?::uuid) AS shared', [$org]), 'id,profile');
        foreach ($data['shared_partner_profiles'] as &$profile) {
            $profile['profile'] = $profile['profile'] === null ? null : json_decode($profile['profile'], true, flags: JSON_THROW_ON_ERROR);
        }
        unset($profile);
        $data['pilot_plan_versions'] = $this->read(DB::table('pilot_plan_versions')->whereIn('id', array_column($data['pilot_assignments'], 'plan_id')), 'id,name,version,quotas,created_at');
        $data['business_notification_history'] = $this->read(DB::table('notification_outbox')->where('org_id', $org)
            ->whereIn('kind', ['partner.invitation', 'onboarding.invitation', 'onboarding.submitted', 'onboarding.corrected', 'onboarding.accepted']), 'id,kind,state,created_at,started_at,completed_at');

        return $data;
    }

    /** Bounded chunks in one database snapshot; never silently truncate to a screen's pagination limit. */
    private function read(Builder $query, string $columns, array $order = ['id']): array
    {
        $result = [];
        foreach ($order as $column) {
            $query->orderBy($column);
        }
        $last = null;
        while (true) {
            $page = clone $query;
            if ($last !== null) {
                $page->whereRaw('('.implode(',', $order).') > ('.implode(',', array_fill(0, count($order), '?')).')', array_map(fn ($key) => $last[$key], $order));
            }
            $batch = $page->limit(250)->get(explode(',', $columns));
            foreach ($batch as $row) {
                $value = (array) $row;
                $this->bytes += strlen(json_encode($value, JSON_THROW_ON_ERROR));
                if (++$this->rows > self::MAX_ROWS || $this->bytes > self::MAX_DATA_BYTES) {
                    throw ValidationException::withMessages(['export' => 'This export exceeds the current safe size limit. Contact the privacy team for an assisted export.']);
                }
                $result[] = $value;
                $last = $value;
            }
            if ($batch->count() < 250) {
                return $result;
            }
        }
    }
}
