<?php

declare(strict_types=1);

namespace GermanCoach\Usage;

final class UsageDecision
{
    /** @param array<string,mixed> $snapshot */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly bool $warning,
        public readonly array $snapshot,
        public readonly int $retryAfterSeconds = 0,
    ) {
    }

    public function messageAr(): string
    {
        return match ($this->reason) {
            'ai_disabled' => 'ميزات الذكاء الاصطناعي متوقفة حاليًا من قِبل المسؤول.',
            'ai_not_configured' => 'لم يتم إعداد مزوّد الذكاء الاصطناعي بعد (مفتاح API غير موجود).',
            'monthly_budget_reached' => 'تم الوصول إلى حدّ الميزانية الشهرية. توقّفت طلبات الذكاء الاصطناعي حتى بداية الشهر القادم أو حتى يرفع المسؤول الحدّ.',
            'daily_request_limit' => 'وصلت إلى الحدّ اليومي لعدد طلبات الذكاء الاصطناعي. يمكنك متابعة المراجعة والمفردات بدون ذكاء اصطناعي.',
            'daily_budget_reached' => 'وصلت إلى حدّ التكلفة اليومية. يمكنك متابعة التمارين ذات الإجابات المحددة والمراجعة والمفردات.',
            'daily_audio_limit' => 'وصلت إلى الحدّ اليومي لدقائق التسجيل الصوتي. يمكنك الكتابة بدلًا من التحدث.',
            'session_request_limit' => 'وصلت إلى حدّ الطلبات لهذه الجلسة. ابدأ جلسة جديدة لاحقًا.',
            'rate_limited' => 'طلبات كثيرة في وقت قصير. انتظر دقيقة ثم حاول مجددًا.',
            default => 'ميزات الذكاء الاصطناعي غير متاحة الآن.',
        };
    }
}
