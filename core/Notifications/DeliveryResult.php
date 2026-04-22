<?php
declare(strict_types=1);

/**
 * DeliveryResult — wynik pojedynczej próby wysyłki przez kanał.
 */
final class DeliveryResult
{
    public readonly bool   $success;
    public readonly string $status;          // 'sent' | 'failed'
    public readonly ?string $messageId;      // ID wiadomości od providera (do trackowania statusu)
    public readonly ?string $errorMessage;
    public readonly ?int    $costGrosze;     // Koszt SMS w groszach (jeśli znany)

    public function __construct(
        bool    $success,
        string  $status,
        ?string $messageId    = null,
        ?string $errorMessage = null,
        ?int    $costGrosze   = null
    ) {
        $this->success      = $success;
        $this->status       = $status;
        $this->messageId    = $messageId;
        $this->errorMessage = $errorMessage;
        $this->costGrosze   = $costGrosze;
    }

    public static function ok(?string $messageId = null, ?int $costGrosze = null): self
    {
        return new self(true, 'sent', $messageId, null, $costGrosze);
    }

    public static function fail(string $errorMessage): self
    {
        return new self(false, 'failed', null, $errorMessage);
    }
}
