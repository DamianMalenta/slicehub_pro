<?php

declare(strict_types=1);

/**
 * PayrollAllocator — rozbicie sesji pracy na okresy rozliczeniowe (HR-6).
 *
 * PROBLEM (HR-6, `_docs/18_BACKOFFICE_HR_LOGIC.md §1.2`):
 * zmiana 31.07 22:00 → 01.08 06:00 była w całości księgowana w lipcu (miesiąc
 * `start_time`). Sześć z ośmiu godzin przepracowano w sierpniu. Po
 * `payroll_close_period` na lipcu błąd stawał się nieodwracalny — ledger jest
 * append-only.
 *
 * ROZWIĄZANIE: sesja jest cięta na granicach miesięcy, a zarobek dzielony
 * proporcjonalnie do **sekund** przepracowanych w każdym okresie.
 *
 * ŚWIĘTOŚĆ PIENIĄDZA — dwie żelazne gwarancje:
 *
 *   1. **Zero groszowego wycieku.** `array_sum(allocate($total, $w)) === $total`
 *      dla dowolnego wejścia. Użyta jest metoda największych reszt (largest
 *      remainder), nie niezależne zaokrąglanie segmentów — to drugie potrafi
 *      zgubić lub wyprodukować grosz.
 *   2. **Zero floatów.** Cała arytmetyka na intach. Wagi to sekundy (int),
 *      kwoty to grosze (int).
 *
 * Kwota całkowita jest liczona DOKŁADNIE tak jak wcześniej (z `total_hours`
 * sesji), a dopiero potem dzielona. Dzięki temu sesja nieprzecinająca miesiąca
 * daje bit w bit ten sam wynik co przed wprowadzeniem tej klasy.
 *
 * Klasa jest CZYSTA (bez PDO, bez HTTP, bez `date_default_timezone` side-effectów)
 * — w całości testowalna z CLI. Świadomie NIE jest to funkcja SQL
 * (`fn_allocate_hours` z pierwotnego szkicu §11.5): logika pieniężna zostaje w
 * PHP, gdzie obowiązuje dyscyplina int-only i gdzie da się ją pokryć testami
 * bez bazy. MariaDB 10.11 w tym repo ma już problemy zgodnościowe z migracjami
 * 015/030/037 — dokładanie tam funkcji składowanej byłoby dodatkowym ryzykiem.
 */
final class PayrollAllocator
{
    /** Bezpiecznik pętli — sesja dłuższa niż to jest artefaktem, nie pracą. */
    private const MAX_SEGMENTS = 64;

    /**
     * Tnie przedział [start, end) na segmenty według miesięcy kalendarzowych.
     *
     * Zwraca listę w porządku chronologicznym. Dla sesji w obrębie jednego
     * miesiąca (przypadek dominujący) zwraca dokładnie jeden segment.
     *
     * @param string $startTime 'Y-m-d H:i:s'
     * @param string $endTime   'Y-m-d H:i:s'
     * @return list<array{year:int, month:int, seconds:int}>
     *
     * @throws \InvalidArgumentException gdy daty są nieparsowalne
     */
    public static function splitByPeriod(string $startTime, string $endTime): array
    {
        $tz    = new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($startTime), $tz);
        $end   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($endTime), $tz);

        if ($start === false || $end === false) {
            throw new \InvalidArgumentException('INVALID_DATETIME');
        }
        if ($end <= $start) {
            return [];
        }

        $segments = [];
        $cursor   = $start;

        for ($i = 0; $i < self::MAX_SEGMENTS; $i++) {
            // Pierwsza sekunda kolejnego miesiąca względem kursora.
            $nextMonth = $cursor->modify('first day of next month')->setTime(0, 0, 0);
            $segEnd    = $nextMonth < $end ? $nextMonth : $end;

            $seconds = $segEnd->getTimestamp() - $cursor->getTimestamp();
            if ($seconds > 0) {
                $segments[] = [
                    'year'    => (int)$cursor->format('Y'),
                    'month'   => (int)$cursor->format('n'),
                    'seconds' => $seconds,
                ];
            }

            if ($segEnd >= $end) {
                break;
            }
            $cursor = $segEnd;
        }

        return $segments;
    }

    /**
     * Dzieli kwotę całkowitą proporcjonalnie do wag, metodą największych reszt.
     *
     * Gwarancja: `array_sum(wynik) === $totalMinor` zawsze — również gdy podział
     * nie jest równy (np. 1000 gr na 3 segmenty → 334 + 333 + 333).
     *
     * Reszty rozdzielane są do segmentów o największej części ułamkowej;
     * przy remisie wygrywa segment wcześniejszy (stabilne, deterministyczne —
     * ten sam wynik przy każdym retry workera).
     *
     * Obsługuje kwoty ujemne (choć `work_earnings` ich nie używa): znak jest
     * wyłączany przed podziałem i przywracany po, żeby `intdiv` nie obcinał
     * w stronę zera niesymetrycznie.
     *
     * @param int       $totalMinor kwota do podziału (grosze)
     * @param list<int> $weights    wagi dodatnie (sekundy)
     * @return list<int> kwoty per segment, sumujące się DOKŁADNIE do $totalMinor
     *
     * @throws \InvalidArgumentException gdy wagi są puste lub niedodatnie
     */
    public static function allocate(int $totalMinor, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            throw new \InvalidArgumentException('EMPTY_WEIGHTS');
        }
        foreach ($weights as $w) {
            if (!is_int($w) || $w < 0) {
                throw new \InvalidArgumentException('INVALID_WEIGHT');
            }
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            throw new \InvalidArgumentException('ZERO_TOTAL_WEIGHT');
        }
        if ($count === 1) {
            return [$totalMinor];
        }

        $sign  = $totalMinor < 0 ? -1 : 1;
        $abs   = abs($totalMinor);

        $base       = [];
        $remainders = [];
        $assigned   = 0;

        foreach ($weights as $i => $w) {
            $product        = $abs * $w;
            $base[$i]       = intdiv($product, $totalWeight);
            $remainders[$i] = $product % $totalWeight;
            $assigned      += $base[$i];
        }

        // Rozdaj brakujące jednostki wg malejącej reszty (remis → niższy indeks).
        $leftover = $abs - $assigned;
        if ($leftover > 0) {
            $order = range(0, $count - 1);
            usort($order, static function (int $a, int $b) use ($remainders): int {
                return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
            });
            for ($k = 0; $k < $leftover; $k++) {
                $base[$order[$k % $count]]++;
            }
        }

        $out = [];
        foreach ($base as $v) {
            $out[] = $sign * $v;
        }

        return $out;
    }
}
