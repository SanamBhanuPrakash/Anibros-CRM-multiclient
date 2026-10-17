<?php
declare(strict_types=1);

namespace App\Modules\Inbound;

use App\Core\Ids;
use App\Modules\Contacts\Normalize;

final class Providers
{
    public const PROVIDERS = ['meta', 'google', 'website', 'generic'];
    public const STATUSES = ['received', 'created', 'duplicate', 'needs_review', 'ignored', 'failed'];

    private const EMAIL_KEYS = ['email', 'email_address', 'work_email', 'e_mail', 'mail'];
    private const PHONE_KEYS = ['phone_number', 'phone', 'mobile', 'mobile_number', 'work_phone_number', 'whatsapp', 'whatsapp_number', 'contact_number', 'telephone', 'tel'];
    private const NAME_KEYS = ['full_name', 'fullname', 'name', 'your_name', 'contact_name', 'customer_name'];
    private const FIRST_KEYS = ['first_name', 'firstname', 'first', 'given_name'];
    private const LAST_KEYS = ['last_name', 'lastname', 'surname', 'family_name'];

    public static function clean(mixed $value, int $max = 500): string
    {
        if ($value === null || is_array($value) || is_object($value)) return '';
        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) return '';
        $text = (string) $value;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_substr(trim($text), 0, $max);
    }

    public static function parseBody(string $raw, ?string $contentType): array
    {
        if (trim($raw) === '') throw new BadPayload('Empty body');
        $type = strtolower(trim(explode(';', (string) $contentType, 2)[0]));
        if ($type === 'application/x-www-form-urlencoded') {
            $out = [];
            foreach (explode('&', $raw) as $pair) {
                if ($pair === '') continue;
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $key = urldecode(str_replace('+', ' ', $key));
                if (strlen($key) <= 100 && !array_key_exists($key, $out)) $out[$key] = urldecode(str_replace('+', ' ', $value));
            }
            return $out;
        }
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadPayload('Body is not valid JSON');
        }
        if (!$decoded instanceof \stdClass) throw new BadPayload('Body must be a JSON object');
        $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        return $body;
    }

    public static function verifyMeta(string $raw, ?string $signature, string $secret): bool
    {
        if ($signature === null || !str_starts_with($signature, 'sha256=')) return false;
        return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower(substr($signature, 7)));
    }

    public static function verifyGeneric(string $raw, ?string $signature, string $secret, ?int $now = null): bool
    {
        if ($signature === null) return false;
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) return false;
            $parts[$pair[0]] = $pair[1];
        }
        $timestampText = $parts['t'] ?? '';
        $signatureHash = $parts['v1'] ?? '';
        if (!ctype_digit($timestampText) || !preg_match('/^[a-f0-9]{64}$/i', $signatureHash)) return false;
        $timestamp = (int) $timestampText;
        if (abs(($now ?? time()) - $timestamp) > 300) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), strtolower($signatureHash));
    }

    public static function extract(string $provider, array $body, string $raw, array $headers): array
    {
        if ($provider === 'meta') {
            if (($body['object'] ?? null) !== 'page' || !is_array($body['entry'] ?? null)) throw new BadPayload('Not a Meta page webhook');
            $events = [];
            foreach (array_slice($body['entry'], 0, 50) as $entry) {
                if (!is_array($entry) || !is_array($entry['changes'] ?? null)) continue;
                foreach (array_slice($entry['changes'], 0, 100) as $change) {
                    $v = $change['value'] ?? null;
                    if (!is_array($change) || ($change['field'] ?? null) !== 'leadgen' || !is_array($v)) continue;
                    $id = self::clean($v['leadgen_id'] ?? null, 100);
                    if ($id === '') continue;
                    $payload = ['leadgen' => $v, 'pageId' => self::clean($entry['id'] ?? null, 100)];
                    if (is_array($v['lead'] ?? null)) $payload['lead'] = $v['lead'];
                    $events[] = ['eventId' => $id, 'payload' => $payload, 'isTest' => ($v['is_test'] ?? false) === true];
                }
            }
            return $events;
        }
        if ($provider === 'google') {
            $id = self::clean($body['lead_id'] ?? null, 200);
            if ($id === '') throw new BadPayload('Missing lead_id');
            unset($body['google_key']);
            return [['eventId' => $id, 'payload' => $body, 'isTest' => ($body['is_test'] ?? false) === true]];
        }
        if ($provider === 'website') {
            unset($body['_hp'], $body['website_url']);
            $id = self::clean($body['eventId'] ?? $body['event_id'] ?? null, 100) ?: Ids::uuid();
            return [['eventId' => $id, 'payload' => $body, 'isTest' => false]];
        }
        if ($provider === 'generic') {
            $id = self::clean($headers['x-event-id'] ?? null, 100)
                ?: self::clean($body['eventId'] ?? $body['event_id'] ?? $body['id'] ?? null, 100)
                ?: hash('sha256', $raw);
            return [['eventId' => $id, 'payload' => $body, 'isTest' => ($body['is_test'] ?? $body['isTest'] ?? false) === true]];
        }
        throw new BadPayload('Unsupported provider');
    }

    public static function honeypot(array $body): bool
    {
        return self::clean($body['_hp'] ?? null, 10) !== '' || self::clean($body['website_url'] ?? null, 10) !== '';
    }

    /**
     * Normalize common lead-form names, retaining unmapped answers and provenance.
     * @return array<string,mixed>
     */
    public static function normalize(string $provider, array $payload, array $config, bool $isTest): array
    {
        if ($provider === 'meta' && !is_array($payload['lead'] ?? null)) throw new BadPayload('Meta lead details are missing');
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : $payload;
        $values = [];
        foreach (['field_data', 'user_data', 'user_column_data'] as $key) {
            if (is_array($lead[$key] ?? null)) {
                foreach ($lead[$key] as $item) {
                    if (!is_array($item)) continue;
                    $name = self::key(self::clean($item['name'] ?? $item['column_id'] ?? '', 100));
                    $vals = $item['values'] ?? null;
                    $value = is_array($vals) ? ($vals[0] ?? null) : ($item['string_value'] ?? $item['value'] ?? null);
                    if ($name && $value !== null) $values[$name] = self::clean($value, 2000);
                }
            }
        }
        $walk = static function (array $node, string $prefix = '', int $depth = 0) use (&$walk, &$values): void {
            foreach ($node as $k => $v) {
                if (count($values) >= 120) break;
                $key = $prefix === '' ? (string) $k : $prefix . '_' . $k;
                if (is_array($v) && !array_is_list($v) && $depth < 3) $walk($v, $key, $depth + 1);
                elseif (is_scalar($v) || is_array($v)) {
                    $flat = is_array($v) ? implode(', ', array_filter(array_map([self::class, 'clean'], $v))) : self::clean($v, 2000);
                    $normalized = self::key($key);
                    if ($normalized !== '' && $flat !== '' && !in_array($normalized, ['lead', 'field_data', 'user_data'], true)) $values[$normalized] = $flat;
                }
            }
        };
        $walk($lead);
        if (is_array($payload['leadgen'] ?? null)) {
            foreach ($payload['leadgen'] as $k => $v) if (!is_array($v) && $v !== null) $values[self::key((string) $k)] = self::clean($v, 200);
        }
        $fieldMap = is_array($config['fieldMap'] ?? null) ? $config['fieldMap'] : [];
        if ($provider === 'generic') foreach ($fieldMap as $source => $target) {
            $sourceKey = self::key((string) $source);
            $targetKey = self::key((string) $target);
            if ($sourceKey && $targetKey && isset($values[$sourceKey])) $values[$targetKey] = $values[$sourceKey];
        }

        $first = self::take($values, self::FIRST_KEYS) ?? self::takeLike($values, '/(^|_)(first|given|f)_?name($|_)|^fname$/');
        $last = self::take($values, self::LAST_KEYS) ?? self::takeLike($values, '/(^|_)(last|sur|family|l)_?name($|_)|^lname$/');
        $full = self::take($values, self::NAME_KEYS) ?? self::takeLike($values, '/(^|_)name($|_)/', '/(company|business|organi[sz]ation|brand|project|property|file|user_?name|login|city|state|country|subject|page|form|campaign)/');
        if (!$first && $full) {
            $parts = preg_split('/\\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = array_shift($parts) ?: null;
            $last ??= $parts ? implode(' ', $parts) : null;
        }
        $emailRaw = self::take($values, self::EMAIL_KEYS);
        $email = $emailRaw;
        if (!$email) $email = self::takeLike($values, '/e_?mail/');
        if (!$email) foreach ($values as $key => $value) if (filter_var($value, FILTER_VALIDATE_EMAIL)) { $email = $value; unset($values[$key]); break; }
        $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
        $phoneRaw = self::take($values, self::PHONE_KEYS);
        if ($phoneRaw === null) $phoneRaw = self::takeLike($values, '/phone|mobile|whats_?app|contact_?(no|number|num)|(^|_)tel($|_)|(^|_)cell($|_)|(^|_)number$/', '/fax|pin|zip|postal|otp|code|id$|^id_/');
        $phone = $phoneRaw;
        if (!$phone) foreach ($values as $key => $value) if (!preg_match('/budget|price|amount|salary|income|zip|pin|postal|year|age|date|id$|^id_|utm_|gclid|fbclid|otp|code/', $key) && preg_match('/^\\+?[\\d\\s().-]{7,22}$/', $value) && strlen(preg_replace('/\\D/', '', $value) ?? '') >= 7 && strlen(preg_replace('/\\D/', '', $value) ?? '') <= 15) { $phone = $value; break; }
        if ($phone && (strlen(preg_replace('/\\D/', '', $phone) ?? '') < 7 || strlen(preg_replace('/\\D/', '', $phone) ?? '') > 15)) $phone = null;
        $invalidEmail = $emailRaw !== null && !filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : null;
        $invalidPhone = $phoneRaw !== null && (!$phone || strlen(preg_replace('/\\D/', '', $phone) ?? '') < 7) ? $phoneRaw : null;
        $message = self::take($values, ['message', 'your_message', 'comments', 'comment', 'enquiry', 'inquiry', 'query', 'details', 'description', 'note', 'notes', 'question', 'text'])
            ?? self::takeLike($values, '/message|comment|enquir|inquir|requirement|question|how_can|help_you|details|brief|query|msg|tell_us|notes?/', null, true);

        $providerCampaign = $provider === 'google'
            ? (self::clean($payload['campaign_name'] ?? '', 150) ?: (!empty($payload['campaign_id']) ? 'Google campaign ' . self::clean($payload['campaign_id'], 50) : ''))
            : ($provider === 'website' ? ($lead['utm_campaign'] ?? '') : ($lead['campaign_name'] ?? $lead['campaign'] ?? ''));
        $campaign = self::clean($config['campaign'] ?? $providerCampaign, 200) ?: null;
        $allowedSources = ['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other'];
        $source = match ($provider) {
            'meta' => 'meta_ads',
            'google' => 'google_ads',
            'website' => in_array($config['source'] ?? 'website', $allowedSources, true) ? ($config['source'] ?? 'website') : 'website',
            default => in_array($config['source'] ?? 'other', $allowedSources, true) ? ($config['source'] ?? 'other') : 'other',
        };
        $tags = Normalize::tags(is_array($config['tags'] ?? null) ? $config['tags'] : []);
        if ($isTest && !in_array('test', $tags, true)) $tags[] = 'test';
        $answers = [];
        $details = [];
        if ($invalidEmail !== null) $answers[] = ['label' => 'Email (as entered)', 'value' => $invalidEmail];
        if ($invalidPhone !== null) $answers[] = ['label' => 'Phone (as entered)', 'value' => $invalidPhone];
        $budgetText = self::take($values, ['budget', 'max_budget', 'budget_max', 'your_budget']);
        $budgetMax = $budgetText === null ? null : self::parseMoney($budgetText);
        if ($budgetText !== null && $budgetMax === null) $answers[] = ['label' => 'Budget', 'value' => $budgetText];
        $bedroomsText = self::take($values, ['bedrooms', 'bhk', 'beds', 'bedroom', 'configuration', 'preferred_configuration']);
        $bedrooms = null;
        if ($bedroomsText !== null) {
            if (preg_match('/\\b([1-9]|1\\d|20)\\b/', $bedroomsText, $bm)) $bedrooms = (int) $bm[1];
            else $answers[] = ['label' => 'Configuration', 'value' => $bedroomsText];
        }
        $locations = [];
        foreach (['location', 'preferred_location', 'locality', 'city', 'area', 'preferred_area'] as $locationKey) {
            $location = self::take($values, [$locationKey]);
            if ($location !== null) $locations[] = mb_substr($location, 0, 100);
        }
        $tracking = [];
        $context = [];
        if ($provider === 'google') {
            $gclid = self::clean($payload['gcl_id'] ?? $payload['gclid'] ?? '', 200);
            if ($gclid !== '') $details['gclid'] = $gclid;
        }
        foreach ($values as $key => $value) {
            if (preg_match('/pass(word|wd)?|card|cvv|cvc|ccnum|expir|otp|ssn|aadhaar|aadhar|pan_?no|secret|token|authorization/i', $key)) continue;
            if (in_array($key, ['id', 'eventid', 'event_id', 'is_test', 'istest', 'conversation', 'transcript', 'messages', 'chat', 'history', 'chat_history', 'chat_log'], true)) continue;
            if ($provider === 'google' && in_array($key, ['gcl_id', 'gclid'], true)) continue;
            if (in_array($key, array_merge(self::EMAIL_KEYS, self::PHONE_KEYS, self::NAME_KEYS, self::FIRST_KEYS, self::LAST_KEYS, ['message', 'message_consent']), true)) continue;
            if (str_starts_with($key, 'utm_') || in_array($key, ['gclid', 'fbclid'], true)) $tracking[$key] = mb_substr($value, 0, 200);
            elseif (in_array($key, ['page_url', 'page', 'page_title', 'referrer', 'form_name', 'form_id', 'form', 'anibros_form', 'chat_id', 'conversation_id'], true)) $context[$key] = mb_substr($value, 0, 300);
            elseif (in_array($key, ['leadgen_id', 'lead_id', 'page_id', 'pageid', 'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'adgroup_id', 'creative_id', 'ad_id', 'ad_name', 'form_id', 'api_version', 'platform', 'created_time'], true)) $details[$key] = mb_substr($value, 0, 300);
            else $answers[] = ['label' => mb_substr(ucwords(str_replace('_', ' ', $key)), 0, 80), 'value' => mb_substr($value, 0, 500)];
        }
        if ($tracking) $details['tracking'] = $tracking;
        if ($context) $details['context'] = $context;
        if ($answers) $details['answers'] = array_slice($answers, 0, 40);
        $details['provider'] = $provider;
        $conversation = [];
        $speakers = [
            'visitor' => 'visitor', 'user' => 'visitor', 'customer' => 'visitor', 'client' => 'visitor',
            'human' => 'visitor', 'lead' => 'visitor', 'contact' => 'visitor', 'me' => 'visitor',
            'bot' => 'bot', 'agent' => 'bot', 'assistant' => 'bot', 'operator' => 'bot',
            'ai' => 'bot', 'system' => 'bot', 'support' => 'bot',
        ];
        foreach (['conversation', 'transcript', 'messages', 'chat', 'history', 'chat_history', 'chat_log'] as $key) {
            if (!is_array($payload[$key] ?? null)) continue;
            foreach (array_slice($payload[$key], 0, 80) as $i => $turn) {
                if (is_string($turn)) {
                    $text = self::clean($turn, 600);
                    $who = $i % 2 === 0 ? 'visitor' : 'bot';
                } elseif (is_array($turn)) {
                    $text = '';
                    foreach (['text', 'message', 'content', 'body', 'msg', 'value'] as $field) {
                        if (is_string($turn[$field] ?? null) && trim($turn[$field]) !== '') {
                            $text = self::clean($turn[$field], 600);
                            break;
                        }
                    }
                    $whoRaw = '';
                    foreach (['from', 'role', 'sender', 'author', 'type', 'who', 'speaker'] as $field) {
                        if (is_string($turn[$field] ?? null)) {
                            $whoRaw = strtolower(self::clean($turn[$field], 40));
                            break;
                        }
                    }
                    $who = $speakers[$whoRaw] ?? (($turn['is_bot'] ?? false) === true || ($turn['bot'] ?? false) === true ? 'bot' : 'visitor');
                }
                else continue;
                if ($text !== '') $conversation[] = ['who' => $who, 'text' => $text];
            }
            break;
        }
        if ($message === null && $conversation) $message = implode(' / ', array_map(static fn($t) => $t['text'], array_filter($conversation, static fn($t) => $t['who'] === 'visitor'))) ?: null;
        return [
            'firstName' => mb_substr($first ?: ($email ? explode('@', $email)[0] : ($phone ?: 'Unknown')), 0, 100),
            'lastName' => $last ? mb_substr($last, 0, 100) : null,
            'email' => $email,
            'phone' => $phone,
            'source' => $source,
            'campaign' => $campaign,
            'budgetMax' => $budgetMax,
            'bedroomsMin' => $bedrooms,
            'locations' => array_values(array_unique($locations)),
            'tags' => $tags,
            'details' => $details,
            'answers' => $answers,
            'message' => $message ? mb_substr($message, 0, 1000) : null,
            'conversation' => $conversation,
            'isTest' => $isTest,
        ];
    }

    private static function key(string $key): string
    {
        return trim((string) preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/i', '_', strtolower($key)) ?? ''), '_');
    }

    private static function parseMoney(string $text): ?float
    {
        $value = strtolower(preg_replace('/[,₹$\\s]/u', '', $text) ?? '');
        $value = preg_replace('/^rs\\.?/', '', $value) ?? '';
        if (!preg_match('/^(\\d+(?:\\.\\d+)?)(l|lac|lacs|lakh|lakhs|cr|crore|crores|k)?$/', $value, $m)) return null;
        $number = (float) $m[1];
        $suffix = $m[2] ?? '';
        $multiplier = $suffix === '' ? 1 : ($suffix === 'k' ? 1000 : (str_starts_with($suffix, 'c') ? 10000000 : 100000));
        $amount = $number * $multiplier;
        return is_finite($amount) && $amount > 0 && $amount < 1.0e13 ? $amount : null;
    }

    private static function take(array &$values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $value = $values[$key];
                unset($values[$key]);
                return $value;
            }
        }
        return null;
    }

    private static function takeLike(array &$values, string $pattern, ?string $avoid = null, bool $longest = false): ?string
    {
        $matches = [];
        foreach ($values as $key => $value) {
            if (preg_match($pattern, $key) && ($avoid === null || !preg_match($avoid, $key))) $matches[$key] = $value;
        }
        if (!$matches) return null;
        if ($longest) uasort($matches, static fn($a, $b) => strlen($b) <=> strlen($a));
        $key = array_key_first($matches);
        $value = $values[$key];
        unset($values[$key]);
        return $value;
    }
}
