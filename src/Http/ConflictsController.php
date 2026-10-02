<?php

namespace Modules\MatterlyConflicts\Http;

use App\Models\Matter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\MatterlyConflicts\Models\Check;
use Modules\MatterlyConflicts\Models\Party;
use Modules\MatterlyConflicts\Support\ConflictSearch;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Firm pages run behind firm.scope, so Party and Check queries are already limited to the signed-in
 * firm (BelongsToFirm); ids from the browser are only ever looked up inside that scope.
 */
class ConflictsController extends Controller
{
    /** Search + the conflict log. React: "ConflictCheck". */
    public function index(Request $request): Response
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 160);
        $filter = in_array($request->query('outcome'), Check::OUTCOMES, true) ? $request->query('outcome') : null;

        return Inertia::render('modules/matterly-conflicts/ConflictCheck', [
            'query' => $query,
            'results' => $query !== '' ? (new ConflictSearch($this->firmId($request)))->run($query) : null,
            'filter' => $filter,
            'pending' => Check::where('outcome', 'pending')->count(),
            'checks' => Check::with('user:id,name', 'resolver:id,name')
                ->when($filter, fn ($q) => $q->where('outcome', $filter))
                ->latest('id')->paginate(15)->withQueryString()
                ->through(fn (Check $c) => $this->checkRow($c)),
            't' => trans('matterly-conflicts::messages'),
        ]);
    }

    /** Record the outcome of a search in the log. The hits are found again here, never taken from the browser. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:160'],
            'outcome' => ['required', Rule::in(['clear', 'conflict', 'waived'])],
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => in_array($request->input('outcome'), ['conflict', 'waived'], true))],
        ]);
        $result = (new ConflictSearch($this->firmId($request)))->run($data['query']);

        Check::create([
            'user_id' => $request->user()->id,
            'query' => trim($data['query']),
            'outcome' => $data['outcome'],
            'note' => $data['note'] ?? null,
            'hits' => ConflictSearch::snapshot($result),
            'hit_count' => $result['count'],
            'source' => 'manual',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return redirect('/matterly-conflicts')->with('success', __('matterly-conflicts::messages.recorded'));
    }

    /** Decide a check that was flagged automatically. */
    public function resolve(Request $request, int $check): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['clear', 'conflict', 'waived'])],
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => in_array($request->input('outcome'), ['conflict', 'waived'], true))],
        ]);
        $row = Check::whereKey($check)->where('outcome', 'pending')->firstOrFail();
        $row->update($data + ['resolved_by' => $request->user()->id, 'resolved_at' => now()]);

        return back()->with('success', __('matterly-conflicts::messages.recorded'));
    }

    /** The whole log as CSV, for compliance files. */
    public function export(): StreamedResponse
    {
        $rows = Check::with('user:id,name', 'resolver:id,name')->latest('id')->cursor();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Searched for', 'Matches', 'Outcome', 'Note', 'Checked by', 'Decided by', 'Decided at', 'Source']);
            foreach ($rows as $c) {
                fputcsv($out, array_map([self::class, 'cell'], [
                    $c->created_at?->toDateTimeString(), $c->query, $c->hit_count, $c->outcome, $c->note,
                    $c->user?->name, $c->resolver?->name, $c->resolved_at?->toDateTimeString(), $c->source,
                ]));
            }
            fclose($out);
        }, 'conflict-checks-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ── Parties ─────────────────────────────────────────────────────────────

    /** React: "Parties". */
    public function parties(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);

        return Inertia::render('modules/matterly-conflicts/Parties', [
            'search' => $search,
            'parties' => Party::with('matter:id,matter_number,client_id', 'matter.client:id,first_name,last_name')
                ->when($search !== '', function ($q) use ($search) {
                    foreach (ConflictSearch::words($search) as $word) {
                        $like = '%'.addcslashes($word, '%_\\').'%';
                        $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('aliases', 'like', $like));
                    }
                })
                ->orderBy('name')->paginate(25)->withQueryString()
                ->through(fn (Party $p) => [
                    'id' => $p->id, 'name' => $p->name, 'aliases' => $p->aliases, 'role' => $p->role, 'notes' => $p->notes,
                    'matter' => $p->matter ? ['id' => $p->matter->id, 'number' => $p->matter->matter_number,
                        'client' => $p->matter->client ? trim($p->matter->client->first_name.' '.$p->matter->client->last_name) : null] : null,
                ]),
            'matters' => Matter::with('client:id,first_name,last_name')->latest('id')->limit(300)
                ->get(['id', 'matter_number', 'client_id'])
                ->map(fn (Matter $m) => ['id' => $m->id, 'label' => $m->matter_number.($m->client ? ' · '.trim($m->client->first_name.' '.$m->client->last_name) : '')]),
            'roles' => Party::ROLES,
            't' => trans('matterly-conflicts::messages'),
        ]);
    }

    public function storeParty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'aliases' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(Party::ROLES)],
            'matter_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        // Only a matter of this firm (Matter is firm-scoped here).
        if (! empty($data['matter_id']) && ! Matter::whereKey($data['matter_id'])->exists()) {
            return back()->withErrors(['matter_id' => __('matterly-conflicts::messages.bad_matter')]);
        }

        Party::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', __('matterly-conflicts::messages.party_added'));
    }

    public function destroyParty(int $party): RedirectResponse
    {
        Party::whereKey($party)->delete();

        return back()->with('success', __('matterly-conflicts::messages.party_removed'));
    }

    // ── Super admin ─────────────────────────────────────────────────────────

    /** React: "Overview". Totals only: never what a firm searched for. */
    public function overview(): Response
    {
        $since = now()->subDays(30);

        return Inertia::render('modules/matterly-conflicts/Overview', [
            'totals' => [
                'firms' => DB::table('matterly_conflicts_checks')->where('created_at', '>=', $since)->distinct()->count('firm_id'),
                'checks' => DB::table('matterly_conflicts_checks')->where('created_at', '>=', $since)->count(),
                'conflicts' => DB::table('matterly_conflicts_checks')->where('created_at', '>=', $since)->where('outcome', 'conflict')->count(),
                'parties' => DB::table('matterly_conflicts_parties')->count(),
            ],
            'rows' => DB::table('matterly_conflicts_checks')
                ->join('firms', 'firms.id', '=', 'matterly_conflicts_checks.firm_id')
                ->where('matterly_conflicts_checks.created_at', '>=', $since)
                ->selectRaw("firms.name as firm, count(*) as checks, sum(case when outcome = 'conflict' then 1 else 0 end) as conflicts, sum(case when outcome = 'pending' then 1 else 0 end) as pending, max(matterly_conflicts_checks.created_at) as latest")
                ->groupBy('firms.id', 'firms.name')->orderByDesc('checks')->limit(25)->get()
                ->map(fn ($r) => ['latest' => $r->latest ? \Illuminate\Support\Carbon::parse($r->latest)->toIso8601String() : null] + (array) $r),
            't' => trans('matterly-conflicts::messages'),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function firmId(Request $request): int
    {
        return (int) $request->user()->firm_id;
    }

    private function checkRow(Check $c): array
    {
        return [
            'id' => $c->id,
            'query' => $c->query,
            'outcome' => $c->outcome,
            'source' => $c->source,
            'note' => $c->note,
            'hit_count' => $c->hit_count,
            'hits' => $c->hits ?? [],
            'by' => $c->user?->name,
            'resolver' => $c->resolver?->name,
            'created_at' => $c->created_at?->toIso8601String(),
            'resolved_at' => $c->resolved_at?->toIso8601String(),
        ];
    }

    /** Stop spreadsheet apps treating a name as a formula. */
    private static function cell($value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
