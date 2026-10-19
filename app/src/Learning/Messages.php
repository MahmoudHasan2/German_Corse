<?php

declare(strict_types=1);

namespace GermanCoach\Learning;

use GermanCoach\Mastery\Decision;

/** Arabic-first messages explaining each progression decision to the learner. */
final class Messages
{
    public static function decision(string $code): string
    {
        return match ($code) {
            Decision::RETRY => 'حاول مرة أخرى — السؤال نفسه ما زال مطروحًا.',
            Decision::RETRY_NEW_STRATEGY => 'لنجرّب طريقة أخرى: اقرأ الشرح الإضافي ثم حاول مجددًا.',
            Decision::RETRY_UNCERTAIN => 'لم نتمكّن من تقييم الإجابة بثقة كافية، لذلك لا تُحتسب هذه المحاولة. أعد الصياغة بوضوح.',
            Decision::CONFIRM_TRANSCRIPT => 'ربما لم يُفهم تسجيلك بشكل صحيح. راجع النص وصحّحه إن لزم، ثم أرسله.',
            Decision::REQUIRE_TRANSFER => 'أحسنت! الآن مهمة نقل: نفس المهارة في سياق جديد، بدون مساعدة.',
            Decision::NEXT_TRANSFER => 'جيد. لنتأكّد بمهمة نقل أخرى دون مساعدة.',
            Decision::MASTERED => 'ممتاز! أتقنت هذا التمرين مبدئيًا، وسنراجعه لاحقًا.',
            Decision::NEEDS_REVIEW => 'نجحت بمساعدة. سنعيد هذا التمرين في المراجعة لاحقًا.',
            Decision::DEFERRED => 'هذا التمرين صعب الآن. نقلناه إلى المراجعة ولن يُحتسب متقنًا.',
            Decision::SKIPPED => 'تم تخطّي التمرين مؤقتًا. سيظهر في المراجعة ولن يُحتسب متقنًا.',
            Decision::ASSESSMENT_RECORDED => 'تم حفظ إجابتك.',
            Decision::OFFLINE_SELF_CHECK => 'التقييم الآلي غير متاح الآن. قارن إجابتك بالإجابات النموذجية، ثم حاول لاحقًا أو تخطَّ التمرين.',
            default => '',
        };
    }

    public static function strategy(int $level): string
    {
        return match ($level) {
            1 => 'تلميح إضافي',
            2 => 'شرح القاعدة',
            3 => 'جملة نموذجية للتكرار — بعدها ستحتاج مهمة نقل لإثبات الإتقان',
            default => '',
        };
    }
}
