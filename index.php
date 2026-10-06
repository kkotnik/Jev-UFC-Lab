<?php
declare(strict_types=1);
require_once __DIR__ . '/src/bootstrap.php';
app_db();
?>
<!doctype html>
<html lang="sl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>JEV // UFC Profit Lab</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="#"><span class="brand-mark">J</span><span>JEV <em>// UFC PROFIT LAB</em></span></a>
    <div class="top-actions"><span id="apiBadge" class="status"><i></i> API status</span><button class="button ghost" id="oddsButton">Osveži kvote</button><button class="button ghost" data-open="settingsModal">Nastavitve</button><button class="button primary" id="syncButton">Osveži zgodovino</button></div>
</header>

<main class="shell">
    <nav class="mode-tabs" aria-label="Program"><button type="button" id="modeSingle">Single bet</button><button type="button" id="modeTicket" class="active">2/3 listek</button></nav>
    <nav class="view-tabs" aria-label="Glavni pogledi"><button class="active" id="currentTab">Aktualno</button><button id="historyTab">Zgodovina <span id="historyBadge">0</span></button><button id="backtestTab">Backtest <span id="backtestBadge">—</span></button></nav>

    <section id="currentView" class="view-panel">
        <section class="hero">
            <div><p class="eyebrow">VIRTUALNI HEAD-TO-HEAD EKSPERIMENT</p><h1>Človek proti <span>stroju.</span><br>Kdo obrne €500 v več?</h1><p class="lede" id="ledeText">Jev sestavi en E-Stave listek na dogodek: sistem 2/3 pri treh kandidatih, kombinacija pri dveh. Pove, koliko vplačati. Ena noga na sistemu sme pasti.</p></div>
            <div class="event-picker"><label for="eventSelect">Aktivni dogodek</label><select id="eventSelect"></select><button class="text-button" data-open="eventModal">+ Nov tedenski dogodek</button></div>
        </section>
        <section class="scoreboard">
            <article class="score-card jev"><div class="score-head"><span class="avatar">J</span><div><strong>JEV AI</strong><small id="jevModeLabel">E-STAVE LISTEK</small></div><span class="rank">MODEL</span></div><div class="bankroll"><small>BANKROLL</small><strong id="jevBankroll">€500.00</strong><span id="jevProfit">€0.00</span></div><div class="score-stats"><span><small>ROI</small><b id="jevRoi">0%</b></span><span><small>W–L</small><b id="jevRecord">0–0</b></span><span><small>PROSTO</small><b id="jevAvailable">€500</b></span></div></article>
            <div class="versus"><span>VS</span><i></i></div>
            <article class="score-card human"><div class="score-head"><span class="avatar">K</span><div><strong>KRISTJAN</strong><small>HUMAN READS</small></div><span class="rank">TI</span></div><div class="bankroll"><small>BANKROLL</small><strong id="meBankroll">€500.00</strong><span id="meProfit">€0.00</span></div><div class="score-stats"><span><small>ROI</small><b id="meRoi">0%</b></span><span><small>W–L</small><b id="meRecord">0–0</b></span><span><small>PROSTO</small><b id="meAvailable">€500</b></span></div></article>
        </section>
        <section class="data-strip"><span><i></i> <b id="historyCount">0</b> zgodovinskih borb</span><span>Model: <b>jev-latest</b></span><span>Brier score: <b id="brierScore">še brez rezultatov</b></span><span class="lock">NAPOVEDI SO ZAKLENJENE PO ANALIZI</span></section>
        <section class="bet-guide" id="betGuide"><strong id="betGuideTitle">E-Stave: en listek na dogodek</strong><span id="betGuideA"><b>3 borci = Sistem 2/3.</b> Iskalec stav → trije borci z listka → kvota Zmagovalec → tip Sistem → 2 iz 3. Vplačilo na kombinacijo = znesek s kartice (× 3 kombinaciji = skupaj).</span><span id="betGuideB"><b>2 borca = Kombinacija.</b> Obe nogi morata zmagati. Če je samo 1 kandidat, listka ni. Pred oddajo preveri vsako kvoto.</span></section>
        <section class="section-head"><div><p class="eyebrow">CELOTEN FIGHT CARD</p><h2 id="eventTitle">Nalaganje dogodka …</h2><p id="eventMeta"></p></div><div class="section-actions"><button class="button ghost" data-open="fightModal">+ Dodaj borbo</button><button class="button ghost" id="prefightButton">Osveži pre-fight podatke</button><button class="button primary" id="predictAllButton">Analiziraj cel card + sestavi listek</button></div></section>
        <div id="fightList" class="fight-list"><div class="empty">Nalaganje …</div></div>
        <section class="ticket-section" id="estaveTicketsWrap" hidden>
            <div class="section-head compact"><div><p class="eyebrow">E-STAVE</p><h2>Listki za oddajo</h2></div></div>
            <p class="wager-warning">En listek na dogodek. Sistem 2/3: vplačilo na kombinacijo × 3. Kombinacija: eno vplačilo. Minimalno 0,50 €. Maksimalno 250 € na listek. Dobitek nad 300 €: 15 % davek. 18+.</p>
            <div id="estaveTickets" class="ticket-list"></div>
        </section>
        <section class="ledger-section"><div class="section-head compact"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Stavni dnevnik</h2></div></div><p class="wager-warning" id="ledgerHint">Virtualni model ni zagotovilo dobička. Povezava odpre E-Stave, stave pa ne odda. Pred vplačilom preveri vsako kvoto na listku; samo 18+.</p><div class="table-wrap"><table><thead><tr><th>Igralec</th><th>Izbor</th><th>Kvota</th><th>Vložek</th><th>Status</th><th>P/L</th><th></th></tr></thead><tbody id="betLedger"></tbody></table></div></section>
        <section class="review-grid"><article class="review-card"><p class="eyebrow">PO DOGODKU</p><h2>Preveri dejanske rezultate</h2><p>Osveži vir rezultatov, poravnaj vse stave in izračunaj Jevov accuracy, method accuracy, ROI ter Brier score.</p><button class="button primary" id="completeEventButton">Dogodek je končan — preveri Jeva</button><div id="eventReport" class="report-box"></div></article></section>
    </section>

    <section id="historyView" class="view-panel" hidden>
        <header class="page-intro"><p class="eyebrow">ARHIV</p><h1>Zgodovina dogodkov</h1><p id="historyIntro">Na enem mestu so zaklenjene napovedi, tvoje in Jevove stave, dejanski rezultati ter končni P/L.</p></header>
        <div id="historyList"></div>
    </section>

    <section id="backtestView" class="view-panel" hidden>
        <header class="page-intro"><p class="eyebrow">MODEL LAB</p><h1>Walk-forward backtest</h1><p id="backtestIntro">Isti Jevovi picki kot prej. Vložek je E-Stave listek: sistem 2/3 (3 noge, ena sme pasti) ali kombinacija (2 nogi, obe morata zadeniti). 15 % davek na dobitek nad 300 € je vključen. Kvote so zgodovinske tržne, ne E-Stave.</p></header>
        <article class="review-card backtest-card"><div class="backtest-title"><div><p class="eyebrow">20 PRETEKLIH DOGODKOV</p><h2 id="backtestH2">E-Stave listek</h2></div><div class="backtest-rules" id="backtestRules"><span>sistem 2/3</span><span>kombinacija 2</span><span>edge ≥ 5 točk</span><span>confidence ≥ 55 %</span><span>½ Kelly</span></div></div><p id="backtestBlurb">Če ni vsaj dveh kandidatov, dogodek nima listka. Primerjava s starimi posameznimi stavami je na istem Jevovem researchu.</p><button class="button human-button" id="backtestButton">Zaženi E-Stave listek test</button><div id="backtestProgress" class="backtest-progress" hidden><span></span><i></i></div><div id="backtestReport" class="report-box"></div></article>
    </section>
</main>

<div id="toast" class="toast" role="status"></div>

<dialog id="settingsModal"><form method="dialog" class="modal-card"><button class="close" value="cancel">×</button><p class="eyebrow">MODEL & BANKROLL</p><h3>Strategija Jeva</h3><label>Jev API ključ (samo ta zavihek)<input type="password" id="apiKeyInput" autocomplete="off" placeholder="Če ni nastavljen v start.bat"></label><label>The Odds API ključ (neobvezno, avtomatske kvote)<input type="password" id="oddsKeyInput" autocomplete="off" placeholder="the-odds-api.com"></label><div class="grid-2"><label>Kelly delež<input type="number" id="kellyInput" min="0.05" max="1" step="0.05"></label><label>Max. stava (% bankrolla)<input type="number" id="maxBetInput" min="1" max="30" step="1"></label><label>Max. dogodek (%)<input type="number" id="maxEventInput" min="5" max="80" step="1"></label><label>Minimalni edge (%)<input type="number" id="minEdgeInput" min="0" max="25" step="0.5"></label></div><p class="hint">Ključa ostaneta samo v sessionStorage brskalnika in izgineta, ko zapreš zavihek.</p><button type="button" class="button primary wide" id="saveSettings">Shrani</button></form></dialog>
<dialog id="betModal"><form method="dialog" class="modal-card"><button class="close" value="cancel">×</button><p class="eyebrow">TVOJA POTEZA</p><h3>Dodaj virtualno stavo</h3><input type="hidden" id="betFightId"><label>Izbor<select id="betSelection"></select></label><div class="grid-2"><label>Decimalna kvota<input type="number" id="betOdds" min="1.01" step="0.01"></label><label>Vložek €<input type="number" id="betStake" min="0.01" step="0.01"></label></div><button type="button" class="button human-button wide" id="saveBet">Zakleni mojo stavo</button></form></dialog>
<dialog id="settleModal"><form method="dialog" class="modal-card"><button class="close" value="cancel">×</button><p class="eyebrow">REZULTAT</p><h3>Poravnaj borbo</h3><input type="hidden" id="settleFightId"><label>Zmagovalec<select id="settleWinner"></select></label><div class="grid-2"><label>Način<select id="settleMethod"><option>KO/TKO</option><option>Submission</option><option>Decision</option><option>DQ</option></select></label><label>Runda<input type="number" id="settleRound" min="1" max="5" value="1"></label></div><button type="button" class="button primary wide" id="saveResult">Potrdi rezultat</button></form></dialog>
<dialog id="eventModal"><form method="dialog" class="modal-card"><button class="close" value="cancel">×</button><p class="eyebrow">NOV TEDEN</p><h3>Dodaj dogodek</h3><label>Ime<input id="newEventName" placeholder="UFC Fight Night: ..."></label><label>Datum in čas<input type="datetime-local" id="newEventDate"></label><label>Lokacija<input id="newEventVenue" placeholder="Las Vegas, NV"></label><label>UFC povezava<input type="url" id="newEventUrl" placeholder="https://www.ufc.com/event/..."></label><button type="button" class="button primary wide" id="saveEvent">Ustvari dogodek</button></form></dialog>
<dialog id="fightModal"><form method="dialog" class="modal-card"><button class="close" value="cancel">×</button><p class="eyebrow">FIGHT CARD</p><h3>Dodaj borbo</h3><div class="grid-2"><label>Borec A<input id="newFighterA"></label><label>Borec B<input id="newFighterB"></label><label>Kategorija<input id="newWeightClass" placeholder="Lightweight"></label><label>Del carda<select id="newCardSection"><option>Main</option><option>Prelims</option><option>Early Prelims</option></select></label><label>Kvota A<input type="number" id="newOddsA" min="1.01" step="0.01"></label><label>Kvota B<input type="number" id="newOddsB" min="1.01" step="0.01"></label></div><button type="button" class="button primary wide" id="saveFight">Dodaj na card</button></form></dialog>

<script src="assets/app.js"></script>
</body>
</html>
