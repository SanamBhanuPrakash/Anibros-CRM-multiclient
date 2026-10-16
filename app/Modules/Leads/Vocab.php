<?php
declare(strict_types=1);

namespace App\Modules\Leads;

/** Fixed vocabularies of the leads module: reason codes, work channels, activity types/outcomes, lifecycle statuses. */
final class Vocab
{
    public const STATUSES = ['open', 'contacted', 'qualified', 'viewing_scheduled', 'negotiation', 'won', 'lost', 'disqualified'];
    public const CLOSED = ['won', 'lost', 'disqualified'];
    public const PROPERTY_TYPES = ['apartment', 'villa', 'townhouse', 'penthouse', 'office', 'retail', 'plot', 'other'];
    public const GATE_FIELDS = ['ownerId', 'estimatedValue', 'budgetMax', 'phone', 'email', 'nextFollowupAt'];

    public const ACTIVITY_TYPES = ['note', 'call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting'];
    public const ACTIVITY_DIRECTIONS = ['outbound', 'inbound'];
    /** What happened when an employee reached out. Stable codes (stored, exported, counted); labels are for screens. */
    public const ACTIVITY_OUTCOMES = [
        ['code' => 'connected', 'label' => 'Spoke to them', 'forTypes' => ['call', 'meeting']],
        ['code' => 'no_answer', 'label' => 'No answer', 'forTypes' => ['call']],
        ['code' => 'busy', 'label' => 'Busy / call back', 'forTypes' => ['call']],
        ['code' => 'voicemail', 'label' => 'Left a voicemail', 'forTypes' => ['call']],
        ['code' => 'wrong_number', 'label' => 'Wrong number', 'forTypes' => ['call', 'whatsapp', 'sms']],
        ['code' => 'interested', 'label' => 'Interested', 'forTypes' => ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting']],
        ['code' => 'not_interested', 'label' => 'Not interested', 'forTypes' => ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting']],
        ['code' => 'appointment_booked', 'label' => 'Appointment / visit booked', 'forTypes' => ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting']],
        ['code' => 'sent', 'label' => 'Message sent', 'forTypes' => ['whatsapp', 'sms', 'email', 'instagram']],
        ['code' => 'replied', 'label' => 'They replied', 'forTypes' => ['whatsapp', 'sms', 'email', 'instagram']],
    ];

    /** What kinds of work an employee may log inside a client. NULL on the membership means everything; a specialist has a short list. Notes are always allowed. */
    public const WORK_CHANNELS = ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting'];
    public const WORK_CHANNEL_LABEL = ['call' => 'phone calls', 'whatsapp' => 'WhatsApp', 'sms' => 'text messages', 'email' => 'e-mail', 'instagram' => 'Instagram messages', 'meeting' => 'visits and meetings'];

    /** Reasons for closing a lead without a sale. lost = a real buyer who did not buy from us; not_a_fit = there never was a buyer. */
    public const REASON_CODES = [
        'lost' => [
            ['code' => 'bought_elsewhere', 'label' => 'Bought elsewhere'], ['code' => 'price_too_high', 'label' => 'Price too high'], ['code' => 'financing', 'label' => 'Could not get financing'],
            ['code' => 'timing', 'label' => 'Timing did not work'], ['code' => 'changed_mind', 'label' => 'Changed their mind'], ['code' => 'no_response', 'label' => 'Stopped responding'], ['code' => 'other', 'label' => 'Other (explain)'],
        ],
        'not_a_fit' => [
            ['code' => 'spam', 'label' => 'Spam or junk'], ['code' => 'wrong_number', 'label' => 'Wrong number or unreachable'], ['code' => 'duplicate', 'label' => 'Duplicate of another lead'],
            ['code' => 'not_a_buyer', 'label' => 'Not a buyer (agent, vendor, job seeker)'], ['code' => 'test', 'label' => 'Test enquiry'], ['code' => 'other', 'label' => 'Other (explain)'],
        ],
    ];

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }

    /** Which reason catalogue a lifecycle status closes into, if any. */
    public static function reasonKindOf(string $status): ?string
    {
        return $status === 'lost' ? 'lost' : ($status === 'disqualified' ? 'not_a_fit' : null);
    }

    /** @return array{code:string,label:string}|null */
    public static function findReason(string $kind, string $code): ?array
    {
        foreach (self::REASON_CODES[$kind] as $r) if ($r['code'] === $code) return $r;
        return null;
    }

    /** Label for a stored code in either catalogue ("No reason recorded" for legacy rows that never had one). */
    public static function reasonLabel(?string $code): string
    {
        if (!$code) return 'No reason recorded';
        return self::findReason('lost', $code)['label'] ?? self::findReason('not_a_fit', $code)['label'] ?? str_replace('_', ' ', $code);
    }

    public static function channelOf(string $activityType): ?string
    {
        return in_array($activityType, self::WORK_CHANNELS, true) ? $activityType : null;
    }

    /** Words for an error message: "WhatsApp and phone calls". @param string[] $channels */
    public static function describeChannels(array $channels): string
    {
        $names = array_map(static fn($c) => self::WORK_CHANNEL_LABEL[$c] ?? $c, $channels);
        if (count($names) <= 1) return $names[0] ?? 'nothing';
        return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[count($names) - 1];
    }
}
