<?php
declare(strict_types=1);

/**
 * ChannelRegistry — mapowanie channel_type → ChannelInterface implementacja.
 *
 * Wzorzec 1:1 z AdapterRegistry z core/Integrations/.
 * Klasy kanałów są lazy-loaded przy pierwszym użyciu.
 */
final class ChannelRegistry
{
    /** @var array<string, string> channel_type => fully-qualified class name */
    private static array $classMap = [
        'in_app'         => \SliceHub\Notifications\Channels\InAppChannel::class,
        'email'          => \SliceHub\Notifications\Channels\EmailChannel::class,
        'personal_phone' => \SliceHub\Notifications\Channels\PersonalPhoneChannel::class,
        'sms_gateway'    => \SliceHub\Notifications\Channels\SmsGatewayChannel::class,
    ];

    /** @var array<string, ChannelInterface> Singleton instances per channel_type */
    private static array $instances = [];

    private static string $channelsDir = '';

    public static function setChannelsDir(string $dir): void
    {
        self::$channelsDir = rtrim($dir, '/\\');
    }

    /**
     * Pobierz instancję kanału dla danego channel_type.
     * Zwraca null jeśli typ nieznany lub plik nie istnieje.
     */
    public static function get(string $channelType): ?ChannelInterface
    {
        if (isset(self::$instances[$channelType])) {
            return self::$instances[$channelType];
        }

        $dir  = self::$channelsDir ?: __DIR__ . '/Channels';
        $file = $dir . '/' . ucfirst(str_replace('_', '', ucwords($channelType, '_'))) . 'Channel.php';

        // Fallback: manual map
        $fileMap = [
            'in_app'         => $dir . '/InAppChannel.php',
            'email'          => $dir . '/EmailChannel.php',
            'personal_phone' => $dir . '/PersonalPhoneChannel.php',
            'sms_gateway'    => $dir . '/SmsGatewayChannel.php',
        ];
        $file = $fileMap[$channelType] ?? $file;

        if (!file_exists($file)) {
            error_log("[ChannelRegistry] Channel file not found: {$file}");
            return null;
        }

        require_once $file;

        $classMap = [
            'in_app'         => 'InAppChannel',
            'email'          => 'EmailChannel',
            'personal_phone' => 'PersonalPhoneChannel',
            'sms_gateway'    => 'SmsGatewayChannel',
        ];

        $className = $classMap[$channelType] ?? null;
        if (!$className || !class_exists($className)) {
            error_log("[ChannelRegistry] Class '{$className}' not found for channel_type '{$channelType}'");
            return null;
        }

        $instance = new $className();
        if (!($instance instanceof ChannelInterface)) {
            error_log("[ChannelRegistry] {$className} does not implement ChannelInterface");
            return null;
        }

        self::$instances[$channelType] = $instance;
        return $instance;
    }

    /** Lista znanych channel_type (dla UI). */
    public static function knownTypes(): array
    {
        return array_keys(self::$fileMap ?? [
            'in_app'         => '',
            'email'          => '',
            'personal_phone' => '',
            'sms_gateway'    => '',
        ]);
    }
}
