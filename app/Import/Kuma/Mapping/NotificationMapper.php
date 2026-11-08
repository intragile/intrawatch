<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

/**
 * Translates a Kuma notification row into the body of `POST /notification-channels`. Values come from the
 * Kuma form config (keys are case-sensitive and differ per provider); nothing is invented for a missing
 * required value. A mapping that changes behaviour yields an inactive channel with a note.
 */
final class NotificationMapper
{
    public const MAX_NAME = 120;

    /** Placeholder for the required Apprise API URL; the channel is imported inactive until it is replaced. */
    private const APPRISE_PLACEHOLDER_URL = 'https://apprise.invalid';

    private const PAGERDUTY_DEFAULT_URL = 'https://events.pagerduty.com/v2/enqueue';

    /** Config keys that must be non-empty per target type, as `config.*` or `secrets.*` paths. */
    private const REQUIRED = [
        'email' => ['config.to'],
        'webhook' => ['config.url'],
        'slack' => ['secrets.webhook_url'],
        'discord' => ['secrets.webhook_url'],
        'teams' => ['secrets.webhook_url'],
        'telegram' => ['config.chat_id', 'secrets.bot_token'],
        'ntfy' => ['config.topic'],
        'gotify' => ['config.server_url', 'secrets.app_token'],
        'pushover' => ['secrets.user_key', 'secrets.app_token'],
        'matrix' => ['config.homeserver_url', 'config.room_id', 'secrets.access_token'],
        'mattermost' => ['secrets.webhook_url'],
        'google_chat' => ['secrets.webhook_url'],
        'pagerduty' => ['secrets.routing_key'],
        'opsgenie' => ['secrets.api_key'],
        'apprise' => ['secrets.urls'],
    ];

    /** Target type => [note code, message, Kuma keys that trigger it]. */
    private const IGNORED = [
        'email' => [['smtp_template_ignored', 'DKIM signing, custom subject, HTML body and additional headers are not supported and were ignored.', ['htmlBody', 'smtpAdditionalHeaders']]],
        'slack' => [['slack_template_ignored', 'The custom message template is not supported and was ignored.', ['slackUseTemplate', 'slackTemplate']]],
        'discord' => [['discord_template_ignored', 'The message prefix and custom message template are not supported and were ignored.', ['discordPrefixMessage', 'discordUseMessageTemplate', 'discordMessageTemplate']]],
        'telegram' => [['telegram_option_ignored', 'The protect-content option is not supported and was ignored.', ['telegramProtectContent']]],
        'ntfy' => [['ntfy_template_ignored', 'The custom title and message template are not supported and were ignored.', ['ntfyUseTemplate', 'ntfyCustomTitle', 'ntfyCustomMessage']]],
        'matrix' => [['matrix_template_ignored', 'The custom message template is not supported and was ignored.', ['matrixUseTemplate']]],
        'google_chat' => [['google_chat_template_ignored', 'The custom message template is not supported and was ignored.', ['googleChatUseTemplate']]],
        'pushover' => [['pushover_options_ignored', 'The custom title, time to live and priority sound are not supported and were ignored.', ['pushovertitle', 'pushoverttl', 'pushoversounds_up']]],
    ];

    /** Kuma provider type => IntraWatch channel type. */
    private const TYPES = [
        'smtp' => 'email', 'webhook' => 'webhook', 'slack' => 'slack', 'discord' => 'discord', 'teams' => 'teams',
        'telegram' => 'telegram', 'ntfy' => 'ntfy', 'gotify' => 'gotify', 'pushover' => 'pushover',
        'matrix' => 'matrix', 'mattermost' => 'mattermost', 'GoogleChat' => 'google_chat',
        'PagerDuty' => 'pagerduty', 'Opsgenie' => 'opsgenie', 'apprise' => 'apprise',
    ];

    /**
     * @param  array<string, mixed>  $row  A row of `KumaSource::notifications()`.
     */
    public function map(array $row): Mapped|Unmappable
    {
        $config = [];
        foreach (is_array($row['config'] ?? null) ? $row['config'] : [] as $key => $value) {
            $config[(string) $key] = $value;
        }
        $kumaType = is_string($config['type'] ?? null) ? $config['type'] : '';
        $type = self::TYPES[$kumaType] ?? null;

        if ($type === null) {
            return new Unmappable(Note::of(
                'provider_unsupported',
                "The notification provider '".self::label($kumaType)."' has no IntraWatch equivalent.",
            ));
        }

        $notes = [];
        $active = ($row['active'] ?? true) !== false;
        $name = $this->name($row, $config, $notes);

        $built = match ($type) {
            'email' => $this->email($config, $notes),
            'webhook' => $this->webhook($config, $notes, $active),
            'slack' => $this->slack($config, $notes),
            'discord' => $this->discord($config, $notes),
            'teams' => [[], ['webhook_url' => self::str($config, 'webhookUrl')]],
            'telegram' => $this->telegram($config, $notes),
            'ntfy' => $this->ntfy($config, $notes, $active),
            'gotify' => [
                ['server_url' => self::str($config, 'gotifyserverurl'), 'priority' => self::int($config, 'gotifyPriority')],
                ['app_token' => self::str($config, 'gotifyapplicationToken')],
            ],
            'pushover' => $this->pushover($config, $notes),
            'matrix' => [
                ['homeserver_url' => self::str($config, 'homeserverUrl'), 'room_id' => self::str($config, 'internalRoomId')],
                ['access_token' => self::str($config, 'accessToken')],
            ],
            'mattermost' => $this->mattermost($config, $notes),
            'google_chat' => [[], ['webhook_url' => self::str($config, 'googleChatWebhookURL')]],
            'pagerduty' => $this->pagerduty($config, $notes),
            'opsgenie' => $this->opsgenie($config),
            default => $this->apprise($config, $notes, $active),
        };

        [$channelConfig, $secrets] = $built;
        $channelConfig = self::compact($channelConfig);
        $secrets = self::compact($secrets);

        $this->ignoredSettings($type, $config, $notes);

        $missing = $this->missing($type, $channelConfig, $secrets, $name);
        if ($missing !== []) {
            return new Unmappable(
                Note::of('channel_field_missing', 'A value the channel requires is missing in the source.'),
                $missing,
            );
        }

        return new Mapped([
            'name' => $name,
            'type' => $type,
            'config' => $channelConfig,
            'secrets' => $secrets,
            'is_default' => ($row['is_default'] ?? false) === true,
            'active' => $active,
        ], $notes);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function email(array $config, array &$notes): array
    {
        $to = self::addresses(self::str($config, 'smtpTo'));
        $extra = [...self::addresses(self::str($config, 'smtpCC')), ...self::addresses(self::str($config, 'smtpBCC'))];
        if ($extra !== []) {
            $notes[] = Note::of('smtp_cc_merged', 'CC and BCC recipients were merged into the recipient list.');
        }
        $to = self::unique([...$to, ...$extra]);

        foreach (['smtpDkimDomain', 'smtpDkimKeySelector', 'smtpDkimPrivateKey', 'customSubject', 'customBody'] as $key) {
            if (self::str($config, $key) !== null) {
                $notes[] = Note::of('smtp_template_ignored', 'DKIM signing and custom subject or body are not supported and were ignored.');
                break;
            }
        }

        [$fromName, $fromAddress] = self::parseFrom(self::str($config, 'smtpFrom'));
        $encryption = self::bool($config, 'smtpSecure') ? 'tls' : (self::bool($config, 'smtpIgnoreSTARTTLS') ? 'none' : 'starttls');

        return [[
            'to' => $to,
            'smtp' => [
                'host' => self::str($config, 'smtpHost'),
                'port' => self::int($config, 'smtpPort'),
                'encryption' => $encryption,
                'username' => self::str($config, 'smtpUsername'),
                'from_address' => $fromAddress,
                'from_name' => $fromName,
            ],
        ], ['smtp_password' => self::str($config, 'smtpPassword')]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function webhook(array $config, array &$notes, bool &$active): array
    {
        $method = strtoupper(self::str($config, 'httpMethod') ?? 'POST');
        if (! in_array($method, ['POST', 'PUT'], true)) {
            $method = 'POST';
            $active = false;
            $notes[] = Note::of('webhook_method_changed', 'The HTTP method is not supported; POST is used and the channel was imported inactive.');
        }

        $format = 'json';
        $contentType = self::str($config, 'webhookContentType');
        if ($contentType === 'form-data') {
            $format = 'form';
        } elseif ($contentType === 'custom') {
            $active = false;
            $notes[] = Note::of('webhook_custom_body_ignored', 'The custom request body is not supported; the standard JSON body would be sent, so the channel was imported inactive.');
        }

        $headers = [];
        $secretHeaders = [];
        $raw = $config['webhookAdditionalHeaders'] ?? null;
        $decoded = is_array($raw) ? $raw : (is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : []);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $active = false;
            $notes[] = Note::of('webhook_headers_invalid', 'The additional headers are not a valid JSON object and were dropped; the channel was imported inactive.');
            $decoded = [];
        }
        foreach ($decoded as $header => $value) {
            $header = (string) $header;
            $value = is_scalar($value) ? (string) $value : '';
            if (self::isSecretHeader($header)) {
                $secretHeaders[$header] = $value;
            } else {
                $headers[$header] = $value;
            }
        }

        return [
            ['url' => self::str($config, 'webhookURL'), 'method' => $method, 'format' => $format, 'headers' => $headers],
            ['secret_headers' => $secretHeaders === [] ? null : $secretHeaders],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function slack(array $config, array &$notes): array
    {
        foreach (['slackchannel', 'slackusername', 'slackiconemo', 'slackiconurl'] as $key) {
            if (self::str($config, $key) !== null) {
                $notes[] = Note::of('slack_overrides_ignored', 'Channel, username and icon overrides are not supported and were ignored.');
                break;
            }
        }

        return [[], ['webhook_url' => self::str($config, 'slackwebhookURL')]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function discord(array $config, array &$notes): array
    {
        if (self::str($config, 'discordPrefixMessage') !== null) {
            $notes[] = Note::of('discord_template_ignored', 'The message prefix is not supported and was ignored.');
        }

        return [
            ['username' => self::str($config, 'discordUsername'), 'thread_id' => self::str($config, 'threadId')],
            ['webhook_url' => self::str($config, 'discordWebhookUrl')],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function telegram(array $config, array &$notes): array
    {
        if (self::bool($config, 'telegramUseTemplate')) {
            $notes[] = Note::of('telegram_template_ignored', 'The custom message template is not supported and was ignored.');
        }

        return [[
            'chat_id' => self::str($config, 'telegramChatID'),
            'message_thread_id' => self::int($config, 'telegramMessageThreadID'),
            'server_url' => self::str($config, 'telegramServerUrl'),
            'silent' => self::bool($config, 'telegramSendSilently'),
        ], ['bot_token' => self::str($config, 'telegramBotToken')]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function ntfy(array $config, array &$notes, bool &$active): array
    {
        $token = self::str($config, 'ntfyaccesstoken');
        $basic = self::str($config, 'ntfyAuthenticationMethod') === 'usernamePassword'
            || ($token === null && (self::str($config, 'ntfyusername') !== null || self::str($config, 'ntfypassword') !== null));
        if ($basic) {
            $active = false;
            $token = null;
            $notes[] = Note::of('ntfy_basic_auth_unsupported', 'Username and password authentication is not supported; add an access token and activate the channel.');
        }

        return [[
            'server_url' => self::str($config, 'ntfyserverurl'),
            'topic' => self::str($config, 'ntfytopic'),
            'priority' => self::int($config, 'ntfyPriority'),
        ], ['access_token' => $token]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function pushover(array $config, array &$notes): array
    {
        if (self::str($config, 'pushovertitle') !== null || self::str($config, 'pushoverttl') !== null) {
            $notes[] = Note::of('pushover_options_ignored', 'The custom title and time to live are not supported and were ignored.');
        }

        return [[
            'priority' => self::int($config, 'pushoverpriority'),
            'sound' => self::str($config, 'pushoversounds'),
            'device' => self::str($config, 'pushoverdevice'),
        ], [
            'user_key' => self::str($config, 'pushoveruserkey'),
            'app_token' => self::str($config, 'pushoverapptoken'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function mattermost(array $config, array &$notes): array
    {
        if (self::str($config, 'mattermosticonemo') !== null || self::str($config, 'mattermosticonurl') !== null) {
            $notes[] = Note::of('mattermost_icon_ignored', 'The icon override is not supported and was ignored.');
        }

        return [
            ['channel' => self::str($config, 'mattermostchannel'), 'username' => self::str($config, 'mattermostusername')],
            ['webhook_url' => self::str($config, 'mattermostWebhookUrl')],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function pagerduty(array $config, array &$notes): array
    {
        $url = self::str($config, 'pagerdutyIntegrationUrl');
        if (($url !== null && rtrim($url, '/') !== self::PAGERDUTY_DEFAULT_URL) || self::str($config, 'pagerdutyPriority') !== null) {
            $notes[] = Note::of('pagerduty_options_ignored', 'A custom integration URL and the priority are not supported and were ignored.');
        }

        return [[], ['routing_key' => self::str($config, 'pagerdutyIntegrationKey')]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function opsgenie(array $config): array
    {
        $priority = self::int($config, 'opsgeniePriority');
        $region = strtolower(self::str($config, 'opsgenieRegion') ?? 'us');

        return [[
            'region' => $region,
            'priority' => $priority !== null && $priority >= 1 && $priority <= 5 ? 'P'.$priority : null,
        ], ['api_key' => self::str($config, 'opsgenieApiKey')]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function apprise(array $config, array &$notes, bool &$active): array
    {
        $active = false;
        $notes[] = Note::of('apprise_server_required', 'Kuma runs the Apprise command line locally; IntraWatch needs an Apprise API server. Set its URL on the channel, then activate it.');

        return [['server_url' => self::APPRISE_PLACEHOLDER_URL], ['urls' => self::str($config, 'appriseURL')]];
    }

    /**
     * Notes for template and message settings that are set in the source but have no equivalent; the channel
     * itself stays as mapped.
     *
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     */
    private function ignoredSettings(string $type, array $config, array &$notes): void
    {
        foreach (self::IGNORED[$type] ?? [] as [$code, $message, $keys]) {
            foreach ($notes as $existing) {
                if ($existing->code === $code) {
                    continue 2;
                }
            }
            foreach ($keys as $key) {
                if (self::isSet($config[$key] ?? null)) {
                    $notes[] = Note::of($code, $message);
                    break;
                }
            }
        }
    }

    private static function isSet(mixed $value): bool
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
        }

        return is_array($value) ? $value !== [] : (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $config
     * @param  list<Note>  $notes
     */
    private function name(array $row, array $config, array &$notes): string
    {
        $name = is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
        if ($name === '' && is_scalar($config['name'] ?? null)) {
            $name = trim((string) $config['name']);
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            $name = mb_substr($name, 0, self::MAX_NAME);
            $notes[] = Note::of('channel_name_truncated', 'The channel name was longer than '.self::MAX_NAME.' characters and was shortened.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     * @return array<string, list<string>>
     */
    private function missing(string $type, array $config, array $secrets, string $name): array
    {
        $errors = [];
        if ($name === '') {
            $errors['name'] = ['The channel has no name.'];
        }
        foreach (self::REQUIRED[$type] as $path) {
            [$bag, $key] = explode('.', $path, 2);
            $value = ($bag === 'config' ? $config : $secrets)[$key] ?? null;
            if ($value === null || $value === [] || $value === '') {
                $errors[$path] = ["The {$path} field is required."];
            }
        }

        return $errors;
    }

    /**
     * Drops null values. A false flag and empty headers stay, because the
     * channel type defaults them.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function str(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function int(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function bool(array $config, string $key): bool
    {
        $value = $config[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @return list<string>
     */
    private static function addresses(?string $list): array
    {
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[,;]/', $list) ?: [] as $address) {
            $address = trim($address);
            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        $seen = [];
        foreach ($values as $value) {
            $seen[strtolower($value)] ??= $value;
        }

        return array_values($seen);
    }

    /**
     * @return array{0: string|null, 1: string|null} Display name and address of a `Name <a@b>` sender.
     */
    private static function parseFrom(?string $from): array
    {
        if ($from === null) {
            return [null, null];
        }
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $from, $m) === 1) {
            $name = trim($m[1]);

            return [$name === '' ? null : $name, trim($m[2])];
        }

        return [null, $from];
    }

    private static function isSecretHeader(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, ['authorization', 'cookie'], true) || str_contains($name, 'token') || str_contains($name, 'key');
    }

    private static function label(string $type): string
    {
        $type = preg_replace('/[^\x20-\x7E]/', '', $type) ?? '';

        return $type === '' ? 'unknown' : mb_substr($type, 0, 60);
    }
}
