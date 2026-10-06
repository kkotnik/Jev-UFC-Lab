(function () {
    'use strict';

    var savedMode = sessionStorage.getItem('jevMode');
    var startMode = 'ticket';
    if (savedMode === 'single') {
        startMode = 'single';
    }
    var state = { data: null, eventId: null, view: 'current', mode: startMode };
    var byId = function (id) { return document.getElementById(id); };
    var esc = function (value) { var div = document.createElement('div'); div.textContent = value == null ? '' : String(value); return div.innerHTML; };
    var euro = function (value) { return new Intl.NumberFormat('sl-SI', { style: 'currency', currency: 'EUR' }).format(Number(value || 0)); };
    var pct = function (value, digits) { return (Number(value || 0) * 100).toFixed(digits == null ? 1 : digits) + '%'; };
    function sportsbookUrl() {
        if (state.mode === 'single') {
            if (state.data && state.data.stake_url) {
                return state.data.stake_url;
            }
            return 'https://stake.com/sports/mma/ufc';
        }
        return 'https://www.e-stave.com/stave';
    }

    function sportsbookLabel() {
        if (state.mode === 'single') {
            return 'Odpri Stake ↗';
        }
        return 'Odpri E-Stave ↗';
    }

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
        var node = byId('toast');
        node.textContent = message;
        if (error) {
            node.className = 'toast show error';
        } else {
            node.className = 'toast show';
        }
        clearTimeout(toast.timer); toast.timer = setTimeout(function () { node.className = 'toast'; }, 4200);
    }

    function isTicketMarket(market) {
        if (market === 'kombinacija') {
            return true;
        }
        if (market === 'sistem') {
            return true;
        }
        return false;
    }

    function modeBets(bets) {
        var out = [];
        (bets || []).forEach(function (bet) {
            if (Number(bet.stake) <= 0) {
                return;
            }
            if (bet.owner === 'me') {
                out.push(bet);
                return;
            }
            if (state.mode === 'ticket') {
                if (isTicketMarket(bet.market)) {
                    out.push(bet);
                }
                return;
            }
            if (!isTicketMarket(bet.market)) {
                out.push(bet);
            }
        });
        return out;
    }

    function currentBacktest() {
        if (!state.data) {
            return null;
        }
        if (state.mode === 'single') {
            if (state.data.backtest_single_method) {
                return state.data.backtest_single_method;
            }
            if (state.data.backtest_single) {
                return state.data.backtest_single;
            }
            return null;
        }
        if (state.data.backtest_ticket) {
            return state.data.backtest_ticket;
        }
        return state.data.backtest || null;
    }

    function currentJevMetrics() {
        if (state.mode === 'single') {
            return state.data.metrics.jev_single;
        }
        return state.data.metrics.jev_ticket;
    }

    function applyModeCopy() {
        var single = state.mode === 'single';
        byId('modeSingle').className = '';
        byId('modeTicket').className = '';
        if (single) {
            byId('modeSingle').className = 'active';
            byId('ledeText').textContent = 'Jev ti za vsako borbo pove, kaj zakleniti v dnevnik: moneyline ali Stake winning method. Method samo, če je na ta izid dovolj ziher (npr. decision 80 %+). Sicer samo zmagovalec.';
            byId('jevModeLabel').textContent = 'SINGLE BET';
            byId('betGuide').className = 'bet-guide single-mode';
            byId('betGuideTitle').textContent = 'Isto kot dnevnik: ena priporočena stava na borbo';
            byId('betGuideA').innerHTML = '<b>Jev pove STAVI.</b> Moneyline = zmaga na kakršen koli način. Winning method = KO/TKO, Submission ali Decision. Če ni dovolj ziher na metodo, ostane moneyline. Draw ne stavimo.';
            byId('betGuideB').textContent = '';
            byId('ledgerHint').textContent = 'To so Jevove zaklenjene stave za ta dogodek — isto pravilo kot backtest po datumih. Povezava odpre Stake. Stave ne odda. 18+.';
            byId('historyIntro').textContent = 'Zgodovina dnevnikov: samo zaklenjene stave, ne cel card.';
            byId('backtestIntro').textContent = 'Vsak datum je en dogodek, kot da si tisti teden kliknil Analiziraj cel card. Vrstice so točno tiste, ki bi šle v Stavni dnevnik. Borbe brez stave niso v dnevniku.';
            byId('backtestH2').textContent = 'Dnevnik po dogodkih';
            byId('backtestRules').innerHTML = '<span>isti dnevnik</span><span>ne cel card</span><span>moneyline ali method</span><span>edge ≥ 5 točk</span><span>½ Kelly</span>';
            byId('backtestBlurb').textContent = 'Odpri 27. 9. (ali drug datum): to je tedenski dnevnik, ne seznam vseh borb.';
            byId('predictAllButton').textContent = 'Analiziraj cel card + napolni dnevnik';
            byId('ledgerPickHead').textContent = 'Jev priporočilo';
        } else {
            byId('modeTicket').className = 'active';
            byId('ledeText').textContent = 'Jev sestavi en E-Stave listek na dogodek: sistem 2/3 pri treh kandidatih, kombinacija pri dveh. Pove, koliko vplačati. Ena noga na sistemu sme pasti.';
            byId('jevModeLabel').textContent = 'E-STAVE LISTEK';
            byId('betGuide').className = 'bet-guide';
            byId('betGuideTitle').textContent = 'E-Stave: en listek na dogodek';
            byId('betGuideA').innerHTML = '<b>3 borci = Sistem 2/3.</b> Iskalec stav → trije borci z listka → kvota Zmagovalec → tip Sistem → 2 iz 3. Vplačilo na kombinacijo = znesek s kartice (× 3 kombinaciji = skupaj).';
            byId('betGuideB').innerHTML = '<b>2 borca = Kombinacija.</b> Obe nogi morata zmagati. Če je samo 1 kandidat, listka ni. Pred oddajo preveri vsako kvoto.';
            byId('ledgerHint').textContent = 'Virtualni model ni zagotovilo dobička. Povezava odpre E-Stave, stave pa ne odda. Pred vplačilom preveri vsako kvoto na listku; samo 18+.';
            byId('historyIntro').textContent = 'Zgodovina listkov: zaklenjene napovedi, tvoje stave, Jevov E-Stave listek, rezultati ter P/L.';
            byId('backtestIntro').textContent = 'Isti Jevovi picki kot prej. Vložek je E-Stave listek: sistem 2/3 (3 noge, ena sme pasti) ali kombinacija (2 nogi, obe morata zadeniti). 15 % davek na dobitek nad 300 € je vključen. Kvote so zgodovinske tržne, ne E-Stave.';
            byId('backtestH2').textContent = 'E-Stave listek';
            byId('backtestRules').innerHTML = '<span>sistem 2/3</span><span>kombinacija 2</span><span>edge ≥ 5 točk</span><span>confidence ≥ 55 %</span><span>½ Kelly</span>';
            byId('backtestBlurb').textContent = 'Če ni vsaj dveh kandidatov, dogodek nima listka. Primerjava s starimi posameznimi stavami je na istem Jevovem researchu.';
            byId('predictAllButton').textContent = 'Analiziraj cel card + sestavi listek';
            byId('ledgerPickHead').textContent = 'Izbor';
        }
    }

    function setMode(mode) {
        state.mode = mode;
        sessionStorage.setItem('jevMode', mode);
        if (state.data) {
            render();
        } else {
            applyModeCopy();
        }
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
            var selected = '';
            if (Number(event.id) === Number(data.event_id)) {
                selected = ' selected';
            }
            return '<option value="' + Number(event.id) + '"' + selected + '>' + esc(event.name) + '</option>';
        }).join('');
        if (activeEvent) {
            byId('eventTitle').textContent = activeEvent.name;
            byId('eventMeta').textContent = new Date(activeEvent.event_date).toLocaleString('sl-SI', { dateStyle: 'full', timeStyle: 'short' }) + ' · ' + activeEvent.venue;
        } else {
            byId('eventTitle').textContent = 'Ni dogodka';
            byId('eventMeta').textContent = '';
        }
        byId('historyCount').textContent = Number(data.history_count).toLocaleString('sl-SI');
        byId('historyBadge').textContent = data.history.length;
        var test = currentBacktest();
        if (test && Number(test.event_count)) {
            byId('backtestBadge').textContent = test.event_count;
        } else {
            byId('backtestBadge').textContent = 'novo';
        }
        if (data.brier == null) {
            byId('brierScore').textContent = 'še brez rezultatov';
        } else {
            byId('brierScore').textContent = Number(data.brier).toFixed(3) + ' (nižji je boljši)';
        }
        var configured = data.api_configured || !!sessionStorage.getItem('typesafeKey');
        if (configured) {
            byId('apiBadge').className = 'status online';
            byId('apiBadge').lastChild.nodeValue = ' Jev pripravljen';
        } else {
            byId('apiBadge').className = 'status';
            byId('apiBadge').lastChild.nodeValue = ' Ključ ni nastavljen';
        }
        applyModeCopy();
        renderMetric('jev', currentJevMetrics());
        renderMetric('me', data.metrics.me);
        byId('kellyInput').value = data.settings.kelly_fraction;
        byId('maxBetInput').value = Number(data.settings.max_bet_fraction) * 100;
        byId('maxEventInput').value = Number(data.settings.max_event_fraction) * 100;
        byId('minEdgeInput').value = Number(data.settings.min_edge) * 100;
        renderFights(data.fights);
        renderEstaveTickets(data.bets);
        renderLedger(data.bets);
        renderEventReport(data.event_report);
        renderBacktest(test);
        renderHistory(data.history);
        setView(state.view);
    }

    function renderMetric(prefix, metric) {
        byId(prefix + 'Bankroll').textContent = euro(metric.bankroll); byId(prefix + 'Available').textContent = euro(metric.available);
        byId(prefix + 'Roi').textContent = pct(metric.roi); byId(prefix + 'Record').textContent = metric.wins + '–' + metric.losses;
        var profit = byId(prefix + 'Profit'); profit.textContent = (metric.profit >= 0 ? '+' : '') + euro(metric.profit); profit.className = metric.profit < 0 ? 'negative' : '';
    }

    function parseTicketBet(bet) {
        if (!bet || (bet.market !== 'kombinacija' && bet.market !== 'sistem')) return null;
        try {
            var parsed = JSON.parse(bet.selection);
            if (!parsed || !parsed.legs) return null;
            if (parsed.type !== 'kombinacija' && parsed.type !== 'sistem') return null;
            return parsed;
        } catch (error) {
            return null;
        }
    }

    function betLabel(bet) {
        var ticket = parseTicketBet(bet);
        if (ticket) {
            if (ticket.type === 'sistem') return ticket.label + ' (sistem 2/3)';
            return ticket.label + ' (kombinacija)';
        }
        if (bet.market === 'winning_method') {
            return 'STAVI METHOD: ' + bet.selection;
        }
        return 'STAVI MONEYLINE: ' + bet.selection + ' zmaga';
    }

    function ticketByFight(bets) {
        var map = {};
        (bets || []).forEach(function (bet) {
            if (Number(bet.stake) <= 0) return;
            var ticket = parseTicketBet(bet);
            if (!ticket) return;
            ticket.stake = Number(bet.stake);
            ticket.odds = Number(bet.odds);
            ticket.result = bet.result;
            ticket.payout = Number(bet.stake) * Number(bet.odds);
            ticket.legs.forEach(function (leg) {
                map[Number(leg.fight_id)] = { ticket: ticket, leg: leg, bet: bet };
            });
        });
        return map;
    }

    function jevSingleByFight(bets) {
        var map = {};
        (bets || []).forEach(function (bet) {
            if (bet.owner !== 'jev') {
                return;
            }
            if (Number(bet.stake) <= 0) {
                return;
            }
            if (isTicketMarket(bet.market)) {
                return;
            }
            map[Number(bet.fight_id)] = bet;
        });
        return map;
    }

    function renderMethodMarkets(fight) {
        if (state.mode !== 'single') {
            return ' <small>(ločeno od moneyline stave)</small>';
        }
        var markets = fight.method_markets || [];
        if (!markets.length) {
            return ' <small>Stake winning method: KO/TKO · Submission · Decision. Preveri kvoto na Stake.</small>';
        }
        var html = '<div class="method-markets">';
        markets.forEach(function (market) {
            var cls = '';
            if (Number(market.edge) >= Number(state.data.settings.min_edge || 0.05)) {
                cls = ' has-edge';
            }
            html += '<span class="method-chip' + cls + '"><b>' + esc(market.selection) + '</b> ' + Number(market.odds).toFixed(2) + ' · Jev ' + pct(market.p) + '</span>';
        });
        html += '</div><small>Ocenjene Stake method kvote (+18 % juice). Pred vplačilom preveri na Stake.</small>';
        return html;
    }

    function renderFights(fights) {
        if (!fights.length) { byId('fightList').innerHTML = '<div class="empty">Card še ni objavljen ali ga ni bilo mogoče uvoziti. Lahko ga dodaš ročno.</div>'; return; }
        var onTicket = ticketByFight(state.data.bets);
        var singles = jevSingleByFight(state.data.bets);
        var minEdge = Number(state.data.settings.min_edge || 0.05);
        byId('fightList').innerHTML = fights.map(function (fight, index) {
            var predicted = fight.prediction_id != null;
            var betHtml = '';
            if (state.mode === 'ticket') {
                var ticketInfo = onTicket[Number(fight.id)];
                betHtml = '<div class="bet-call none">BREZ STAVE — ni na listku</div>';
                if (ticketInfo) {
                    betHtml = '<div class="bet-call"><strong>NA LISTKU: ' + esc(ticketInfo.leg.selection) + ' zmaga</strong>' +
                        '<small>Kombinacija ' + esc(ticketInfo.ticket.label) + ' · skupna kvota ' + Number(ticketInfo.ticket.odds).toFixed(2) + ' · vložek listka ' + euro(ticketInfo.ticket.stake) + '</small></div>';
                } else if (predicted && Number(fight.edge) >= minEdge && Number(fight.confidence) >= 0.55) {
                    betHtml = '<div class="bet-call none">KANDIDAT — čaka na listek (min 2 nogi)</div>';
                }
            } else {
                var singleBet = singles[Number(fight.id)];
                betHtml = '<div class="bet-call none">BREZ STAVE — ni kvote ali edge ni dovolj velik</div>';
                if (singleBet) {
                    var probability = Number(fight.p_a);
                    if (singleBet.selection === fight.fighter_b) {
                        probability = Number(fight.p_b);
                    }
                    if (singleBet.market === 'winning_method') {
                        probability = Number(fight.confidence);
                        (fight.method_markets || []).forEach(function (market) {
                            if (market.selection === singleBet.selection) {
                                probability = Number(market.p);
                            }
                        });
                    }
                    var odds = Number(singleBet.odds);
                    var stake = Number(singleBet.stake);
                    var implied = 1 / odds;
                    var edgePts = (probability - implied) * 100;
                    var title = 'STAVI MONEYLINE: ' + esc(singleBet.selection) + ' zmaga';
                    if (singleBet.market === 'winning_method') {
                        title = 'STAVI METHOD: ' + esc(singleBet.selection);
                    }
                    betHtml = '<div class="bet-call"><strong>' + title + '</strong>' +
                        '<small>Vložek ' + euro(stake) + ' · kvota ' + odds.toFixed(2) + ' · možno izplačilo ' + euro(stake * odds) + ' · možni čisti dobiček ' + euro(stake * (odds - 1)) + '</small>' +
                        '<small>Jev ' + pct(probability) + ' proti tržnih ' + pct(implied) + ' → edge +' + edgePts.toFixed(1) + ' odstotne točke</small>' +
                        '<a class="sportsbook-link" href="' + sportsbookUrl() + '" target="_blank" rel="noopener noreferrer nofollow">Odpri Stake ↗</a></div>';
                }
            }
            var prefight = renderPrefight(fight);
            var analysis = '';
            if (predicted) {
                analysis = '<div class="prob-head"><b>Jev izbere: ' + esc(fight.winner_pick) + '</b><span class="confidence">confidence ' + pct(fight.confidence) + '</span></div>' +
                    '<div class="prob-line"><span>' + esc(fight.fighter_a) + '</span><div class="bar"><i style="width:' + (fight.p_a * 100) + '%"></i></div><b>' + pct(fight.p_a, 0) + '</b></div>' +
                    '<div class="prob-line second"><span>' + esc(fight.fighter_b) + '</span><div class="bar"><i style="width:' + (fight.p_b * 100) + '%"></i></div><b>' + pct(fight.p_b, 0) + '</b></div>' +
                    '<div class="method">Napoved načina zaključka: <b>' + esc(fight.method_pick) + '</b>' + renderMethodMarkets(fight) + '</div>' + betHtml;
            } else {
                analysis = '<div class="awaiting">Jev še ni analiziral borbe. Najprej preveri kvote in sinhroniziraj zgodovino.</div>';
            }
            var result = '';
            var predictedAttr = '';
            var predictLabel = 'Analiziraj';
            if (predicted) {
                predictedAttr = ' disabled';
                predictLabel = 'Zaklenjeno';
            }
            if (Number(fight.completed)) {
                var roundBit = '';
                if (fight.result_round) {
                    roundBit = ' · R' + Number(fight.result_round);
                }
                result = '<div class="result-badge">' + esc(fight.winner) + '<br>' + esc(fight.method || '') + roundBit + '</div>';
            } else {
                result = '<button class="button primary predict-one" data-id="' + Number(fight.id) + '"' + predictedAttr + '>' + predictLabel + '</button><button class="button human-button my-bet" data-id="' + Number(fight.id) + '">Moja stava</button><button class="button ghost settle" data-id="' + Number(fight.id) + '">Rezultat</button>';
            }
            var oddsA = '';
            var oddsB = '';
            if (fight.odds_a != null) {
                oddsA = Number(fight.odds_a).toFixed(2);
            }
            if (fight.odds_b != null) {
                oddsB = Number(fight.odds_b).toFixed(2);
            }
            var oddsDisabled = '';
            var saveOddsBtn = '<button class="save-odds" data-id="' + Number(fight.id) + '">shrani</button>';
            if (predicted) {
                oddsDisabled = 'disabled';
                saveOddsBtn = '';
            }
            var completedClass = '';
            if (Number(fight.completed)) {
                completedClass = ' completed';
            }
            return '<article class="fight-card' + completedClass + '"><div class="fight-num"><strong>' + String(index + 1).padStart(2, '0') + '</strong><small>' + esc(fight.card_section) + '</small></div>' +
                '<div class="matchup"><span class="weight">' + esc(fight.weight_class) + '</span><div class="names">' + esc(fight.fighter_a) + '<span class="vs">vs</span>' + esc(fight.fighter_b) + '</div>' +
                '<div class="odds-edit"><label><span class="odds-name">' + esc(fight.fighter_a) + '</span><input class="odds-a" aria-label="Kvota za ' + esc(fight.fighter_a) + '" type="number" min="1.01" step="0.01" value="' + oddsA + '" ' + oddsDisabled + '></label>' +
                '<label><span class="odds-name">' + esc(fight.fighter_b) + '</span><input class="odds-b" aria-label="Kvota za ' + esc(fight.fighter_b) + '" type="number" min="1.01" step="0.01" value="' + oddsB + '" ' + oddsDisabled + '></label>' + saveOddsBtn + '</div>' + prefight + '</div><div class="analysis">' + analysis + '</div><div class="fight-actions">' + result + '</div></article>';
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

    function renderEstaveTickets(bets) {
        var wrap = byId('estaveTicketsWrap');
        if (state.mode !== 'ticket') {
            wrap.hidden = true;
            byId('estaveTickets').innerHTML = '';
            return;
        }
        var html = '';
        (bets || []).forEach(function (bet) {
            if (bet.owner !== 'jev' || Number(bet.stake) <= 0) return;
            var ticket = parseTicketBet(bet);
            if (!ticket) return;
            var legs = '';
            ticket.legs.forEach(function (leg, index) {
                var noga = index + 1;
                legs += '<div class="estave-leg"><div><b>' + noga + '. ' + esc(leg.selection) + '</b><span>' + esc(leg.fighter_a) + ' vs ' + esc(leg.fighter_b) + '</span></div><span>kvota <b>' + Number(leg.odds).toFixed(2) + '</b></span><span>Jev <b>' + pct(leg.p) + '</b></span><span>edge <b>' + (Number(leg.edge) * 100).toFixed(1) + ' t.</b></span></div>';
            });
            var title = 'Listek · kombinacija';
            var steps = 'Na E-Stave: Iskalec stav → obe nogi → Zmagovalec → tip Kombinacija → vplačilo ' + euro(bet.stake) + ' → oddaj. Pred oddajo preveri vsako kvoto.';
            var totals = '<div class="estave-totals"><span><small>Skupna kvota</small><b>' + Number(bet.odds).toFixed(2) + '</b></span><span><small>Vplačilo</small><b>' + euro(bet.stake) + '</b></span><span><small>Možno izplačilo</small><b>' + euro(Number(bet.stake) * Number(bet.odds)) + '</b></span><span><small>Jev p</small><b>' + pct(ticket.combined_p) + '</b></span></div>';
            if (ticket.type === 'sistem') {
                title = 'Listek · sistem 2/3';
                steps = 'Na E-Stave: Iskalec stav → trije borci → Zmagovalec → tip Sistem → 2 iz 3 → vplačilo na kombinacijo ' + euro(ticket.unit_stake) + ' (skupaj ' + euro(bet.stake) + '). Ena noga sme pasti. Pred oddajo preveri vsako kvoto.';
                totals = '<div class="estave-totals"><span><small>Vplačilo na kombinacijo</small><b>' + euro(ticket.unit_stake) + '</b></span><span><small>Skupaj (×3)</small><b>' + euro(bet.stake) + '</b></span><span><small>Če zadenejo vsi 3</small><b>' + euro(Number(bet.stake) * Number(bet.odds)) + '</b></span><span><small>Jev p vsi 3</small><b>' + pct(ticket.combined_p) + '</b></span></div>';
            }
            html += '<article class="estave-ticket"><header><div><h3>' + title + '</h3><small>' + esc(ticket.label) + '</small></div><b>' + euro(bet.stake) + '</b></header>' +
                legs +
                totals +
                '<p class="estave-steps">' + steps + '</p>' +
                '<p><a class="sportsbook-link" href="' + sportsbookUrl() + '" target="_blank" rel="noopener noreferrer nofollow">' + sportsbookLabel() + '</a></p></article>';
        });
        if (html === '') {
            wrap.hidden = true;
            byId('estaveTickets').innerHTML = '';
            return;
        }
        wrap.hidden = false;
        byId('estaveTickets').innerHTML = html;
    }

    function renderLedger(bets) {
        var rows = modeBets(bets);
        if (!rows.length) {
            byId('betLedger').innerHTML = '<tr><td colspan="7">Še ni zaklenjenih stav.</td></tr>';
            return;
        }
        byId('betLedger').innerHTML = rows.map(function (bet) {
            var owner = 'Kristjan';
            if (bet.owner === 'jev') owner = 'Jev';
            var profitClass = '';
            if (bet.profit > 0) profitClass = 'win';
            else if (bet.profit < 0) profitClass = 'loss';
            var profitPrefix = '';
            if (bet.profit > 0) profitPrefix = '+';
            var link = '';
            if (bet.owner === 'jev' && bet.result === 'open') {
                link = '<a class="sportsbook-link" href="' + sportsbookUrl() + '" target="_blank" rel="noopener noreferrer nofollow" title="Preveri izbor in trenutno kvoto pred vplačilom">' + sportsbookLabel() + '</a>';
            }
            return '<tr><td><span class="pill ' + esc(bet.owner) + '">' + owner + '</span></td><td>' + esc(betLabel(bet)) + '</td><td>' + Number(bet.odds).toFixed(2) + '</td><td>' + euro(bet.stake) + '</td><td>' + esc(bet.result) + '</td><td class="' + profitClass + '">' + profitPrefix + euro(bet.profit) + '</td><td>' + link + '</td></tr>';
        }).join('');
    }

    function reportSummary(items) { return '<div class="report-summary">' + items.map(function (item) { return '<span><small>' + esc(item[0]) + '</small><b>' + esc(item[1]) + '</b></span>'; }).join('') + '</div>'; }
    function renderEventReport(report) {
        byId('eventReport').innerHTML = report ? reportSummary([['Pravilni zmagovalci', report.correct_winner + '/' + report.total_predictions], ['Metoda', report.correct_method + '/' + report.total_predictions], ['Profit', (report.profit >= 0 ? '+' : '') + euro(report.profit)], ['ROI', pct(report.roi)], ['Brier', report.brier == null ? '—' : Number(report.brier).toFixed(3)], ['Poravnane borbe', report.fights_settled]]) + report.details.map(function (row) { return '<div class="report-row"><span>' + esc(row.fight) + '</span><span>' + esc(row.pick) + ' → ' + esc(row.actual) + '</span><b class="' + (row.winner_correct ? 'correct' : 'wrong') + '">' + (row.winner_correct ? 'PRAV' : 'NAROBE') + '</b></div>'; }).join('') : '';
    }
    function renderBacktest(test) {
        if (state.mode === 'single') {
            renderSingleBacktest(test);
            return;
        }
        renderTicketBacktest(test);
    }

    function renderSingleBacktest(test) {
        var button = byId('backtestButton');
        if (!test) {
            byId('backtestReport').innerHTML = '';
            button.textContent = 'Pokaži single bet backtest';
            button.disabled = false;
            button.classList.remove('locked');
            return;
        }
        if (Number(test.version || 1) < 4) {
            byId('backtestReport').innerHTML = '<p class="legacy-note">Shranjen je starejši test z ožjim cardom. Single bet backtest v5 je v bazi pod concentrated full-card.</p>';
            button.textContent = 'Pokaži single bet backtest';
            button.disabled = false;
            button.classList.remove('locked');
            return;
        }
        var winnerOnly = test.winner_only || null;
        var locked = test.winner_only_locked || null;
        if (!locked && state.data.backtest_single) {
            locked = {
                profit: state.data.backtest_single.profit,
                total_stake: state.data.backtest_single.total_stake,
                ending_bankroll: state.data.backtest_single.risk ? state.data.backtest_single.risk.ending_bankroll : 0,
                bet_count: state.data.backtest_single.bet_count || 0
            };
        }
        var jevRoi = 0;
        if (Number(test.total_stake)) {
            jevRoi = Number(test.profit) / Number(test.total_stake);
        }
        var favoriteRoi = 0;
        if (test.favorite && Number(test.favorite.total_stake)) {
            favoriteRoi = Number(test.favorite.profit) / Number(test.favorite.total_stake);
        }
        var grouped = {};
        (test.results || []).forEach(function (row) {
            if (!grouped[row.event_date]) {
                grouped[row.event_date] = [];
            }
            grouped[row.event_date].push(row);
        });
        var events = (test.event_summaries || []).map(function (event) {
            var ledgerRows = [];
            if (event.ledger && event.ledger.length) {
                ledgerRows = event.ledger;
            } else {
                (grouped[event.event_date] || []).forEach(function (row) {
                    if (Number(row.stake) <= 0 || !row.bet) {
                        return;
                    }
                    var result = 'loss';
                    if (Number(row.bet_profit) >= 0) {
                        result = 'win';
                    }
                    ledgerRows.push({
                        advice: betLabel({ market: row.bet.market || 'moneyline', selection: row.bet.selection }),
                        fight: row.fighter_a + ' vs ' + row.fighter_b,
                        odds: row.bet.odds,
                        stake: row.stake,
                        result: result,
                        profit: row.bet_profit,
                        actual: row.actual_winner + ' · ' + row.actual_method,
                        reason: row.reason || ''
                    });
                });
            }
            var table = '<p class="history-empty">Ta teden dnevnik prazen — Jev ni zaklenil stave.</p>';
            if (ledgerRows.length) {
                table = '<div class="table-wrap history-bets"><table><thead><tr><th>Jev priporočilo</th><th>Borba</th><th>Kvota</th><th>Vložek</th><th>Izid</th><th>P/L</th></tr></thead><tbody>';
                ledgerRows.forEach(function (bet) {
                    var plClass = 'loss';
                    if (Number(bet.profit) >= 0) {
                        plClass = 'win';
                    }
                    var prefix = '';
                    if (Number(bet.profit) >= 0) {
                        prefix = '+';
                    }
                    var izid = 'PADLA';
                    if (bet.result === 'win') {
                        izid = 'ZADETA';
                    }
                    table += '<tr><td><b>' + esc(bet.advice) + '</b></td><td>' + esc(bet.fight) + '<br><small>' + esc(bet.actual || '') + '</small></td><td>' + Number(bet.odds).toFixed(2) + '</td><td>' + euro(bet.stake) + '</td><td>' + izid + '</td><td class="' + plClass + '">' + prefix + euro(bet.profit) + '</td></tr>';
                });
                table += '</tbody></table></div>';
            }
            var skippedNote = '';
            if (Number(event.skipped) > 0) {
                skippedNote = '<p class="wager-warning">' + event.skipped + ' borb ni šlo v dnevnik (premalo edge/confidence). Niso stave.</p>';
            }
            var eventPrefix = '';
            if (Number(event.profit) >= 0) {
                eventPrefix = '+';
            }
            var eventClass = 'wrong';
            if (Number(event.profit) >= 0) {
                eventClass = 'correct';
            }
            return '<details class="backtest-event"><summary><span><b>' + new Date(event.event_date).toLocaleDateString('sl-SI') + '</b><small>dnevnik · ' + esc(event.location) + '</small></span><span>v dnevniku <b>' + event.bets + '</b></span><span>vložek <b>' + euro(event.stake) + '</b></span><span>P/L <b class="' + eventClass + '">' + eventPrefix + euro(event.profit) + '</b></span></summary><div class="history-content">' + table + skippedNote + '</div></details>';
        }).join('');
        var profitPrefix = '';
        if (Number(test.profit) >= 0) {
            profitPrefix = '+';
        }
        var favPrefix = '';
        if (test.favorite && Number(test.favorite.profit) >= 0) {
            favPrefix = '+';
        }
        var favProfit = 0;
        var favStake = 0;
        if (test.favorite) {
            favProfit = test.favorite.profit;
            favStake = test.favorite.total_stake;
        }
        var compareHtml = '';
        if (winnerOnly) {
            var winnerPrefix = '';
            if (Number(winnerOnly.profit) >= 0) {
                winnerPrefix = '+';
            }
            var methodPrefix = '';
            if (Number(test.profit) >= 0) {
                methodPrefix = '+';
            }
            var lockedHtml = '';
            if (locked) {
                var lockedPrefix = '';
                if (Number(locked.profit) >= 0) {
                    lockedPrefix = '+';
                }
                lockedHtml = '<article><small>SHRANJEN: SAMO ZMAGOVALEC</small><b>' + lockedPrefix + euro(locked.profit) + '</b><span>' + euro(locked.total_stake) + ' vložka · ' + (locked.bet_count || '—') + ' stav · ni spremenjen</span></article>';
            }
            compareHtml = '<div class="strategy-compare">' + lockedHtml +
                '<article><small>ISTI PICKI: SAMO ZMAGOVALEC</small><b>' + winnerPrefix + euro(winnerOnly.profit) + '</b><span>' + euro(winnerOnly.total_stake) + ' vložka · ROI ' + pct(winnerOnly.roi || 0) + ' · ' + winnerOnly.bet_count + ' stav</span></article>' +
                '<article><small>JEV: ZMAGOVALEC ALI WINNING METHOD</small><b>' + methodPrefix + euro(test.profit) + '</b><span>' + euro(test.total_stake) + ' vložka · ROI ' + pct(jevRoi) + ' · method ' + (test.method_bet_count || 0) + ' / moneyline ' + (test.moneyline_bet_count || 0) + '</span></article></div>';
        } else {
            compareHtml = '<div class="strategy-compare two"><article><small>JEV: SINGLE BET</small><b>' + profitPrefix + euro(test.profit) + '</b><span>' + euro(test.total_stake) + ' vložka · ROI ' + pct(jevRoi) + ' · ' + test.skipped_count + ' preskočenih</span></article><article><small>BENCHMARK: VEDNO FAVORIT</small><b>' + favPrefix + euro(favProfit) + '</b><span>' + euro(favStake) + ' vložka · ROI ' + pct(favoriteRoi) + '</span></article></div>';
        }
        var policyNote = '<p class="legacy-note">Vsak datum = en tedenski Stavni dnevnik. Vrstice so samo zaklenjene stave, ne cel fight card.</p>';
        if (test.policy) {
            policyNote = '<p class="legacy-note">Isto pravilo kot gumb Napolni dnevnik: method samo če je Jev dovolj ziher na KO/Sub/Dec, sicer moneyline. Odpri datum — to so vrstice dnevnika.</p>';
        }
        byId('backtestReport').innerHTML = reportSummary([['Dogodki', test.event_count], ['Vrstice v dnevnikih', test.bet_count], ['Borbe na cardih', test.total_fights], ['Preskočene (niso v dnevniku)', test.skipped_count], ['Profit dnevnikov', profitPrefix + euro(test.profit)], ['ROI', pct(jevRoi)], ['Brier', Number(test.brier).toFixed(3)]]) +
            compareHtml + policyNote +
            reportSummary([['Končni bankroll', euro(test.risk.ending_bankroll)], ['Max. drawdown', euro(test.risk.max_drawdown) + ' · ' + pct(test.risk.max_drawdown_pct)], ['Najdaljša serija porazov', test.risk.max_loss_streak]]) + '<div class="backtest-events">' + events + '</div>';
        if (Number(test.version || 1) >= 7) {
            button.textContent = 'Single + winning method backtest je zaklenjen';
        } else {
            button.textContent = 'Single bet backtest je zaklenjen';
        }
        button.disabled = true;
        button.classList.add('locked');
    }

    function renderTicketBacktest(test) {
        var button = byId('backtestButton');
        if (!test) {
            byId('backtestReport').innerHTML = '';
            button.textContent = 'Zaženi E-Stave listek test';
            button.disabled = false;
            button.classList.remove('locked');
            return;
        }
        if (Number(test.version || 1) < 6) {
            var oldProfitPrefix = '';
            if (Number(test.profit) >= 0) {
                oldProfitPrefix = '+';
            }
            var oldRoi = 0;
            if (Number(test.total_stake)) {
                oldRoi = Number(test.profit) / Number(test.total_stake);
            }
            byId('backtestReport').innerHTML = '<p class="legacy-note">Shranjen je stari test s posameznimi stavami. Novi test vzame iste Jevove picke in jih zloži v E-Stave kombinacijo (2 nogi, en listek).</p>' + reportSummary([['Stari singles test', test.total_fights + ' borb'], ['Profit', oldProfitPrefix + euro(test.profit)], ['ROI', pct(oldRoi)]]);
            button.textContent = 'Zaženi E-Stave listek backtest';
            button.disabled = false;
            button.classList.remove('locked');
            return;
        }
        var jevRoi = 0;
        if (Number(test.total_stake)) jevRoi = Number(test.profit) / Number(test.total_stake);
        var favoriteRoi = 0;
        if (Number(test.favorite.total_stake)) favoriteRoi = Number(test.favorite.profit) / Number(test.favorite.total_stake);
        var singles = test.singles_counterfactual || { total_stake: 0, profit: 0 };
        var singlesRoi = 0;
        if (Number(singles.total_stake)) singlesRoi = Number(singles.profit) / Number(singles.total_stake);
        var events = (test.event_summaries || []).map(function (event) {
            var ticketHtml = '<div class="backtest-ticket"><em>Ni listka — manj kot 2 kandidata ali negativen EV.</em></div>';
            if (event.ticket) {
                var ticket = event.ticket;
                var legs = '';
                ticket.legs.forEach(function (leg) {
                    var hitClass = 'wrong';
                    var hitLabel = 'MISS';
                    if (leg.hit) {
                        hitClass = 'correct';
                        hitLabel = 'HIT';
                    }
                    legs += '<div class="estave-leg"><div><b>' + esc(leg.selection) + '</b><small>' + esc(leg.fighter_a + ' vs ' + leg.fighter_b) + '</small></div><span>kvota <b>' + Number(leg.odds).toFixed(2) + '</b></span><span>Jev <b>' + pct(leg.p) + '</b></span><strong class="' + hitClass + '">' + hitLabel + '</strong></div>';
                });
                var wonClass = 'wrong';
                var wonLabel = 'LISTEK PADL';
                if (ticket.won) {
                    wonClass = 'correct';
                    wonLabel = 'LISTEK ZADET';
                }
                var profitPrefix = '';
                if (Number(ticket.profit) >= 0) profitPrefix = '+';
                var kind = 'kombinacija';
                if (ticket.type === 'sistem') kind = 'sistem 2/3';
                ticketHtml = '<div class="backtest-ticket"><div><b>' + esc(ticket.label) + '</b> · ' + kind + ' · ' + ticket.leg_count + ' noge · kvota <b>' + Number(ticket.combined_odds).toFixed(2) + '</b> · Jev p ' + pct(ticket.combined_p) + '</div>' + legs + '<div class="estave-totals"><span><small>Vložek</small><b>' + euro(ticket.stake) + '</b></span><span><small>Možno izplačilo</small><b>' + euro(ticket.possible_payout) + '</b></span><span><small>Izid</small><b class="' + wonClass + '">' + wonLabel + '</b></span><span><small>P/L</small><b class="' + wonClass + '">' + profitPrefix + euro(ticket.profit) + '</b></span></div></div>';
            }
            var eventPrefix = '';
            if (Number(event.profit) >= 0) eventPrefix = '+';
            var eventClass = 'wrong';
            if (Number(event.profit) >= 0) eventClass = 'correct';
            return '<details class="backtest-event"><summary><span><b>' + new Date(event.event_date).toLocaleDateString('sl-SI') + '</b><small>' + esc(event.location) + '</small></span><span>listek <b>' + event.bets + '</b></span><span>vložek <b>' + euro(event.stake) + '</b></span><span>P/L <b class="' + eventClass + '">' + eventPrefix + euro(event.profit) + '</b></span></summary>' + ticketHtml + '</details>';
        }).join('');
        var profitPrefix = '';
        if (Number(test.profit) >= 0) profitPrefix = '+';
        var singlesPrefix = '';
        if (Number(singles.profit) >= 0) singlesPrefix = '+';
        var favPrefix = '';
        if (Number(test.favorite.profit) >= 0) favPrefix = '+';
        var ticketRecord = (test.ticket_wins || 0) + '/' + test.bet_count;
        byId('backtestReport').innerHTML = reportSummary([['Dogodki', test.event_count], ['Vse borbe', test.total_fights], ['Listki', test.bet_count], ['Zadeti listki', ticketRecord], ['Pravilni zmagovalci', test.correct_winner + '/' + test.total_fights], ['Profit', profitPrefix + euro(test.profit)], ['ROI', pct(jevRoi)], ['Brier', Number(test.brier).toFixed(3)]]) +
            '<div class="strategy-compare"><article><small>JEV: E-STAVE LISTEK</small><b>' + profitPrefix + euro(test.profit) + '</b><span>' + euro(test.total_stake) + ' vložka · ROI ' + pct(jevRoi) + ' · ' + test.bet_count + ' listkov</span></article><article><small>ISTI PICKI: STARI SINGLES</small><b>' + singlesPrefix + euro(singles.profit) + '</b><span>' + euro(singles.total_stake) + ' vložka · ROI ' + pct(singlesRoi) + '</span></article><article><small>BENCHMARK: VEDNO FAVORIT</small><b>' + favPrefix + euro(test.favorite.profit) + '</b><span>' + euro(test.favorite.total_stake) + ' vložka · ROI ' + pct(favoriteRoi) + '</span></article></div>' +
            reportSummary([['Končni bankroll', euro(test.risk.ending_bankroll)], ['Max. drawdown', euro(test.risk.max_drawdown) + ' · ' + pct(test.risk.max_drawdown_pct)], ['Najdaljša serija padlih listkov', test.risk.max_loss_streak]]) + '<div class="backtest-events">' + events + '</div>';
        button.textContent = 'E-Stave listek backtest je zaklenjen';
        button.disabled = true;
        button.classList.add('locked');
    }

    function renderHistory(events) {
        if (!events.length) { byId('historyList').innerHTML = '<div class="history-empty">Zgodovina je trenutno prazna. Končani dogodki se bodo samodejno pojavili tukaj.</div>'; return; }
        var html = '';
        html += events.map(function (event) {
            var bets = modeBets(event.bets || []);
            var jevProfit = bets.filter(function (b) { return b.owner === 'jev'; }).reduce(function (sum, b) { return sum + Number(b.profit); }, 0);
            var meProfit = bets.filter(function (b) { return b.owner === 'me'; }).reduce(function (sum, b) { return sum + Number(b.profit); }, 0);
            var fights = (event.fights || []).map(function (fight) { return '<div class="history-fight"><span><b>' + esc(fight.fighter_a) + '</b> vs ' + esc(fight.fighter_b) + '</span><span>Jev: ' + esc(fight.winner_pick || 'brez napovedi') + '</span><span>Rezultat: ' + esc(fight.winner || 'še ni vpisan') + '</span></div>'; }).join('');
            var betRows = bets.length ? '<div class="table-wrap history-bets"><table><thead><tr><th>Igralec</th><th>Stava</th><th>Kvota</th><th>Vložek</th><th>P/L</th></tr></thead><tbody>' + bets.map(function (bet) {
                var who = 'Kristjan';
                if (bet.owner === 'jev') who = 'Jev';
                var plClass = 'loss';
                if (Number(bet.profit) >= 0) plClass = 'win';
                return '<tr><td>' + who + '</td><td>' + esc(betLabel(bet)) + '</td><td>' + Number(bet.odds).toFixed(2) + '</td><td>' + euro(bet.stake) + '</td><td class="' + plClass + '">' + euro(bet.profit) + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<p class="history-empty">Na tem dogodku ni evidentiranih stav.</p>';
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
        if (name === 'bet') {
            var html = '<option value="' + esc(fight.fighter_a) + '">' + esc(fight.fighter_a) + ' zmaga (moneyline)</option><option value="' + esc(fight.fighter_b) + '">' + esc(fight.fighter_b) + ' zmaga (moneyline)</option>';
            if (state.mode === 'single') {
                (fight.method_markets || []).forEach(function (market) {
                    html += '<option value="' + esc(market.selection) + '" data-odds="' + Number(market.odds).toFixed(2) + '">' + esc(market.selection) + '</option>';
                });
            }
            select.innerHTML = html;
            if (fight.odds_a) {
                byId('betOdds').value = Number(fight.odds_a).toFixed(2);
            } else {
                byId('betOdds').value = '';
            }
        } else {
            select.innerHTML = '<option>' + esc(fight.fighter_a) + '</option><option>' + esc(fight.fighter_b) + '</option>';
        }
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
    byId('modeSingle').addEventListener('click', function () { setMode('single'); });
    byId('modeTicket').addEventListener('click', function () { setMode('ticket'); });
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
            button.textContent = 'Sestavljam E-Stave listek …';
            if (state.mode === 'single') {
                button.textContent = 'Razporejam single stave …';
            }
            var result = await request('finalize_portfolio', 'POST', { event_id: state.eventId, mode: state.mode });
            if (state.mode === 'single') {
                if (result.portfolio.total_stake > 0) {
                    toast('Single stave so razporejene: ' + euro(result.portfolio.total_stake) + ' · ' + result.portfolio.bets.length + ' stav.');
                } else {
                    toast('Ni stav — ni kandidatov z dovolj velikim edgem.');
                }
            } else {
                if (result.portfolio.total_stake > 0) {
                    toast('E-Stave listek je pripravljen: ' + euro(result.portfolio.total_stake) + ' · kvota ' + Number(result.portfolio.ticket.combined_odds).toFixed(2) + '.');
                } else {
                    toast('Ni listka — treba sta vsaj 2 kandidata z edgem.');
                }
            }
            load();
        } catch (error) { toast(error.message, true); }
        button.disabled = false;
        if (state.mode === 'single') {
            button.textContent = 'Analiziraj cel card + razporedi stave';
        } else {
            button.textContent = 'Analiziraj cel card + sestavi listek';
        }
    });
    byId('completeEventButton').addEventListener('click', function () { var button = this; button.disabled = true; button.textContent = 'Berem dejanske rezultate …'; request('complete_event', 'POST', { event_id: state.eventId }).then(function () { toast('Dogodek je poravnan in Jev ocenjen.'); load(); }).catch(function (error) { toast(error.message, true); }).finally(function () { button.disabled = false; button.textContent = 'Dogodek je končan — preveri Jeva'; }); });
    byId('backtestButton').addEventListener('click', async function () {
        var button = this, progress = byId('backtestProgress'), completed = false;
        button.disabled = true; button.classList.add('loading'); progress.hidden = false;
        try {
            while (!completed) {
                var result = await request('run_backtest', 'POST', { mode: state.mode });
                completed = !!result.complete;
                if (completed) {
                    if (state.mode === 'single') {
                        state.data.backtest_single_method = result.backtest;
                    } else {
                        state.data.backtest_ticket = result.backtest;
                        state.data.backtest = result.backtest;
                    }
                    renderBacktest(result.backtest);
                    progress.querySelector('span').textContent = '20/20 dogodkov';
                    progress.querySelector('i').style.width = '100%';
                    if (state.mode === 'single') {
                        toast('Single + winning method backtest je primerjan z winner-only.');
                    } else {
                        toast('E-Stave listek backtest je zaključen in zaklenjen.');
                    }
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
            if (!completed) {
                button.disabled = false;
                button.textContent = 'Nadaljuj 20-eventni test';
            }
            if (completed) {
                setTimeout(function () { progress.hidden = true; }, 1200);
            }
        }
    });
    byId('betSelection').addEventListener('change', function () {
        var fight = fightById(byId('betFightId').value);
        var selected = this.options[this.selectedIndex];
        if (selected && selected.getAttribute('data-odds')) {
            byId('betOdds').value = selected.getAttribute('data-odds');
            return;
        }
        if (this.value === fight.fighter_a) {
            byId('betOdds').value = fight.odds_a || '';
        } else {
            byId('betOdds').value = fight.odds_b || '';
        }
    });
    byId('saveSettings').addEventListener('click', function () { var key = byId('apiKeyInput').value.trim(), oddsKey = byId('oddsKeyInput').value.trim(); if (key) sessionStorage.setItem('typesafeKey', key); if (oddsKey) sessionStorage.setItem('oddsKey', oddsKey); request('settings', 'POST', { kelly_fraction: byId('kellyInput').value, max_bet_fraction: byId('maxBetInput').value / 100, max_event_fraction: byId('maxEventInput').value / 100, min_edge: byId('minEdgeInput').value / 100 }).then(function () { byId('settingsModal').close(); toast('Strategija shranjena.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveBet').addEventListener('click', function () { request('my_bet', 'POST', { fight_id: byId('betFightId').value, selection: byId('betSelection').value, odds: byId('betOdds').value, stake: byId('betStake').value }).then(function () { byId('betModal').close(); toast('Tvoja stava je zaklenjena.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveResult').addEventListener('click', function () { request('settle_fight', 'POST', { fight_id: byId('settleFightId').value, winner: byId('settleWinner').value, method: byId('settleMethod').value, round: byId('settleRound').value }).then(function () { byId('settleModal').close(); toast('Rezultat poravnan.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveEvent').addEventListener('click', function () { request('create_event', 'POST', { name: byId('newEventName').value, event_date: byId('newEventDate').value, venue: byId('newEventVenue').value, source_url: byId('newEventUrl').value }).then(function (response) { state.eventId = response.event_id; byId('eventModal').close(); toast('Dogodek ustvarjen.'); load(); }).catch(function (error) { toast(error.message, true); }); });
    byId('saveFight').addEventListener('click', function () { request('add_fight', 'POST', { event_id: state.eventId, fighter_a: byId('newFighterA').value, fighter_b: byId('newFighterB').value, weight_class: byId('newWeightClass').value, card_section: byId('newCardSection').value, odds_a: byId('newOddsA').value, odds_b: byId('newOddsB').value }).then(function () { byId('fightModal').close(); toast('Borba dodana.'); load(); }).catch(function (error) { toast(error.message, true); }); });

    applyModeCopy();
    load();
}());
