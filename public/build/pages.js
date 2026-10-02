/*
 * matterly-conflicts: React pages, published to /modules/matterly-conflicts/build/pages.js.
 * Plain JavaScript on Matterly's shared module API (window.__matterly), so there's no build step.
 * Every string comes from lang/{locale}/messages.php through the `t` prop.
 */
(function (M) {
    if (!M || M.apiVersion !== 1) {
        console.error('[matterly-conflicts] needs Matterly module API v1');
        return;
    }
    var R = M.React;
    var h = R.createElement;
    var useState = R.useState;
    var useForm = M.inertia.useForm;
    var router = M.inertia.router;
    var Link = M.inertia.Link;
    var FirmLayout = M.layouts.FirmLayout;
    var SuperAdminLayout = M.layouts.SuperAdminLayout;
    var Button = M.ui.Button;
    var BASE = '/matterly-conflicts';

    if (!document.getElementById('matterly-conflicts-css')) {
        var link = document.createElement('link');
        link.id = 'matterly-conflicts-css';
        link.rel = 'stylesheet';
        link.href = '/modules/matterly-conflicts/build/pages.css';
        document.head.appendChild(link);
    }

    // ── helpers ─────────────────────────────────────────────────────────────
    function tr(t, key, repl) {
        var s = key.split('.').reduce(function (o, k) { return o && o[k]; }, t);
        if (typeof s !== 'string') return key;
        Object.keys(repl || {}).forEach(function (k) { s = s.split(':' + k).join(repl[k]); });
        return s;
    }
    function when(iso) {
        var d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) + ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }
    function cx() { return Array.prototype.filter.call(arguments, Boolean).join(' '); }
    function Outcome(p) { return h('span', { className: 'mc-badge mc-' + p.outcome }, tr(p.t, 'outcomes.' + p.outcome)); }
    function Role(p) { return h('span', { className: cx('mc-role', (p.role === 'adverse' || p.role === 'opposing_counsel') && 'mc-role-adverse') }, tr(p.t, 'roles.' + p.role)); }
    function Pager(p) {
        if (!p.page || (!p.page.prev_page_url && !p.page.next_page_url)) return null;
        return h('div', { className: 'mc-pager' },
            p.page.prev_page_url ? h(Link, { href: p.page.prev_page_url, preserveScroll: true }, '← ' + tr(p.t, 'previous')) : h('span'),
            h('small', null, p.page.from + '–' + p.page.to + ' / ' + p.page.total),
            p.page.next_page_url ? h(Link, { href: p.page.next_page_url, preserveScroll: true }, tr(p.t, 'next') + ' →') : h('span')
        );
    }

    // Outcome + note, used to record a search and to decide a flagged check.
    function DecisionForm(p) {
        var t = p.t;
        var form = useForm({ query: p.query || '', outcome: 'clear', note: '' });
        var needsNote = form.data.outcome !== 'clear';
        function submit(e) {
            e.preventDefault();
            var opts = { preserveScroll: true, onSuccess: function () { form.reset('note'); p.onDone && p.onDone(); } };
            p.checkId ? form.patch(BASE + '/checks/' + p.checkId, opts) : form.post(BASE + '/checks', opts);
        }
        return h('form', { className: 'mc-decide', onSubmit: submit },
            h('div', { className: 'mc-choices', role: 'radiogroup', 'aria-label': tr(t, 'outcome') },
                ['clear', 'conflict', 'waived'].map(function (o) {
                    return h('label', { key: o, className: cx('mc-choice', 'mc-choice-' + o, form.data.outcome === o && 'is-on') },
                        h('input', { type: 'radio', name: 'outcome', value: o, checked: form.data.outcome === o, onChange: function () { form.setData('outcome', o); } }),
                        tr(t, 'outcomes.' + o));
                })
            ),
            h('textarea', {
                className: 'mc-input', rows: 2, maxLength: 2000, value: form.data.note,
                placeholder: needsNote ? tr(t, 'note_required') : tr(t, 'note'),
                onChange: function (e) { form.setData('note', e.target.value); },
            }),
            form.errors.note ? h('p', { className: 'mc-error' }, form.errors.note) : null,
            form.errors.query ? h('p', { className: 'mc-error' }, form.errors.query) : null,
            h('div', { className: 'mc-row' },
                h(Button, { type: 'submit', disabled: form.processing }, tr(t, 'save')),
                p.onDone ? h('button', { type: 'button', className: 'mc-ghost', onClick: p.onDone }, tr(t, 'cancel')) : null
            )
        );
    }

    function Section(p) {
        if (!p.items.length) return null;
        return h('div', { className: 'mc-section' },
            h('h3', null, p.title, h('span', { className: 'mc-count' }, p.items.length)),
            h('ul', { className: 'mc-hits' }, p.items.map(p.render))
        );
    }

    // ── Firm: search + log ──────────────────────────────────────────────────
    function ConflictCheck(props) {
        var t = props.t;
        var _q = useState(props.query || '');
        var q = _q[0], setQ = _q[1];
        R.useEffect(function () { setQ(props.query || ''); }, [props.query]);
        var _open = useState(null);
        var open = _open[0], setOpen = _open[1];
        var res = props.results;

        function search(e) {
            e.preventDefault();
            router.get(BASE, q.trim() ? { q: q.trim() } : {}, { preserveState: true, preserveScroll: true });
        }
        function filterTo(outcome) {
            router.get(BASE, Object.assign({}, props.query ? { q: props.query } : {}, outcome ? { outcome: outcome } : {}), { preserveState: true, preserveScroll: true });
        }

        return h(FirmLayout, { title: tr(t, 'title') },
            h('div', { className: 'mc-page' },
                h('div', { className: 'mc-head' },
                    h('div', null, h('h1', null, tr(t, 'title')), h('p', { className: 'mc-muted' }, tr(t, 'intro'))),
                    h('div', { className: 'mc-row' },
                        h(Link, { className: 'mc-link', href: BASE + '/parties' }, tr(t, 'parties_title') + ' →')
                    )
                ),

                props.pending > 0 && props.filter !== 'pending'
                    ? h('button', { type: 'button', className: 'mc-alert', onClick: function () { filterTo('pending'); } },
                        '⚑ ' + tr(t, 'needs_review', { count: props.pending }) + ' →')
                    : null,

                h('form', { className: 'mc-card mc-search', onSubmit: search, role: 'search' },
                    h('input', {
                        className: 'mc-input', type: 'search', maxLength: 160, autoFocus: !props.query,
                        placeholder: tr(t, 'search_placeholder'), 'aria-label': tr(t, 'search_placeholder'),
                        value: q, onChange: function (e) { setQ(e.target.value); },
                    }),
                    h(Button, { type: 'submit' }, tr(t, 'search'))
                ),

                res ? h('div', { className: 'mc-card mc-results' },
                    h('div', { className: 'mc-results-head' },
                        h('h2', null, tr(t, 'searching_for', { query: props.query })),
                        h('span', { className: cx('mc-badge', res.count ? (res.adverse ? 'mc-conflict' : 'mc-pending') : 'mc-clear') },
                            res.count ? tr(t, 'matches', { count: res.count }) : tr(t, 'outcomes.clear'))
                    ),
                    res.adverse ? h('p', { className: 'mc-warning' }, '⚠ ' + tr(t, 'adverse_warning')) : null,
                    res.count === 0 ? h('p', { className: 'mc-muted mc-pad' }, tr(t, 'no_matches')) : null,
                    h(Section, { title: tr(t, 'parties'), items: res.parties, render: function (p) {
                        return h('li', { key: 'p' + p.id },
                            h('div', null,
                                h('strong', null, p.name), p.aliases ? h('small', null, ' (' + p.aliases + ')') : null,
                                p.matter ? h('small', { className: 'mc-block' }, h('a', { href: p.matter.url }, p.matter.number), p.matter.client ? ' · ' + p.matter.client : '') : null,
                                p.notes ? h('small', { className: 'mc-block' }, p.notes) : null
                            ),
                            h(Role, { t: t, role: p.role })
                        );
                    } }),
                    h(Section, { title: tr(t, 'clients'), items: res.clients, render: function (c) {
                        return h('li', { key: 'c' + c.id },
                            h('div', null, h('a', { href: c.url }, h('strong', null, c.name)), h('small', { className: 'mc-block' }, [c.email, c.phone].filter(Boolean).join(' · ')))
                        );
                    } }),
                    h(Section, { title: tr(t, 'matters'), items: res.matters, render: function (m) {
                        return h('li', { key: 'm' + m.id },
                            h('div', null, h('a', { href: m.url }, h('strong', null, m.number)), h('small', { className: 'mc-block' }, [m.client, m.area, m.opened].filter(Boolean).join(' · '))),
                            m.status ? h('span', { className: 'mc-role mc-status' }, m.status.replace('_', ' ')) : null
                        );
                    } }),
                    h('div', { className: 'mc-record' },
                        h('h3', null, tr(t, 'record')),
                        h('p', { className: 'mc-muted' }, tr(t, 'record_hint')),
                        h(DecisionForm, { key: props.query, t: t, query: props.query })
                    )
                ) : null,

                h('div', { className: 'mc-card' },
                    h('div', { className: 'mc-log-head' },
                        h('h2', null, tr(t, 'log')),
                        h('div', { className: 'mc-row mc-wrap' },
                            [null, 'pending', 'clear', 'conflict', 'waived'].map(function (o) {
                                return h('button', { key: o || 'all', type: 'button', className: cx('mc-chip', (props.filter || null) === o && 'is-on'), onClick: function () { filterTo(o); } },
                                    o ? tr(t, 'outcomes.' + o) : tr(t, 'all'));
                            }),
                            h('a', { className: 'mc-link', href: BASE + '/checks.csv' }, tr(t, 'export'))
                        )
                    ),
                    props.checks.data.length === 0
                        ? h('p', { className: 'mc-muted mc-pad' }, tr(t, 'log_empty'))
                        : h('div', { className: 'mc-scroll' }, h('table', { className: 'mc-table' },
                            h('thead', null, h('tr', null,
                                h('th', null, tr(t, 'when')), h('th', null, tr(t, 'searched')), h('th', null, tr(t, 'found')), h('th', null, tr(t, 'outcome')), h('th', null, tr(t, 'note'))
                            )),
                            h('tbody', null, props.checks.data.map(function (c) {
                                var names = [].concat(
                                    (c.hits.parties || []).map(function (p) { return p.name + ' (' + tr(t, 'roles.' + p.role) + ')'; }),
                                    (c.hits.clients || []).map(function (x) { return x.name; }),
                                    (c.hits.matters || []).map(function (m) { return m.number; })
                                );
                                return R.createElement(R.Fragment, { key: c.id },
                                    h('tr', null,
                                        h('td', { className: 'mc-nowrap' }, when(c.created_at), c.by ? h('small', { className: 'mc-block' }, tr(t, 'by', { name: c.by })) : null),
                                        h('td', null, h('strong', null, c.query), c.source === 'auto' ? h('small', { className: 'mc-block' }, tr(t, 'auto')) : null),
                                        h('td', { title: names.join(', ') }, c.hit_count, names.length ? h('small', { className: 'mc-block mc-clip' }, names.slice(0, 3).join(', ') + (names.length > 3 ? '…' : '')) : null),
                                        h('td', null, h(Outcome, { t: t, outcome: c.outcome }),
                                            c.outcome === 'pending' && open !== c.id ? h('button', { type: 'button', className: 'mc-link mc-block', onClick: function () { setOpen(c.id); } }, tr(t, 'decide')) : null),
                                        h('td', { className: 'mc-note' }, c.note || '', c.resolver && c.outcome !== 'pending' && c.source === 'auto' ? h('small', { className: 'mc-block' }, tr(t, 'by', { name: c.resolver })) : null)
                                    ),
                                    open === c.id ? h('tr', null, h('td', { colSpan: 5 }, h(DecisionForm, { t: t, checkId: c.id, onDone: function () { setOpen(null); } }))) : null
                                );
                            }))
                        )),
                    h(Pager, { t: t, page: props.checks })
                )
            )
        );
    }

    // ── Firm: parties register ──────────────────────────────────────────────
    function Parties(props) {
        var t = props.t;
        var form = useForm({ name: '', aliases: '', role: 'adverse', matter_id: '', notes: '' });
        var _s = useState(props.search || '');
        var s = _s[0], setS = _s[1];

        function add(e) {
            e.preventDefault();
            form.post(BASE + '/parties', { preserveScroll: true, onSuccess: function () { form.reset(); } });
        }
        function field(name, label, input) {
            return h('label', { className: 'mc-field' }, h('span', null, label), input, form.errors[name] ? h('small', { className: 'mc-error' }, form.errors[name]) : null);
        }
        function bind(name) { return { value: form.data[name], onChange: function (e) { form.setData(name, e.target.value); } }; }

        return h(FirmLayout, { title: tr(t, 'parties_title') },
            h('div', { className: 'mc-page' },
                h('div', { className: 'mc-head' },
                    h('div', null, h('h1', null, tr(t, 'parties_title')), h('p', { className: 'mc-muted' }, tr(t, 'parties_intro'))),
                    h(Link, { className: 'mc-link', href: BASE }, '← ' + tr(t, 'title'))
                ),
                h('form', { className: 'mc-card mc-grid', onSubmit: add },
                    field('name', tr(t, 'name'), h('input', Object.assign({ className: 'mc-input', maxLength: 160, required: true }, bind('name')))),
                    field('role', tr(t, 'role'), h('select', Object.assign({ className: 'mc-input' }, bind('role')),
                        props.roles.map(function (r) { return h('option', { key: r, value: r }, tr(t, 'roles.' + r)); }))),
                    field('aliases', tr(t, 'aliases'), h('input', Object.assign({ className: 'mc-input', maxLength: 255, placeholder: tr(t, 'aliases_hint') }, bind('aliases')))),
                    field('matter_id', tr(t, 'matter'), h('select', Object.assign({ className: 'mc-input' }, bind('matter_id')),
                        [h('option', { key: '', value: '' }, tr(t, 'no_matter'))].concat(props.matters.map(function (m) { return h('option', { key: m.id, value: m.id }, m.label); })))),
                    h('div', { className: 'mc-span' }, field('notes', tr(t, 'notes'), h('input', Object.assign({ className: 'mc-input', maxLength: 500 }, bind('notes'))))),
                    h('div', { className: 'mc-span' }, h(Button, { type: 'submit', disabled: form.processing }, tr(t, 'add_party')))
                ),
                h('div', { className: 'mc-card' },
                    h('form', { className: 'mc-log-head', onSubmit: function (e) { e.preventDefault(); router.get(BASE + '/parties', s.trim() ? { search: s.trim() } : {}, { preserveState: true }); } },
                        h('h2', null, tr(t, 'parties_title')),
                        h('input', { className: 'mc-input mc-filter', type: 'search', placeholder: tr(t, 'filter'), 'aria-label': tr(t, 'filter'), value: s, onChange: function (e) { setS(e.target.value); } })
                    ),
                    props.parties.data.length === 0
                        ? h('p', { className: 'mc-muted mc-pad' }, tr(t, 'parties_empty'))
                        : h('div', { className: 'mc-scroll' }, h('table', { className: 'mc-table' },
                            h('thead', null, h('tr', null, h('th', null, tr(t, 'name')), h('th', null, tr(t, 'role')), h('th', null, tr(t, 'matter')), h('th', null, tr(t, 'notes')), h('th'))),
                            h('tbody', null, props.parties.data.map(function (p) {
                                return h('tr', { key: p.id },
                                    h('td', null, h('strong', null, p.name), p.aliases ? h('small', { className: 'mc-block' }, p.aliases) : null),
                                    h('td', null, h(Role, { t: t, role: p.role })),
                                    h('td', null, p.matter ? h('a', { href: '/admin/matters/' + p.matter.id }, p.matter.number) : '—', p.matter && p.matter.client ? h('small', { className: 'mc-block' }, p.matter.client) : null),
                                    h('td', { className: 'mc-note' }, p.notes || ''),
                                    h('td', { className: 'mc-right' }, h('button', {
                                        type: 'button', className: 'mc-delete', 'aria-label': tr(t, 'remove'),
                                        onClick: function () { if (window.confirm(tr(t, 'confirm_remove'))) router.delete(BASE + '/parties/' + p.id, { preserveScroll: true }); },
                                    }, '×'))
                                );
                            }))
                        )),
                    h(Pager, { t: t, page: props.parties })
                )
            )
        );
    }

    // ── Super admin ─────────────────────────────────────────────────────────
    function Overview(props) {
        var t = props.t;
        var stats = [['firms_using', 'firms'], ['checks_30', 'checks'], ['conflicts_30', 'conflicts'], ['parties_total', 'parties']];
        return h(SuperAdminLayout, { title: tr(t, 'title') },
            h('div', { className: 'mc-page' },
                h('div', { className: 'mc-head' }, h('div', null, h('h1', null, tr(t, 'overview_title')), h('p', { className: 'mc-muted' }, tr(t, 'overview_hint')))),
                h('div', { className: 'mc-stats' }, stats.map(function (s) {
                    return h('div', { key: s[1], className: 'mc-card mc-stat' }, h('small', null, tr(t, s[0])), h('strong', null, props.totals[s[1]]));
                })),
                h('div', { className: 'mc-card mc-scroll' }, h('table', { className: 'mc-table' },
                    h('thead', null, h('tr', null, h('th', null, tr(t, 'firm')), h('th', null, tr(t, 'checks_30')), h('th', null, tr(t, 'outcomes.conflict')), h('th', null, tr(t, 'outcomes.pending')), h('th', null, tr(t, 'latest')))),
                    h('tbody', null, props.rows.length === 0
                        ? h('tr', null, h('td', { colSpan: 5, className: 'mc-muted' }, tr(t, 'log_empty')))
                        : props.rows.map(function (r) {
                            return h('tr', { key: r.firm }, h('td', null, r.firm), h('td', null, r.checks), h('td', null, r.conflicts), h('td', null, r.pending), h('td', null, when(r.latest)));
                        }))
                ))
            )
        );
    }

    M.registerPages('matterly-conflicts', { ConflictCheck: ConflictCheck, Parties: Parties, Overview: Overview });
})(window.__matterly);
