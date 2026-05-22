<?php

declare(strict_types=1);

/**
 * CLI: Parser — warstwy walidacji (struktura vs procurement gate).
 * php scripts/test_ksef_parser_quality.php
 */

require_once __DIR__ . '/../core/Ksef/Parser.php';
require_once __DIR__ . '/../core/Ksef/Client.php';

$parser = new \SliceHub\Ksef\Parser();
$ok = 0;
$fail = 0;

$assert = static function (bool $cond, string $label) use (&$ok, &$fail): void {
    if ($cond) {
        echo "  OK  {$label}\n";
        $ok++;
    } else {
        echo "  FAIL {$label}\n";
        $fail++;
    }
};

$faHeader = static function (string $body, string $rodzaj = 'VAT'): string {
    return '<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns="http://crd.gov.pl/wzor/2023/06/29/12648/">
  <Podmiot1><DaneIdentyfikacyjne><NIP>5260001246</NIP><Nazwa>Dostawca</Nazwa></DaneIdentyfikacyjne></Podmiot1>
  <Podmiot2><DaneIdentyfikacyjne><NIP>1234567890</NIP><Nazwa>Nabywca</Nazwa></DaneIdentyfikacyjne></Podmiot2>
  <Fa>
    <P_2>FV/TEST/1</P_2>
    <P_1>2026-05-01</P_1>
    <RodzajFaktury>' . $rodzaj . '</RodzajFaktury>
    ' . $body . '
  </Fa>
</Faktura>';
};

echo "=== Parser warstwy ===\n";

// 1) Tylko numer — parse OK (minimalna tożsamość), assess reject (brak linii/kwot)
$xml1 = $faHeader('<P_15>0</P_15>');
$r1 = $parser->parse($xml1);
$assert($r1['success'], 'parse: numer bez linii → success');
$q1 = $parser->assessProcurementQuality($r1);
$assert(!($q1['procurement_ok'] ?? true), 'assess: bez linii → reject');

// 2) Linia z kwotą — parse + assess OK
$xml2 = $faHeader('
    <P_13_1>100.00</P_13_1><P_14_1>23.00</P_14_1><P_15>123.00</P_15>
    <FaWiersz>
      <NrWierszaFa>1</NrWierszaFa><P_7>Mąka</P_7><P_8A>kg</P_8A><P_8B>1</P_8B>
      <P_9A>100</P_9A><P_11>100</P_11><P_12>23</P_12>
    </FaWiersz>');
$r2 = $parser->parse($xml2);
$q2 = $parser->assessProcurementQuality($r2);
$assert($r2['success'] && ($q2['procurement_ok'] ?? false), 'parse+assess: normalna FV');

// 3) KOR bez linii — assess reject
$xml3 = $faHeader('<P_15>50</P_15>', 'KOR');
$r3 = $parser->parse($xml3);
$q3 = $parser->assessProcurementQuality($r3);
$assert($r3['success'] && !($q3['procurement_ok'] ?? true), 'assess: KOR bez linii → reject');

// 3b) KOR z liniami — draft/warn (flow korekty)
$xml3b = $faHeader('
    <P_13_1>-20.00</P_13_1><P_15>-24.60</P_15>
    <FaWiersz>
      <NrWierszaFa>1</NrWierszaFa><P_7>Bazylia korekta</P_7><P_8A>szt</P_8A><P_8B>-1</P_8B>
      <P_9A>20</P_9A><P_11>-20</P_11><P_12>23</P_12>
    </FaWiersz>', 'KOR');
$r3b = $parser->parse($xml3b);
$q3b = $parser->assessProcurementQuality($r3b);
$assert(($r3b['success'] ?? false) && ($q3b['procurement_ok'] ?? false) && ($q3b['level'] ?? '') === 'warn',
    'assess: KOR z liniami → warn OK (nie error)');

// 4) enrich z linii gdy P_15=0
$xml4 = $faHeader('
    <FaWiersz>
      <NrWierszaFa>1</NrWierszaFa><P_7>Ser</P_7><P_8A>szt</P_8A><P_8B>2</P_8B>
      <P_9A>10</P_9A><P_11>20</P_11><P_12>23</P_12>
    </FaWiersz>');
$r4 = $parser->parse($xml4);
$assert(($r4['totals']['total_gross_minor'] ?? 0) > 0, 'enrich: suma z FaWiersz gdy brak P_15');

// 5) Client transport vs Parser
$transportErr = \SliceHub\Ksef\Client::validateInvoiceXmlBody('{"error":true}');
$assert($transportErr !== null, 'Client: JSON odrzucone na transporcie');
$structErr = $parser->parse('<root/>');
$assert(!($structErr['success'] ?? true), 'Parser: zły root → fail (nie Client)');

echo "\n=== Podsumowanie: {$ok} OK, {$fail} FAIL ===\n";
exit($fail > 0 ? 1 : 0);
