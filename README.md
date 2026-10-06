# JEV // UFC Profit Lab

Lokalni eksperiment za primerjavo virtualnega UFC betting bankrolla **Jev AI proti Kristjanu**. Začetni bankroll obeh je 500 €. Projekt ni povezan s pravo stavnico in ne oddaja pravih stav.

## Hiter zagon

1. Dvoklikni `start.bat`.
2. Jev ključ se samodejno prebere iz lokalne datoteke `.env`.
3. Klikni **Osveži zgodovino**, da aplikacija uvozi javni UFCStats CSV posnetek (trenutno 8.794 borb).
4. Preveri oziroma dopolni decimalne kvote. Če imaš ključ za The Odds API, ga vnesi v Nastavitvah in uporabi **Osveži kvote**; sicer kvote vpiši ročno.
5. Klikni **Analiziraj cel card + sestavi portfolio**. Gumb najprej samodejno osveži pre-fight podatke za izbrani aktualni dogodek, nato izdela oziroma posodobi napovedi in sestavi portfolio. Vsaka izdelana napoved in morebitna virtualna stava se verzionira in zaklene.

Aplikacija se odpre na [http://127.0.0.1:8787](http://127.0.0.1:8787). Strežnik ustaviš s `Ctrl+C` v konzoli.

## Kako deluje napoved

- Zgodovina se bere iz javnega UFCStats seznama zaključenih borb.
- Jevu se pošljejo samo borbe pred datumom napovedovanega dogodka; tako ni uhajanja prihodnjih rezultatov.
- Vsaka borba uporablja ločene TypeSafe primitive za zmagovalca, kombinacijo zmagovalec/način, verjetnost distance, volatilnost in kakovost podatkov.
- Jev vrne verjetnosti in confidence. Koda nato neodvisno izračuna breakeven verjetnost kvote, edge in velikost stave.
- Strategija uporablja confidence-weighted 50 % Kelly, najmanj 5 odstotnih točk edga, najmanj 55 % confidence ter nastavljive omejitve tveganja.
- Obveznega vložka ni. Slabši signali ostanejo brez stave, boljši pa dobijo večji vložek. Posamezna stava je omejena na 15 % bankrolla, cel dogodek pa na 35 %.
- Moneyline pomeni zmago izbranega borca na kakršen koli način. Kvota 1,70 pri vložku 50 € pomeni 85 € skupnega izplačila oziroma 35 € čistega dobička.
- Edge je razlika v odstotnih točkah med Jevovo verjetnostjo in breakeven verjetnostjo kvote. Primer: 85,1 % − 58,8 % = 26,3 odstotne točke.

## Tedenski postopek

Po dogodku pri vsaki borbi izberi **Rezultat**. Aplikacija poravna obe strani, preračuna bankroll, ROI, W–L in Brier score. Nato ustvari nov dogodek, dodaj card ter aktualne kvote. Pretekle napovedi ostanejo nespremenjene v `data/ufc_lab.sqlite`.

Gumb **Dogodek je končan — preveri Jeva** poskusi rezultate uvoziti sam, poravna stave in izdela poročilo. Ob zagonu se kot aktualen samodejno izbere najbližji prihodnji UFC dogodek. Pretekli dogodki in stave so v zavihku **Zgodovina**.

## Pre-fight enrichment

`php tools/enrich_prefight.php` osveži najbližji prihodnji dogodek; z ID-jem dogodka lahko zaženeš tudi `php tools/enrich_prefight.php 3`. Skripta združi lokalno UFC zgodovino in časovno pravilne pre-fight posnetke iz `data/ultimate_ufc_dataset.csv`. Jevu doda starost na dan borbe, višino, reach, stance, rekord, serije, striking/grappling povprečja, layoff, aktivnost v zadnjih 365/730 dneh, višino prizorišča in oceno kakovosti podatkov.

Ročni zagon je samo dodatna možnost: glavni gumb za analizo to skripto oziroma isto strežniško opravilo vedno požene sam za trenutno izbrani najbližji dogodek, še preden Jev dobi podatke.

Poškodbe, short-notice zamenjave, replacement opponent in zgrešena teža se brez preverljivega vira ne ugibajo in ostanejo `unknown`. Ponovna analiza po enrichmentu ustvari novo verzijo napovedi; prejšnja ostane shranjena kot `superseded`.

Pri odprtih Jevovih stavah je povezava na uradno stran E-Stave. Povezava ne odda stave in ne more zagotoviti prikazane kvote; izbor, kvoto in vložek je treba pred vplačilom preveriti ročno. Samo 18+ in odgovorno igranje.

Vgrajen je zaklenjen walk-forward backtest 20 celotnih dogodkov oziroma 253 borb, vključno s prelimsi. Jev pri napovedi ne vidi rezultata ali tržne kvote; kvota se uporabi šele po neodvisni oceni verjetnosti za izračun edga in stave. Ni obveznega vložka. Stava zahteva vsaj 5 odstotnih točk edga in 55 % confidence, velikost pa je confidence-weighted half Kelly z omejitvama 15 % bankrolla na stavo in 35 % na dogodek.

Trenutni zaklenjeni test: 170/253 pravilnih zmagovalcev, 97/253 pravilnih metod, 154 dejanskih stav in 99 preskočenih borb. Brier 0,239, +648,76 € na 4.799,67 € vložka (ROI 13,52 %). Slepa stava 10 € na vsakega favorita: +171,26 € na 2.530 € (ROI 6,77 %). Največji drawdown Jevove strategije je 77,12 € oziroma 12,23 %.

Za ročni uvoz iz terminala:

```powershell
php tools/sync.php 80
```

Za osnovni test:

```powershell
php tests/smoke.php
php -l api.php
```

## Podatki in omejitve

- Začetni card je uradni UFC Fight Night: Allen vs Duncan, 10. oktober 2026.
- Nekaj začetnih moneyline kvot je vpisanih iz javno objavljenega posnetka trga; manjkajoče so namenoma prazne, da aplikacija ne izmišljuje številk.
- Card in kvote se lahko spremenijo. Pred analizo jih vedno preveri in shrani.
- UFCStats trenutno zahteva brskalniški JavaScript, zato uvoznik samodejno uporabi javni CSV posnetek projekta `mma-ai`. Obstoječi podatki vedno ostanejo v lokalni SQLite bazi.
- Verjetnost ni zagotovilo. Majhen vzorec UFC dogodkov ima veliko variance, zato je dolgoročna kalibracija pomembnejša od enega zmagovalnega tedna.

## Varnost ključa

Jev API ključ je v lokalni datoteki `.env`, kot je bilo zahtevano. Če ga spremeniš, ponovno zaženi aplikacijo. Ključ za The Odds API lahko dodaš kot `THE_ODDS_API_KEY=...` v isto datoteko ali ga začasno vneseš v Nastavitvah.

## Viri

- Jev / TypeSafe API: <https://docs.typesafe.ai/introduction>
- UFCStats: <http://ufcstats.com/statistics/events/completed?page=all>
- Uradni card: <https://www.ufc.com/event/ufc-fight-night-october-10-2026>
- Rezervni UFCStats CSV: <https://github.com/DanMcInerney/mma-ai>
- Neobvezne aktualne kvote: <https://the-odds-api.com/sports-odds-data/mma-odds.html>
