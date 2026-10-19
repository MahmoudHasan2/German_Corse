// Arabic UI strings and translations of stable server codes.

export const ERRORS = {
    network: 'تعذّر الاتصال بالخادم. تحقّق من الإنترنت ثم حاول مجددًا.',
    timeout: 'استغرق الطلب وقتًا طويلًا. حاول مرة أخرى.',
    unauthenticated: 'انتهت الجلسة. سجّل الدخول من جديد.',
    csrf_invalid: 'انتهت صلاحية رمز الأمان. أعد تحميل الصفحة.',
    invalid_credentials: 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    too_many_attempts: 'محاولات كثيرة. انتظر قليلًا ثم حاول مجددًا.',
    validation_failed: 'بعض البيانات غير صحيحة.',
    lesson_locked: 'هذا الدرس مقفل حتى تُكمل الدروس السابقة.',
    session_completed: 'هذه الجلسة منتهية.',
    answer_in_progress: 'ما زالت إجابة سابقة قيد المعالجة. انتظر لحظة.',
    not_answerable: 'لا يوجد تمرين مفتوح الآن.',
    no_reviews_due: 'لا توجد مراجعات مستحقة الآن.',
    hints_disabled: 'التلميحات غير متاحة في الاختبار.',
    ai_provider_error: 'خدمة التقييم لا تستجيب الآن. حاول مرة أخرى بعد قليل.',
    stt_unavailable: 'تعذّر تحويل الصوت إلى نص الآن. يمكنك كتابة الإجابة.',
    tts_unavailable: 'تعذّر توليد الصوت الآن.',
    audio_limit: 'وصلت إلى حدّ استخدام الصوت.',
    audio_missing: 'لم يُرسل أي تسجيل.',
    audio_empty: 'التسجيل فارغ أو قصير جدًا.',
    audio_too_large: 'التسجيل كبير جدًا.',
    audio_too_long: 'التسجيل أطول من المسموح.',
    audio_unsupported_type: 'صيغة الصوت غير مدعومة.',
    audio_partial: 'انقطع رفع التسجيل. حاول مجددًا.',
    audio_no_speech: 'لم نتعرّف على كلام في التسجيل.',
    invalid_setup_token: 'رمز الإعداد غير صحيح.',
    account_exists: 'يوجد حساب بالفعل. سجّل الدخول.',
    use_review_session: 'تُراجَع التمارين داخل جلسة مراجعة.',
    server_error: 'حدث خطأ في الخادم. حاول لاحقًا.',
    forbidden: 'غير مسموح.',
    not_found: 'غير موجود.',
    mic_denied: 'تم رفض إذن الميكروفون. اسمح للموقع باستخدام الميكروفون من إعدادات المتصفح، أو اكتب إجابتك.',
    mic_missing: 'لم يُعثر على ميكروفون في هذا الجهاز.',
    mic_busy: 'الميكروفون مستخدم من تطبيق آخر.',
    mic_insecure: 'يتطلّب الميكروفون اتصالًا آمنًا (HTTPS).',
    mic_unsupported: 'متصفحك لا يدعم التسجيل الصوتي. استخدم الكتابة أو متصفحًا حديثًا.',
    mic_error: 'تعذّر تشغيل الميكروفون.',
};

export const FIELD_ERRORS = {
    required: 'هذا الحقل مطلوب.',
    too_short: 'قصير جدًا.',
    too_long: 'طويل جدًا.',
    invalid_email: 'بريد إلكتروني غير صالح.',
    password_too_short: 'كلمة المرور يجب أن تكون 10 أحرف على الأقل.',
    password_too_long: 'كلمة المرور طويلة جدًا.',
    incorrect: 'غير صحيح.',
    already_registered: 'هذا البريد مسجّل.',
    type_DELETE: 'اكتب DELETE للتأكيد.',
    out_of_range: 'القيمة خارج النطاق المسموح.',
    invalid_choice: 'اختيار غير صالح.',
    invalid_key: 'المفتاح غير صحيح — انسخه كاملًا كما هو.',
};

export const VERDICTS = {
    correct: 'صحيحة',
    correct_variant: 'صحيحة بصياغة مختلفة',
    minor_errors: 'مفهومة مع أخطاء بسيطة',
    grammar_errors: 'فيها أخطاء قواعدية',
    off_topic: 'لا تجيب عن السؤال',
    incomplete: 'غير مكتملة',
    possible_transcription_error: 'ربما خطأ في التعرّف على الصوت',
    uncertain: 'تعذّر التقييم بثقة',
};

export const SKILLS = {
    speaking: 'التحدّث',
    listening: 'الاستماع',
    grammar: 'القواعد',
    vocabulary: 'المفردات',
    reading: 'القراءة',
    writing: 'الكتابة',
};

export const LESSON_STATUS = {
    completed: 'مكتمل',
    in_progress: 'قيد التعلّم',
    available: 'متاح',
    locked: 'مقفل',
};

export const EX_STATUS = {
    not_started: 'لم يبدأ',
    in_progress: 'قيد التعلّم',
    needs_review: 'يحتاج مراجعة',
    skipped: 'تم تخطّيه',
    provisionally_mastered: 'متقن مبدئيًا',
};

export const TYPES = {
    answer_question: 'أجب عن السؤال',
    translate: 'ترجمة',
    gap_fill: 'املأ الفراغ',
    transform: 'تحويل الجملة',
    build_sentence: 'كوّن جملة',
    listening: 'استماع',
    reading: 'قراءة',
    writing: 'كتابة',
    role_play: 'لعب أدوار',
    describe: 'وصف',
};

export function errorMessage(err) {
    if (!err) return ERRORS.server_error;
    if (ERRORS[err.code]) {
        // Server-provided Arabic messages (e.g. usage limits) are more specific.
        if (/[؀-ۿ]/.test(err.message || '')) return err.message;
        return ERRORS[err.code];
    }
    if (/[؀-ۿ]/.test(err.message || '')) return err.message;
    return ERRORS.server_error;
}
