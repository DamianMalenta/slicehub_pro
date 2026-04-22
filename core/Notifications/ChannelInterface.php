<?php
declare(strict_types=1);

/**
 * ChannelInterface — kontrakt dla każdego kanału powiadomień.
 *
 * Implementacje: InAppChannel, EmailChannel, PersonalPhoneChannel, SmsGatewayChannel.
 * Każda implementacja jest bezstanowa — credentials przychodzą z $channelConfig
 * (jeden wiersz z sh_notification_channels z zdekodowanym credentials_json).
 */
interface ChannelInterface
{
    /**
     * Wyślij powiadomienie przez ten kanał.
     *
     * @param  string $recipient     Email lub znormalizowany numer telefonu (+48XXXXXXXXX)
     * @param  string $subject       Temat (dla email) — ignorowany przez kanały SMS
     * @param  string $body          Treść wiadomości po renderowaniu szablonu
     * @param  array  $channelConfig Wiersz z sh_notification_channels + credentials jako array
     * @param  array  $ctx           Dodatkowy kontekst: event_type, order_id, tracking_token, itp.
     *
     * @return DeliveryResult
     */
    public function send(
        string $recipient,
        string $subject,
        string $body,
        array  $channelConfig,
        array  $ctx = []
    ): DeliveryResult;

    /**
     * Zwraca channel_type obsługiwany przez tę implementację.
     * Używane przez ChannelRegistry do routowania.
     */
    public static function getChannelType(): string;
}
