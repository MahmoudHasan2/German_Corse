<?php

declare(strict_types=1);

namespace GermanCoach\Ai;

/**
 * Normalized provider failure. $code is stable and safe to expose; $providerMessage
 * may contain vendor details and is only written to the private log.
 */
final class ProviderException extends \RuntimeException
{
    public const TIMEOUT = 'timeout';
    public const NETWORK = 'network_error';
    public const AUTH = 'auth_failed';
    public const PERMISSION = 'permission_denied';
    public const NOT_FOUND = 'model_or_endpoint_not_found';
    public const BAD_REQUEST = 'bad_request';
    public const TOO_LARGE = 'payload_too_large';
    public const RATE_LIMITED = 'rate_limited';
    public const QUOTA = 'quota_exceeded';
    public const UNAVAILABLE = 'provider_unavailable';
    public const INVALID_RESPONSE = 'invalid_response';
    public const REFUSAL = 'refusal';
    public const INCOMPLETE = 'incomplete_response';
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly string $providerMessage = '',
        public readonly ?string $providerRequestId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($errorCode);
    }

    /** @param array<string,mixed>|null $body decoded error body */
    public static function fromHttp(int $status, ?array $body, ?string $requestId, ?int $retryAfter): self
    {
        $err = is_array($body['error'] ?? null) ? $body['error'] : [];
        $providerCode = (string) ($err['code'] ?? $err['type'] ?? '');
        $message = mb_substr((string) ($err['message'] ?? ''), 0, 300);
        // Some providers only state the wait in the message ("try again in 9.08s").
        if ($retryAfter === null && preg_match('/try again in ([0-9.]+)\s*s/i', $message, $m)) {
            $retryAfter = (int) ceil((float) $m[1]);
        }
        $invalidKey = str_contains($message, 'API key not valid') || ($err['details'][0]['reason'] ?? '') === 'API_KEY_INVALID';
        [$code, $retryable] = match (true) {
            $status === 401, $invalidKey => [self::AUTH, false],
            $status === 403 => [self::PERMISSION, false],
            $status === 404 => [self::NOT_FOUND, false],
            $status === 413 => [self::TOO_LARGE, false],
            $status === 429 && in_array($providerCode, ['insufficient_quota', 'billing_hard_limit_reached'], true) => [self::QUOTA, false],
            $status === 429 => [self::RATE_LIMITED, true],
            $status >= 500 => [self::UNAVAILABLE, true],
            $status >= 400 => [self::BAD_REQUEST, false],
            default => [self::INVALID_RESPONSE, false],
        };
        return new self($code, $status, $retryable, $providerCode . ': ' . $message, $requestId, $retryAfter);
    }

    public static function fromCurl(int $errno, string $error): self
    {
        // 28 = CURLE_OPERATION_TIMEDOUT
        return $errno === 28
            ? new self(self::TIMEOUT, null, true, $error)
            : new self(self::NETWORK, null, true, "curl $errno: $error");
    }

    /** Arabic-first, user-safe message for the UI. */
    public function userMessageAr(): string
    {
        return match ($this->errorCode) {
            self::TIMEOUT, self::NETWORK, self::UNAVAILABLE => 'خدمة الذكاء الاصطناعي لا تستجيب حاليًا. حاول مرة أخرى بعد قليل.',
            self::RATE_LIMITED => 'وصلت إلى حدّ الطلبات لدى مزوّد الذكاء الاصطناعي (الطبقة المجانية محدودة). انتظر دقيقة ثم حاول مجددًا.',
            self::QUOTA => 'رصيد مزوّد الذكاء الاصطناعي نفد. يجب على المسؤول مراجعة الحساب لدى المزوّد.',
            self::AUTH, self::PERMISSION, self::NOT_FOUND, self::NOT_CONFIGURED => 'خدمة الذكاء الاصطناعي غير مهيّأة بشكل صحيح. راجع الإعدادات.',
            self::TOO_LARGE => 'الملف كبير جدًا.',
            self::REFUSAL => 'لم يتمكّن المقيّم من تقييم هذه الإجابة. جرّب صياغة أخرى.',
            default => 'تعذّر تقييم الإجابة الآن. حاول مرة أخرى.',
        };
    }
}
