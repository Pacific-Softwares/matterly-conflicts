<?php

namespace Modules\MatterlyConflicts\Support;

use App\Models\Client;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;
use Modules\MatterlyConflicts\Models\Party;

/**
 * Looks a name up across one firm's clients, recorded parties and matters.
 *
 * Every query is pinned to the firm id passed in (never the browser's), so it works the same in a
 * firm request, a model hook or a test. Matching is word-based and case-insensitive: "acme corp"
 * finds "ACME Corporation Ltd", and company suffixes such as Ltd or LLC are ignored.
 */
final class ConflictSearch
{
    private const LIMIT = 25;

    /** Ignored when matching, unless the name is nothing but these. */
    private const NOISE = ['the', 'and', 'of', 'mr', 'mrs', 'ms', 'dr', 'llc', 'llp', 'ltd', 'limited', 'inc', 'co', 'corp', 'plc', 'gmbh', 'sa', 'sarl', 'pvt', 'pty', 'bv', 'ag'];

    public function __construct(private readonly int $firmId) {}

    /** @return list<string> lowercase words to match, at least 2 characters each */
    public static function words(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}@.+]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn ($w) => trim($w, '.+@'), $words); // "Ltd." → "ltd"; emails keep their inner dots
        $words = array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
        $meaningful = array_values(array_diff($words, self::NOISE));

        return array_slice($meaningful ?: $words, 0, 6);
    }

    /**
     * @return array{clients: list<array>, parties: list<array>, matters: list<array>, count: int, adverse: int}
     */
    public function run(string $query, ?int $exceptClientId = null): array
    {
        $words = self::words($query);
        if ($words === []) {
            return ['clients' => [], 'parties' => [], 'matters' => [], 'count' => 0, 'adverse' => 0];
        }

        $clients = $this->clients($words, $query, $exceptClientId);
        $parties = $this->parties($words);
        $matters = $this->matters($query, array_column($clients, 'id'));
        $adverse = count(array_filter($parties, fn ($p) => in_array($p['role'], Party::ADVERSE_ROLES, true)));

        return [
            'clients' => $clients,
            'parties' => $parties,
            'matters' => $matters,
            'count' => count($clients) + count($parties) + count($matters),
            'adverse' => $adverse,
        ];
    }

    private function clients(array $words, string $query, ?int $exceptId): array
    {
        $q = Client::withoutGlobalScope('firm')->where('firm_id', $this->firmId)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));

        $digits = preg_replace('/\D+/', '', $query);
        $q->where(function (Builder $q) use ($words, $digits) {
            $q->where(function (Builder $q) use ($words) {
                foreach ($words as $word) {
                    $q->where(fn (Builder $q) => $q->where('first_name', 'like', self::like($word))
                        ->orWhere('last_name', 'like', self::like($word))
                        ->orWhere('email', 'like', self::like($word)));
                }
            });
            // A phone number (7+ digits) also matches the client's phone.
            if (strlen($digits) >= 7) {
                $q->orWhere('phone', 'like', self::like($digits))->orWhere('phone_e164', 'like', self::like($digits));
            }
        });

        return $q->orderBy('last_name')->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => trim($c->first_name.' '.$c->last_name),
                'email' => $c->email,
                'phone' => $c->phone,
                'url' => '/admin/clients/'.$c->id,
            ])->all();
    }

    private function parties(array $words): array
    {
        $q = Party::withoutGlobalScope('firm')->where('firm_id', $this->firmId);
        foreach ($words as $word) {
            $q->where(fn (Builder $q) => $q->where('name', 'like', self::like($word))->orWhere('aliases', 'like', self::like($word)));
        }

        return $q->with('matter:id,matter_number,client_id', 'matter.client:id,first_name,last_name')
            ->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Party $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'aliases' => $p->aliases,
                'role' => $p->role,
                'notes' => $p->notes,
                'matter' => $p->matter ? self::matterLabel($p->matter) : null,
            ])->all();
    }

    /** Matters of the matched clients, plus a matter whose number was typed. */
    private function matters(string $query, array $clientIds): array
    {
        $number = trim($query);
        if ($clientIds === [] && ! preg_match('/\d/', $number)) {
            return [];
        }

        return Matter::withoutGlobalScope('firm')->where('firm_id', $this->firmId)
            ->where(fn (Builder $q) => $q->whereIn('client_id', $clientIds ?: [0])
                ->when(preg_match('/\d/', $number), fn ($q) => $q->orWhere('matter_number', 'like', self::like($number))))
            ->with('client:id,first_name,last_name', 'practiceArea:id,name')
            ->latest('id')->limit(self::LIMIT)
            ->get(['id', 'matter_number', 'client_id', 'practice_area_id', 'status', 'created_at'])
            ->map(fn (Matter $m) => self::matterLabel($m) + [
                'area' => $m->practiceArea?->name,
                'status' => $m->status,
                'opened' => $m->created_at?->toDateString(),
            ])->all();
    }

    private static function matterLabel(Matter $m): array
    {
        return [
            'id' => $m->id,
            'number' => $m->matter_number,
            'client' => $m->client ? trim($m->client->first_name.' '.$m->client->last_name) : null,
            'url' => '/admin/matters/'.$m->id,
        ];
    }

    /** A LIKE pattern for one word, with %, _ and \ taken literally. */
    private static function like(string $word): string
    {
        return '%'.addcslashes($word, '%_\\').'%';
    }

    /** What the log keeps of a search: names only, enough to show what was found at the time. */
    public static function snapshot(array $result): array
    {
        return [
            'clients' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name']], $result['clients']),
            'parties' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'matter' => $p['matter']['number'] ?? null], $result['parties']),
            'matters' => array_map(fn ($m) => ['id' => $m['id'], 'number' => $m['number'], 'client' => $m['client']], $result['matters']),
        ];
    }
}
