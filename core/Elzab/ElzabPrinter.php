<?php

declare(strict_types=1);

namespace SliceHub\Elzab;

/**
 * ElzabPrinter — połączenie TCP z drukarką Elzab Zeta Online
 * przez protokół Thermal.
 *
 * Drukarka działa jako serwer TCP (tryb SERWER, port np. 1001).
 * PHP łączy się przez fsockopen(), wysyła ramki, odbiera odpowiedzi.
 *
 * Cykl życia połączenia:
 *   1. connect()  — fsockopen do IP:port
 *   2. reset()    — CAN ×3, DLE+ENQ ×2 (handshake)
 *   3. setErrorMode(3) — pełna programowa obsługa błędów
 *   4. [komendy paragonu / raportu]
 *   5. disconnect() — fclose
 *
 * Każda komenda: wyślij ramkę → czekaj na odpowiedź → sprawdź kod błędu.
 */
final class ElzabPrinter
{
    /** @var resource|null Socket TCP */
    private $socket = null;

    /** @var string Adres IP drukarki */
    private string $host;

    /** @var int Port TCP drukarki */
    private int $port;

    /** @var int Timeout połączenia w sekundach */
    private int $connectTimeout;

    /** @var int Timeout odczytu w sekundach */
    private int $readTimeout;

    /** @var bool Czy drukarka odpowiedziała na DLE/ENQ */
    private bool $connected = false;

    public function __construct(string $host, int $port = 1001, int $connectTimeout = 5, int $readTimeout = 10)
    {
        $this->host = $host;
        $this->port = $port;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
    }

    // ── Połączenie ────────────────────────────────────────────────────────

    /**
     * Nawiąż połączenie TCP z drukarką i wykonaj handshake.
     */
    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $remote = "tcp://{$this->host}:{$this->port}";

        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float)$this->connectTimeout,
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            throw new ElzabPrinterException(
                "Nie można połączyć z drukarką {$this->host}:{$this->port} — {$errstr} ({$errno})"
            );
        }

        stream_set_timeout($this->socket, $this->readTimeout);
        $this->connected = true;

        // Handshake: reset + sprawdzenie statusu
        $this->reset();
        $this->sendCommand(ThermalProtocol::cmdSetErrorMode(3));
    }

    /**
     * Zakończ połączenie.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Czy drukarka jest połączona.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    // ── Komunikacja niskopoziomowa ─────────────────────────────────────────

    /**
     * Wyślij surowe bajty do drukarki.
     */
    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new ElzabPrinterException('Brak połączenia z drukarką — wywołaj connect()');
        }
        $written = fwrite($this->socket, $data);
        if ($written === false || $written !== strlen($data)) {
            throw new ElzabPrinterException('Błąd zapisu do drukarki');
        }
    }

    /**
     * Odczytaj odpowiedź z drukarki. Czekaj do timeout.
     */
    private function read(int $timeoutMs = 2000): string
    {
        if ($this->socket === null) {
            return '';
        }

        $buffer = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $char = fread($this->socket, 1);
            if ($char === false || $char === '') {
                usleep(1000); // 1ms
                continue;
            }
            $buffer .= $char;

            // Koniec ramki: ESC \ (0x1B 0x5C)
            if (strlen($buffer) >= 2 && substr($buffer, -2) === ThermalProtocol::SUFFIX) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Wyślij ramkę komendy i odbierz odpowiedź.
     * Rzuca wyjątek gdy kod błędu != 0.
     */
    public function sendCommand(string $frame, int $timeoutMs = 2000): string
    {
        $this->write($frame);
        $raw = $this->read($timeoutMs);

        if ($raw === '') {
            throw new ElzabPrinterException('Timeout — brak odpowiedzi drukarki', -1);
        }

        $response = ThermalProtocol::parseResponse($raw);
        $errCode = ThermalProtocol::extractErrorCode($response);

        if ($errCode !== 0) {
            throw new ElzabPrinterException(
                ThermalProtocol::errorDescription($errCode),
                $errCode
            );
        }

        return $response;
    }

    // ── Handshake ─────────────────────────────────────────────────────────

    /**
     * Reset drukarki — CAN ×3, potem DLE+ENQ ×2.
     */
    private function reset(): void
    {
        // CAN ×3 — anuluj ewentualną zawieszoną transakcję
        for ($i = 0; $i < 3; $i++) {
            $this->write(ThermalProtocol::CAN);
            usleep(10000); // 10ms
        }

        // DLE + ENQ ×2 — sprawdź status
        for ($i = 0; $i < 2; $i++) {
            $this->write(ThermalProtocol::DLE);
            usleep(10000);
            $this->write(ThermalProtocol::ENQ);
            usleep(10000);
            // Odczytaj 1 bajt statusu (ignorujemy wynik — tylko sprawdzamy że drukarka żyje)
            fread($this->socket, 1);
        }
    }

    // ── Operacje wysokiego poziomu ─────────────────────────────────────────

    /**
     * Wydrukuj paragon fiskalny.
     *
     * @param array  $lines    Linie paragonu: [['name'=>'Pizza', 'quantity'=>1, 'unit_price'=>35.00, 'vat_rate'=>8, 'line_total'=>35.00], ...]
     * @param float  $total    Kwota całkowita brutto
     * @param array  $payments Formy płatności: [['method'=>'cash', 'amount'=>50.00], ['method'=>'card', 'amount'=>20.00, 'name'=>'Karta']]
     * @param string $cashbox  Identyfikator kasy
     * @param string $cashier  Identyfikator kasjera
     * @param string $reference Numer referencyjny (np. numer zamówienia)
     * @param float  $discount  Kwota rabatu kwotowego (0 = brak)
     * @param array  $footer    Stopka paragonu: ['line1'=>'...', 'line2'=>'...', 'line3'=>'...']
     * @return string Numer paragonu fiskalnego (z odpowiedzi drukarki)
     */
    public function printReceipt(
        array $lines,
        float $total,
        array $payments,
        string $cashbox = 'POS1',
        string $cashier = 'POS',
        string $reference = '',
        float $discount = 0.0,
        array $footer = []
    ): string {
        // Start transakcji
        $this->sendCommand(ThermalProtocol::cmdTransactionStart(0), 1000);

        // Linie paragonu
        $lineNo = 1;
        foreach ($lines as $line) {
            $name = mb_substr((string)($line['name'] ?? 'Towar'), 0, 40, 'UTF-8');
            $qty = (float)($line['quantity'] ?? 1);
            $unitPrice = (float)($line['unit_price'] ?? 0);
            $vatRate = (float)($line['vat_rate'] ?? 0);
            $lineTotal = (float)($line['line_total'] ?? ($qty * $unitPrice));
            $ptu = ThermalProtocol::vatToPtu($vatRate);

            $this->sendCommand(
                ThermalProtocol::cmdTransactionLine(
                    $lineNo++,
                    $name,
                    $qty,
                    $unitPrice,
                    $ptu,
                    $lineTotal
                ),
                2000
            );
        }

        // Mapuj płatności na format Thermal
        $thermalPayments = [];
        foreach ($payments as $pay) {
            $method = (string)($pay['method'] ?? 'cash');
            $amount = (float)($pay['amount'] ?? 0);
            $type = ThermalProtocol::paymentType($method);
            $name = $pay['name'] ?? match ($method) {
                'cash' => 'Gotówka',
                'card' => 'Karta',
                'online' => 'Online',
                default => 'Inne',
            };
            $thermalPayments[] = ['type' => $type, 'amount' => $amount, 'name' => $name];
        }

        // Koniec transakcji
        $footLine1 = (string)($footer['line1'] ?? '');
        $footLine2 = (string)($footer['line2'] ?? '');
        $footLine3 = (string)($footer['line3'] ?? '');

        $response = $this->sendCommand(
            ThermalProtocol::cmdTransactionEnd(
                $total,
                $cashbox,
                $cashier,
                $reference,
                $thermalPayments,
                $footLine1,
                $footLine2,
                $footLine3,
                $discount
            ),
            10000 // 10s — drukowanie paragonu może trwać
        );

        // Otwórz szufladę (best-effort, ignoruj błąd)
        try {
            $this->sendCommand(ThermalProtocol::cmdOpenDrawer(), 1000);
        } catch (ElzabPrinterException $e) {
            // Szuflada opcjonalna — nie przerywaj
        }

        // Wyciągnij numer paragonu z odpowiedzi (jeśli dostępny)
        return $this->extractReceiptNumber($response);
    }

    /**
     * Wydrukuj raport dobowy (zamyka dobę fiskalną).
     */
    public function printDailyReport(): void
    {
        $this->sendCommand(ThermalProtocol::cmdDailyReport(), 30000);
    }

    /**
     * Pobierz status drukarki i stawki PTU.
     */
    public function getStatus(): array
    {
        $response = $this->sendCommand(ThermalProtocol::cmdStatusQuery(23), 1000);
        return $this->parseStatusResponse($response);
    }

    /**
     * Sprawdź czy drukarka jest online (ping TCP).
     */
    public function ping(): bool
    {
        try {
            $this->connect();
            $this->disconnect();
            return true;
        } catch (ElzabPrinterException $e) {
            return false;
        }
    }

    // ── Parsowanie odpowiedzi ──────────────────────────────────────────────

    /**
     * Wyciągnij numer paragonu fiskalnego z odpowiedzi komendy $y.
     * Format odpowiedzi zawiera numer po kodzie błędu i '#Z'.
     */
    private function extractReceiptNumber(string $response): string
    {
        // Odpowiedź po $y zawiera m.in. numer paragonu.
        // Format zależy od wersji protokołu — szukamy wzorca.
        // Przykładowo: "0#Z12345" lub "0#Z\n000123"
        if (preg_match('/#Z\s*0*(\d+)/', $response, $m)) {
            return $m[1];
        }
        // Jeśli nie udało się wyciągnąć, zwróć pusty — paragon i tak się wydrukował
        return '';
    }

    /**
     * Parsuj odpowiedź statusu drukarki (#s).
     * Zwraca stawki PTU i flagi statusu.
     */
    private function parseStatusResponse(string $response): array
    {
        $result = [
            'raw' => $response,
            'ptu_rates' => [],
            'online' => true,
            'paper_end' => false,
            'error' => false,
        ];

        // Stawki PTU są po pierwszym '/' i oddzielone '/'
        $parts = explode('/', $response);
        if (count($parts) >= 8) {
            $labels = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
            for ($i = 0; $i < 7 && $i < count($parts) - 1; $i++) {
                $rate = trim($parts[$i + 1]);
                if ($rate !== '' && isset($labels[$i])) {
                    $result['ptu_rates'][$labels[$i]] = $rate;
                }
            }
        }

        return $result;
    }
}

/**
 * Wyjątek błędu drukarki fiskalnej.
 */
class ElzabPrinterException extends \RuntimeException
{
    private int $errorCode;

    public function __construct(string $message, int $errorCode = 0)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}
