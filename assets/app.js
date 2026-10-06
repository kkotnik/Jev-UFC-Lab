(function () {
    'use strict';

    var state = { data: null, eventId: null, view: 'current' };
    var byId = function (id) { return document.getElementById(id); };
    var esc = function (value) { var div = document.createElement('div'); div.textContent = value == null ? '' : String(value); return div.innerHTML; };
    var euro = function (value) { return new Intl.NumberFormat('sl-SI', { style: 'currency', currency: 'EUR' }).format(Number(value || 0)); };
    var pct = function (value, digits) { return (Number(value || 0) * 100).toFixed(digits == null ? 1 : digits) + '%'; };
    var sportsbookUrl = 'https://www.e-stave.com/';

    function request(action, method, payload) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(method || 'GET', 'api.php?action=' + action + (state.eventId ? '&event_id=' + state.eventId : ''), true);
            xhr.setRequestHeader('Accept', 'application/json');
            if (payload !== undefined) xhr.setRequestHeader('Content-Type', 'application/json');
            var key = sessionStorage.getItem('typesafeKey');
            var oddsKey = sessionStorage.getItem('oddsKey');
            if (key) xhr.setRequestHeader('X-Typesafe-Key', key);
            if (oddsKey) xhr.setRequestHeader('X-Odds-Key', oddsKey);
            xhr.onload = function () {
                var data;
                try { data = JSON.parse(xhr.responseText); } catch (error) { reject(new Error('Strežnik ni vrnil veljavnega odgovora.')); return; }
                if (xhr.status >= 200 && xhr.status < 300 && data.ok) resolve(data);
                else reject(new Error(data.error || ('HTTP ' + xhr.status)));
            };
            xhr.onerror = function () { reject(new Error('Povezava z lokalnim strežnikom ni uspela.')); };
            xhr.send(payload === undefined ? null : JSON.stringify(payload));
        });
    }

    function toast(message, error) {
        var node = byId('toast'); node.textContent = message; node.className = 'toast show' + (error ? ' error' : '');
        clearTimeout(toast.timer); toast.timer = setTimeout(function () { node.className = 'toast'; }, 4200);
    }

    function load() {
        request('dashboard').then(function (response) {
            state.data = response; state.eventId = response.event_id; render(); maybeSyncEmptyCard();
        }).catch(function (error) { toast(error.message, true); });
    }

    function maybeSyncEmptyCard() {
        var event = state.data.events.find(function (item) { return Number(item.id) === Number(state.eventId); });
        if (!event || state.data.fights.length || new Date(event.event_date) < new Date()) return;
        var guard = 'cardSyncAttempt:' + event.id;
        if (sessionStorage.getItem(guard)) return;
        sessionStorage.setItem(guard, '1');
        request('sync_event_card', 'POST', { event_id: event.id }).then(function (result) {
            toast('Aktualni UFC card je samodejno osvežen: ' + result.sync.fights_written + ' borb.'); load();
        }).catch(function (error) { toast('Card še ni bil samodejno uvožen: ' + error.message, true); });
    }

    function render() {
        var data = state.data;
        var activeEvent = data.events.find(function (event) { return Number(event.id) === Number(data.event_id); });
        byId('eventSelect').innerHTML = data.events.map(function (event) {
            return '<option value="' + Number(event.id) + '"' + (Number(event.id) === Number(data.event_id) ? ' selected' : '') + '>' + esc(event.name) + '</option>';
        }).join('');
        byId('eventTitle').textContent = activeEvent ? activeEvent.name : 'Ni dogodka';
        byId('eventMeta').textContent = activeEvent ? new Date(activeEvent.event_date).toLocaleString('sl-SI', { dateStyle: 'full', timeStyle: 'short' }) + ' · ' + activeEvent.venue : '';
        byId('historyCount').textContent = Number(data.history_count).toLocaleString('sl-SI');
        byId('historyBadge').textContent = data.history.length;
        byId('backtestBadge').textContent = data.backtest && Number(data.backtest.version) >= 4 ? data.backtest.event_count : 'novo';
        byId('brierScore').textContent = data.brier == null ? 'še brez rezultatov' : Number(data.brier).toFixed(3) + ' (nižji je boljši)';
        var configured = data.api_configured || !!sessionStorage.getItem('typesafeKey');
        byId('apiBadge').className = 'status' + (configured ? ' online' : '');
        byId('apiBadge').lastChild.nodeValue = configured ? ' Jev pripravljen' : ' Ključ ni nastavljen';
        renderMetric('jev', data.metrics.jev); renderMetric('me', data.metrics.me);
        byId('kellyInput').value = data.settings.kelly_fraction;
        byId('maxBetInput').value = Number(data.settings.max_bet_fraction) * 100;
        byId('maxEventInput').value = Number(data.settings.max_event_fraction) * 100;
        byId('minEdgeInput').value = Number(data.settings.min_edge) * 100;
        renderFights(data.fights); renderLedger(data.bets); renderEventReport(data.event_report); renderBacktest(data.backtest); renderHistory(data.history);
        setView(state.view);
    }

    function renderMetric(prefix, metric) {
        byId(prefix + 'Bankroll').textContent = euro(metric.bankroll); byId(prefix + 'Available').textContent = euro(metric.available);
        byId(prefix + 'Roi').textContent = pct(metric.roi); byId(prefix + 'Record').textContent = metric.wins + '–' + metric.losses;
        var profit = byId(prefix + 'Profit'); profit.textContent = (metric.profit >= 0 ? '+' : '') + euro(metric.profit); profit.className = metric.profit < 0 ? 'negative' : '';
    }

    function renderFights(fights) {
        if (!fights.length) { byId('fightList').innerHTML = '<div class="empty">Card še ni objavljen ali ga ni bilo mogoče uvoziti. Lahko ga dodaš ročno.</div>'; return; }
        byId('fightList').innerHTML = fights.map(function (fight, index) {
            var predicted = fight.prediction_id != null;
            var betHtml = '<div class="bet-call none">BREZ STAVE — portfolio še ni razporejen ali ni kvote</div>';
            if (predicted && Number(fight.prediction_stake) > 0) {
                var probability = fight.recommended_bet === fight.fighter_a ? Number(fight.p_a) : Number(fight.p_b);
                var odds = Number(fight.recommended_odds), stake = Number(fight.prediction_stake), implied = 1 / odds;
                betHtml = '<div class="bet-call"><strong>MONEYLINE: ' + esc(fight.recommended_bet) + ' zmaga na kakršen koli način</strong>' +
                    '<small>Vložek ' + euro(stake) + ' · kvota ' + odds.toFixed(2) + ' · možno izplačilo ' + euro(stake * odds) + ' · možni čisti dobiček ' + euro(stake * (odds - 1)) + '</small>' +
                    '<small>Jev ' + pct(probability) + ' proti tržnih ' + pct(implied) + ' → edge +' + (Number(fight.edge) * 100).toFixed(1) + ' odstotne točke</small></div>';
            }
            var prefight = renderPrefight(fight);
            var analysis = predicted ? '<div class="prob-head"><b>Jev izbere: ' + esc(fight.winner_pick) + '</b><span class="confidence">confidence ' + pct(fight.confidence) + '</span></div>' +
                '<div class="prob-line"><span>' + esc(fight.fighter_a) + '</span><div class="bar"><i style="width:' + (fight.p_a * 100) + '%"></i></div><b>' + pct(fight.p_a, 0) + '</b></div>' +
                '<div class="prob-line second"><span>' + esc(fight.fighter_b) + '</span><div class="bar"><i style="width:' + (fight.p_b * 100) + '%"></i></div><b>' + pct(fight.p_b, 0) + '</b></div>' +
                '<div class="method">Napoved načina zaključka: <b>' + esc(fight.method_pick) + '</b> <small>(ločeno od moneyline stave)</small></div>' + betHtml :
                '<div class="awaiting">Jev še ni analiziral borbe. Najprej preveri kvote in sinhroniziraj zgodovino.</div>';
            var result = Number(fight.completed) ? '<div class="result-badge">' + esc(fight.winner) + '<br>' + esc(fight.method || '') + (fight.result_round ? ' · R' + Number(fight.result_round) : '') + '</div>' :
                '<button class="button primary predict-one" data-id="' + Number(fight.id) + '"' + (predicted ? ' disabled' : '') + '>' + (predicted ? 'Zaklenjeno' : 'Analiziraj') + '</button><button class="button human-button my-bet" data-id="' + Number(fight.id) + '">Moja stava</button><button class="button ghost settle" data-id="' + Number(fight.id) + '">Rezultat</button>';
            var oddsA = fight.odds_a == null ? '' : Number(fight.odds_a).toFixed(2), oddsB = fight.odds_b == null ? '' : Number(fight.odds_b).toFixed(2);
            return '<article class="fight-card' + (Number(fight.completed) ? ' completed' : '') + '"><div class="fight-num"><strong>' + String(index + 1).padStart(2, '0') + '</strong><small>' + esc(fight.card_section) + '</small></div>' +
                '<div class="matchup"><span class="weight">' + esc(fight.weight_class) + '</span><div class="names">' + esc(fight.fighter_a) + '<span class="vs">vs</span>' + esc(fight.fighter_b) + '</div>' +
                '<div class="odds-edit"><label><span class="odds-name">' + esc(fight.fighter_a) + '</span><input class="odds-a" aria-label="Kvota za ' + esc(fight.fighter_a) + '" type="number" min="1.01" step="0.01" value="' + oddsA + '" ' + (predicted ? 'disabled' : '') + '></label>' +
                '<label><span class="odds-name">' + esc(fight.fighter_b) + '</span><input class="odds-b" aria-label="Kvota za ' + esc(fight.fighter_b) + '" type="number" min="1.01" step="0.01" value="' + oddsB + '" ' + (predicted ? 'disabled' : '') + '></label>' + (predicted ? '' : '<button class="save-odds" data-id="' + Number(fight.id) + '">shrani</button>') + '</div>' + prefight + '</div><div class="analysis">' + analysis + '</div><div class="fight-actions">' + result + '</div></article>';
        }).join('');
    }

    function renderPrefight(fight) {
        var data = fight.prefight;
        if (!data) return '<div class="prefight-empty">Pre-fight podatki še niso osveženi.</div>';
        function fighterLine(profile) {
            var activity = profile.activity || {};
            return '<span><b>' + esc(profile.name) + '</b><small>' + (profile.age_at_event == null ? 'starost —' : profile.age_at_event + ' let') + ' · reach ' + (profile.reach_cm == null ? '—' : Number(profile.reach_cm).toFixed(0) + ' cm') + ' · ' + esc(profile.stance || 'stance —') + ' · layoff ' + (activity.days_since_last_fight == null ? '—' : activity.days_since_last_fight + ' dni') + '</small></span>';
        }
        var context = data.event_context || {};
        return '<details class="prefight-data"><summary>Pre-fight podatki <b>quality ' + pct(data.overall_quality, 0) + '</b></summary><div>' + fighterLine(data.fighter_a) + fighterLine(data.fighter_b) + '<span class="prefight-context">Višina prizorišča: ' + (context.altitude_m == null ? 'neznana' : context.altitude_m + ' m') + ' · poškodbe / short notice / weight miss: samo preverjeni podatki, trenutno unknown</span></div></details>';
    }

    function renderLedger(bets) {
        byId('betLedger').innerHTML = bets.length ? bets.filter(function (bet) { return Number(bet.stake) > 0; }).map(function (bet) {
            return '<tr><td><span class="pill ' + esc(bet.owner) + '">' + (bet.owner === 'jev' ? 'Jev' : 'Kristjan') + '</span></td><td>' + esc(bet.selection) + ' zmaga</td><td>' + Number(bet.odds).toFixed(2) + '</td><td>' + euro(bet.stake) + '</td><td>' + esc(bet.result) + '</td><td class="' + (bet.profit > 0 ? 'win' : bet.profit < 0 ? 'loss' : '') + '">' + (bet.profit > 0 ? '+' : '') + euro(bet.profit) + '</td><td>' + (bet.owner === 'jev' && bet.result === 'open' ? '<a class="sportsbook-link" href="' + sportsbookUrl + '" target="_blank" rel="noopener noreferrer nofollow" title="Preveri izbor in trenutno kvoto pred vplačilom">Odpri E-Stave ↗</a>' : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="7">Še ni zaklenjenih stav.</td></tr>';
    }

    function reportSummary(items) { return '<div class="report-summary">' + items.map(function (item) { return '<span><small>' + esc(item[0]) + '</small><b>' + esc(item[1]) + '</b></span>'; }).join('') + '</div>'; }
    function renderEventReport(report) {
        byId('eventReport').innerHTML = report ? reportSummary([['Pravilni zmagovalci', report.correct_winner + '/' + report.total_predictions], ['Metoda', report.correct_method + '/' + report.total_predictions], ['Profit', (report.profit >= 0 ? '+' : '') + euro(report.profit)], ['ROI', pct(report.roi)], ['Brier', report.brier == null ? '—' : Number(report.brier).toFixed(3)], ['Poravnane borbe', report.fights_settled]]) + report.details.map(function (row) { return '<div class="report-row"><span>' + esc(row.fight) + '</span><span>' + esc(row.pick) + ' → ' + esc(row.actual) + '</span><b class="' + (row.winner_correct ? 'correct' : 'wrong') + '">' + (row.winner_correct ? 'PRAV' : 'NAROBE') + '</b></div>'; }).join('') : '';
    }
    function renderBacktest(test) {
        var button = byId('backtestButton');
        if (!test) { byId('backtestReport').innerHTML = ''; button.textContent = 'Zaženi full-card test'; button.disabled = false; button.classList.remove('locked'); return; }
        if (Number(test.version || 1) < 4) {
            byId('backtestReport').innerHTML = '<p class="legacy-note">Prejšnji test je shranjen, vendar je uporabljal samo pet borb na dogodek in staro pravilo vložkov. Novi test zajame main card in prelimse ter nima obveznega vložka.</p>' + reportSummary([['Stari test', test.total_fights + ' borb'], ['Profit', (Number(test.profit) >= 0 ? '+' : '') + euro(test.profit)], ['ROI', pct(Number(test.profit) / Number(test.total_stake))]]);
            button.textContent = 'Zaženi novi full-card test'; button.disabled = false; button.classList.remove('locked'); return;
        }
        var jevRoi = Number(test.total_stake) ? Number(test.profit) / Number(test.total_stake) : 0;
        var favoriteRoi = Number(test.favorite.total_stake) ? Number(test.favorite.profit) / Number(test.favorite.total_stake) : 0;
        var grouped = {};
        (test.results || []).forEach(function (row) { (grouped[row.event_date] = grouped[row.event_date] || []).push(row); });
        var events = (test.event_summaries || []).map(function (event) {
            var rows = (grouped[event.event_date] || []).map(function (row) {
                var placed = Number(row.stake) > 0, won = placed && Number(row.bet_profit) >= 0;
                return '<div class="backtest-bet' + (placed ? '' : ' skipped') + '"><div><b>' + esc(row.bet.selection) + '</b><small>' + esc(row.fighter_a + ' vs ' + row.fighter_b) + '</small><small>rezultat: ' + esc(row.actual_winner) + ' · ' + esc(row.actual_method) + '</small></div><span>kvota <b>' + Number(row.bet.odds).toFixed(2) + '</b></span><span>Jev <b>' + pct(row.bet.p) + '</b></span><span>edge <b>' + (Number(row.bet.edge) * 100).toFixed(1) + ' t.</b></span><span>vložek <b>' + (placed ? euro(row.stake) : 'BREZ STAVE') + '</b></span><strong class="' + (placed ? (won ? 'correct' : 'wrong') : '') + '">' + (placed ? ((Number(row.bet_profit) >= 0 ? '+' : '') + euro(row.bet_profit)) : 'SKIP') + '</strong></div>';
            }).join('');
            return '<details class="backtest-event"><summary><span><b>' + new Date(event.event_date).toLocaleDateString('sl-SI') + '</b><small>' + esc(event.location) + '</small></span><span>stave <b>' + event.bets + '/' + event.total_fights + '</b></span><span>vložek <b>' + euro(event.stake) + '</b></span><span>P/L <b class="' + (Number(event.profit) >= 0 ? 'correct' : 'wrong') + '">' + (Number(event.profit) >= 0 ? '+' : '') + euro(event.profit) + '</b></span></summary><div>' + rows + '</div></details>';
        }).join('');
        byId('backtestReport').innerHTML = reportSummary([['Dogodki', test.event_count], ['Vse borbe', test.total_fights], ['Dejanske stave', test.bet_count], ['Pravilni zmagovalci', test.correct_winner + '/' + test.total_fights], ['Pravilna metoda', test.correct_method + '/' + test.total_fights], ['Preskočene', test.skipped_count], ['Profit', (Number(test.profit) >= 0 ? '+' : '') + euro(test.profit)], ['ROI', pct(jevRoi)], ['Brier', Number(test.brier).toFixed(3)]]) +
            '<div class="strategy-compare two"><article><small>JEV: SAMO MOČNI SIGNALI</small><b>' + (Number(test.profit) >= 0 ? '+' : '') + euro(test.profit) + '</b><span>' + euro(test.total_stake) + ' vložka · ROI ' + pct(jevRoi) + ' · ' + test.skipped_count + ' preskočenih</span></article><article><small>BENCHMARK: VEDNO FAVORIT</small><b>' + (Number(test.favorite.profit) >= 0 ? '+' : '') + euro(test.favorite.profit) + '</b><span>' + euro(test.favorite.total_stake) + ' vložka · ROI ' + pct(favoriteRoi) + '</span></article></div>' +
            reportSummary([['Končni bankroll', euro(test.risk.ending_bankroll)], ['Max. drawdown', euro(test.risk.max_drawdown) + ' · ' + pct(test.risk.max_drawdown_pct)], ['Najdaljša serija porazov', test.risk.max_loss_streak]]) + '<div class="backtest-events">' + events + '</div>';
        button.textContent = 'Full-card backtest je zaklenjen'; button.disabled = true; button.classList.add('locked');
    }

    function renderHistory(events) {
        if (!events.length) { byId('historyList').innerHTML = '<div class="history-empty">Zgodovina je trenutno prazna. Končani dogodki se bodo samodejno pojavili tukaj.</div>'; return; }
        var html = '';
        html += events.map(function (event) {
            var bets = (event.bets || []).filter(function (bet) { return Number(bet.stake) > 0; });
            var jevProfit = bets.filter(function (b) { return b.owner === 'jev'; }).reduce(function (sum, b) { return sum + Number(b.profit); }, 0);
            var meProfit = bets.filter(function (b) { return b.owner === 'me'; }).reduce(function (sum, b) { return sum + Number(b.profit); }, 0);
            var fights = (event.fights || []).map(function (fight) { return '<div class="history-fight"><span><b>' + esc(fight.fighter_a) + '</b> vs ' + esc(fight.fighter_b) + '</span><span>Jev: ' + esc(fight.winner_pick || 'brez napovedi') + '</span><span>Rezultat: ' + esc(fight.winner || 'še ni vpisan') + '</span></div>'; }).join('');
            var betRows = bets.length ? '<div class="table-wrap history-bets"><table><thead><tr><th>Igralec</th><th>Stava</th><th>Kvota</th><th>Vložek</th><th>P/L</th></tr></thead><tbody>' + bets.map(function (bet) { return '<tr><td>' + (bet.owner === 'jev' ? 'Jev' : 'Kristjan') + '</td><td>' + esc(bet.selection) + ' zmaga</td><td>' + Number(bet.odds).toFixed(2) + '</td><td>' + euro(bet.stake) + '</td><td class="' + (bet.profit >= 0 ? 'win' : 'loss') + '">' + euro(bet.profit) + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<p class="history-empty">Na tem dogodku ni evidentiranih stav.</p>';
            return '<details class="history-event"><summary><div><h3>' + esc(event.name) + '</h3><p>' + new Date(event.event_date).toLocaleDateString('sl-SI', { dateStyle: 'long' }) + ' · ' + esc(event.venue) + '</p></div><span>Jev <b>' + (jevProfit >= 0 ? '+' : '') + euro(jevProfit) + '</b></span><span>Ti <b>' + (meProfit >= 0 ? '+' : '') + euro(meProfit) + '</b></span></summary><div class="history-content">' + fights + betRows + '</div></details>';
        }).join('');
        byId('historyList').innerHTML = html;
    }

    function setView(view) {
        state.view = view;
        byId('currentView').hidden = view !== 'current';
        byId('historyView').hidden = view !== 'history';
        byId('backtestView').hidden = view !== 'backtest';
        byId('currentTab').className = view === 'current' ? 'active' : '';
        byId('historyTab').className = view === 'history' ? 'active' : '';
        byId('backtestTab').className = view === 'backtest' ? 'active' : '';
    }

    function fightById(id) { return state.data.fights.find(function (fight) { return Number(fight.id) === Number(id); }); }
    function showFightDialog(name, id) {
        var fight = fightById(id), prefix = name === 'bet' ? 'bet' : 'settle'; byId(prefix + 'FightId').value = id;
        var select = byId(name === 'bet' ? 'betSelection' : 'settleWinner');
        select.innerHTML = '<option>' + esc(fight.fighter_a) + '</option><option>' + esc(fight.fighter_b) + '</option>';
        if (name === 'bet') byId('betOdds').value = fight.odds_a ? Number(fight.odds_a).toFixed(2) : '';
        byId(name + 'Modal').showModal();
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open]'); if (opener) byId(opener.dataset.open).showModal();
        var saveOdds = event.target.closest('.save-odds');
        if (saveOdds) { var card = saveOdds.closest('.fight-card'); saveOdds.disabled = true; request('save_odds', 'POST', { fight_id: saveOdds.dataset.id, odds_a: card.querySelector('.odds-a').value, odds_b: card.querySelector('.odds-b').value }).then(function () { toast('Kvote shranjene.'); load(); }).catch(function (error) { toast(error.message, true); saveOdds.disabled = false; }); }
        var predictor = event.target.closest('.predict-one'); if (predictor) predictOne(Number(predictor.dataset.id), predictor, false);
        var bet = event.target.closest('.my-bet'); if (bet) showFightDialog('bet', bet.dataset.id);
        var settle = event.target.closest('.settle'); if (settle) showFightDialog('settle', settle.dataset.id);
    });

    function predictOne(id, button, silent) {
        if (button) { button.disabled = true; button.textContent = 'Jev razmišlja …'; }
        return request('predict', 'POST', { fight_id: id }).then(function () { if (!silent) { toast('Napoved je zaklenjena.'); load(); } }).catch(function (error) { toast(error.message, true); if (button) { button.disabled = false; button.textContent = 'Analiziraj'; } throw error; });
    }

    byId('currentTab').addEventListener('click', function () { setView('current'); });
    byId('historyTab').addEventListener('click', function () { setView('history'); });
    byId('backtestTab').addEventListener('click', function () { setView('backtest'); });
    byId('eventSelect').addEventListener('change', function () { state.eventId = Number(this.value); state.view = 'current'; load(); });
    byId('syncButton').addEventListener('click', function () { var button = this; button.disabled = true; button.textContent = 'Uvažam podatke …'; request('sync_history', 'POST', { event_limit: 80 }).then(function (result) { toast('Uvoz končan: ' + result.sync.fights_written + ' zapisov.'); load(); }).catch(function (error) { toast(error.message, true); }).finally(function () { button.disabled = false; button.textContent = 'Osveži zgodovino'; }); });
    byId('oddsButton').addEventListener('click', function () { var button = this; button.disabled = true; button.textContent = 'Iščem kvote …'; request('sync_odds', 'POST', {}).then(function (result) { toast('Kvote posodobljene za ' + result.sync.updated_fights + ' borb.'); load(); }).catch(function (error) { toast(error.message, true); }).finally(function () { button.disabled = false; button.textContent = 'Osveži kvote'; }); });
    byId('prefightButton').addEventListener('click', function () { var button = this; button.disabled = true; button.textContent = 'Gradim pre-fight profile …'; request('refresh_prefight', 'POST', { event_id: state.eventId }).then(function (result) { toast('Pre-fight podatki osveženi za ' + result.sync.fights_updated + ' borb · kakovost ' + pct(result.sync.average_quality, 0) + '.'); load(); }).catch(function (error) { toast(error.message, true); }).finally(function () { button.disabled = false; button.textContent = 'Osveži pre-fight podatke'; }); });
    byId('predictAllButton').addEventListener('click', async function () {
        var button = this, pending; button.disabled = true;
        try {
            button.textContent = 'Osvežujem pre-fight podatke …';
            var enrichment = await request('refresh_prefight', 'POST', { event_id: state.eventId });
            var dashboard = await request('dashboard');
            state.data = dashboard; state.eventId = dashboard.event_id; render();
            pending = state.data.fights.filter(function (fight) { return (!fight.prediction_id || !Number(fight.prediction_enriched)) && !Number(fight.completed); });
            toast('Pred analizo osveženih ' + enrichment.sync.fights_updated + ' pre-fight profilov · kakovost ' + pct(enrichment.sync.average_quality, 0) + '.');
            for (var i = 0; i < pending.length; i++) { button.textContent = 'Jev ' + (i + 1) + '/' + pending.length; await predictOne(Number(pending[i].id), null, true); }
            button.textContent = 'Izbiram samo najmočnejše stave …';
            var result = await request('finalize_portfolio', 'POST', { event_id: state.eventId });
            toast(result.portfolio.total_stake > 0 ? 'Jevov selektivni portfolio je zaklenjen: ' + euro(result.portfolio.total_stake) + '.' : 'Jev ni našel dovolj močnega edga — ta dogodek ostane brez stave.'); load();
        } catch (error) { toast(error.message, true); }
        button.disabled = false; button.textContent = 'Analiziraj cel card + sestavi portfolio';
    });
    byId('completeEventButton').addEventListener('click', function () { var button = this; button.disabled = true; button.textContent = 'Berem dejanske rezultate …'; request('complete_event', 'POST', { event_id: state.eventId }).then(function () { toast('Dogodek je poravnan in Jev ocenjen.'); load(); }).catch(function (error) { toast(error.message, true); }).finally(function () { button.disabled = false; button.textContent = 'Dogodek je končan — preveri Jeva'; }); });
    byId('backtestButton').addEventListener('click', async function () {
        var button = this, progress = byId('backtestProgress'), completed = false;
        button.disabled = true; button.classList.add('loading'); progress.hidden = false;
        try {
            while (!completed) {
                var result = await request('run_backtest', 'POST', {});
                completed = !!result.complete;
                if (completed) {
                    state.data.backtest = result.backtest; renderBacktest(result.backtest);
                    progress.querySelector('span').textContent = '20/20 dogodkov'; progress.querySelector('i').style.width = '100%';
                    toast('20-eventni walk-forward test je zaključen in zaklenjen.');
                } else {
                    var ratio = Number(result.completed_events) / Number(result.total_events);
                    button.textContent = 'Jev testira ' + result.completed_events + '/' + result.total_events + ' dogodkov …';
                    progress.querySelector('span').textContent = result.completed_events + '/' + result.total_events + ' · zadnji ' + result.last_event;
                    progress.querySelector('i').style.width = (ratio * 100) + '%';
                }
            }
        } catch (error) {
            toast(error.message + ' Napredek je shranjen; naslednji klik nadaljuje.', true);
        } finally {
            button.classList.remove('loading');
            if (!completed) { button.disabled = false; button.textContent = 'Nadaljuj 20-eventni test'; }
            if (completed) setTimeout(function () { progress.hidden = true; }, 1200);
        }
    });
    byId('betSelection').addEventListener('change', function () { var fight = fightById(byId('betFightId').value); byId('betOdds').value = this.value === fight.fighter_a ? (fight.odds_a || '') : (fight.odds_b || ''); });
    byId('saveSettings').addEventListener('click', function () { var key = byId('apiKeyInput').value.trim(), oddsKey = byId('oddsKeyInput').value.trim(); if (key) sessionStorage.setItem('typesafeKey', key); if (oddsKey) sessionStorage.setItem('oddsKey', oddsKey); request('settings', 'POST', { kelly_fraction: byId('kellyInput').value, max_bet_fraction: byId('maxBetInput').value / 100, max_event_fraction: byId('maxEventInput').value / 100, min_edge: byId('minEdgeInput').value / 100 }).then(function () { byId('settingsModal').close(); toast('Strategija shranjena.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveBet').addEventListener('click', function () { request('my_bet', 'POST', { fight_id: byId('betFightId').value, selection: byId('betSelection').value, odds: byId('betOdds').value, stake: byId('betStake').value }).then(function () { byId('betModal').close(); toast('Tvoja stava je zaklenjena.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveResult').addEventListener('click', function () { request('settle_fight', 'POST', { fight_id: byId('settleFightId').value, winner: byId('settleWinner').value, method: byId('settleMethod').value, round: byId('settleRound').value }).then(function () { byId('settleModal').close(); toast('Rezultat poravnan.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveEvent').addEventListener('click', function () { request('create_event', 'POST', { name: byId('newEventName').value, event_date: byId('newEventDate').value, venue: byId('newEventVenue').value, source_url: byId('newEventUrl').value }).then(function (response) { state.eventId = response.event_id; byId('eventModal').close(); toast('Dogodek ustvarjen.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveFight').addEventListener('click', function () { request('add_fight', 'POST', { event_id: state.eventId, fighter_a: byId('newFighterA').value, fighter_b: byId('newFighterB').value, weight_class: byId('newWeightClass').value, card_section: byId('newCardSection').value, odds_a: byId('newOddsA').value, odds_b: byId('newOddsB').value }).then(function () { byId('fightModal').close(); toast('Borba dodana.'); load(); }).catch(function (error) { toast(error.message, true); }); });

    load();
}());
