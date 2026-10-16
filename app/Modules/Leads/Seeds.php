<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Ids;
use App\Core\Json;
use App\Core\Tx;

/** Workspace seed data: the recommended sales pipeline and the lead-scoring configuration (the SQL seed functions of the Node project). */
final class Seeds
{
    /** key, name, order, colour, maps_to_status, probability */
    public const BEGINNER_STAGES = [
        ['new', 'New enquiry', 0, '#6F86A3', 'open', 5],
        ['contact_attempted', 'Trying to reach', 1, '#8A7F5C', 'open', 8],
        ['contacted', 'In conversation', 2, '#7C8C8A', 'contacted', 15],
        ['qualified', 'Qualified', 3, '#6B8F71', 'qualified', 30],
        ['viewing_scheduled', 'Site visit booked', 4, '#8F7A9B', 'viewing_scheduled', 45],
        ['viewing_completed', 'Site visit done', 5, '#7A8FA6', 'viewing_scheduled', 55],
        ['negotiation', 'Negotiating', 6, '#9A7B6B', 'negotiation', 70],
        ['booking_reserved', 'Booked', 7, '#B08D57', 'negotiation', 90],
        ['follow_up_later', 'Follow up later', 8, '#A3A3A3', 'open', 10],
        ['won', 'Won', 9, '#2E7D4F', 'won', 100],
        ['lost', 'Lost', 10, '#B3382C', 'lost', 0],
        ['not_a_fit', 'Not a fit', 11, '#6B6B66', 'disqualified', 0],
    ];

    /** Default status/probability of the well-known stage keys (used when a stage is created with a known key and no explicit values). */
    public const STAGE_DEFAULTS = [
        'new' => ['open', 5], 'contact_attempted' => ['open', 8], 'contacted' => ['contacted', 15], 'qualified' => ['qualified', 30],
        'viewing_scheduled' => ['viewing_scheduled', 45], 'viewing_completed' => ['viewing_scheduled', 55],
        'negotiation' => ['negotiation', 70], 'booking_reserved' => ['negotiation', 90], 'won' => ['won', 100],
        'lost' => ['lost', 0], 'invalid' => ['disqualified', 0], 'unqualified' => ['disqualified', 0], 'nurture' => ['open', 10],
    ];

    /** Returns the id of the workspace's beginner pipeline, creating it (with stages and transition rules) when missing. */
    public static function beginnerPipeline(Tx $tx, string $tenantId, string $name = 'Sales pipeline', bool $makeDefault = true): string
    {
        $existing = $tx->val("SELECT id FROM pipelines WHERE tenant_id = ? AND template = 'beginner_v1' AND archived_at IS NULL", [$tenantId]);
        if ($existing) return (string) $existing;
        if ($makeDefault) $tx->run('UPDATE pipelines SET is_default = 0 WHERE tenant_id = ? AND is_default = 1 AND archived_at IS NULL', [$tenantId]);
        $pipelineId = $tx->insert('pipelines', [
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => 'The recommended sales process for a real-estate team. Any stage can follow any other; a lead only becomes Won after Booked.',
            'is_default' => $makeDefault ? 1 : 0,
            'template' => 'beginner_v1',
            'reason_codes_required' => 1,
            'deal_value_required' => 1,
        ]);
        $stages = [];
        foreach (self::BEGINNER_STAGES as [$key, $sname, $ord, $color, $status, $prob]) {
            $id = $tx->insert('pipeline_stages', ['tenant_id' => $tenantId, 'pipeline_id' => $pipelineId, 'name' => $sname, 'stage_key' => $key, 'order_index' => $ord, 'color' => $color, 'maps_to_status' => $status, 'probability' => $prob]);
            $stages[$key] = ['id' => $id, 'status' => $status];
        }
        // free movement between open stages and into the two outcomes; Won only from Booked; Follow up later needs a date
        foreach ($stages as $fk => $f) {
            if (in_array($f['status'], ['won', 'lost', 'disqualified'], true)) continue;
            foreach ($stages as $tk => $t) {
                if ($tk === $fk || $tk === 'won') continue;
                $tx->insert('pipeline_stage_transitions', [
                    'tenant_id' => $tenantId, 'pipeline_id' => $pipelineId, 'from_stage_id' => $f['id'], 'to_stage_id' => $t['id'],
                    'from_stage_key' => $fk, 'to_stage_key' => $tk, 'is_allowed' => 1,
                    'required_fields' => $tk === 'follow_up_later' ? Json::enc(['nextFollowupAt']) : '[]',
                ]);
            }
        }
        $tx->insert('pipeline_stage_transitions', [
            'tenant_id' => $tenantId, 'pipeline_id' => $pipelineId, 'from_stage_id' => $stages['booking_reserved']['id'], 'to_stage_id' => $stages['won']['id'],
            'from_stage_key' => 'booking_reserved', 'to_stage_key' => 'won', 'is_allowed' => 1, 'required_fields' => '[]',
        ]);
        return $pipelineId;
    }

    public static function scoringConfig(Tx $tx, string $tenantId): void
    {
        $tx->run('INSERT IGNORE INTO lead_scoring_config (id, tenant_id) VALUES (?, ?)', [Ids::uuid(), $tenantId]);
    }
}
