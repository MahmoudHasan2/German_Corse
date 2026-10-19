<?php

declare(strict_types=1);

namespace GermanCoach\Evaluation;

/** Closed list of error categories shared by the curriculum, the AI schema and the statistics. */
final class ErrorCategories
{
    public const ALL = [
        'verb_conjugation',
        'verb_tense',
        'verb_position',
        'word_order',
        'separable_verb',
        'modal_verb',
        'article',
        'gender',
        'case_nominative',
        'case_accusative',
        'case_dative',
        'preposition',
        'adjective_ending',
        'pronoun',
        'plural',
        'negation',
        'conjunction',
        'vocabulary',
        'word_choice',
        'spelling',
        'capitalization',
        'punctuation',
        'missing_word',
        'extra_word',
        'register',
        'comprehension',
        'other',
    ];

    /** Errors that are surface-level and often caused by transcription, not language knowledge. */
    public const SURFACE = ['spelling', 'capitalization', 'punctuation'];

    /** Categories counted as vocabulary mistakes in the statistics. */
    public const VOCABULARY = ['vocabulary', 'word_choice', 'gender', 'plural'];

    public const LABELS_AR = [
        'verb_conjugation' => 'تصريف الفعل',
        'verb_tense' => 'زمن الفعل',
        'verb_position' => 'موقع الفعل',
        'word_order' => 'ترتيب الكلمات',
        'separable_verb' => 'الأفعال المنفصلة',
        'modal_verb' => 'الأفعال المساعدة (Modalverben)',
        'article' => 'أداة التعريف/التنكير',
        'gender' => 'جنس الاسم',
        'case_nominative' => 'حالة الرفع (Nominativ)',
        'case_accusative' => 'حالة النصب (Akkusativ)',
        'case_dative' => 'حالة الجر (Dativ)',
        'preposition' => 'حروف الجر',
        'adjective_ending' => 'نهايات الصفات',
        'pronoun' => 'الضمائر',
        'plural' => 'الجمع',
        'negation' => 'النفي',
        'conjunction' => 'أدوات الربط',
        'vocabulary' => 'المفردات',
        'word_choice' => 'اختيار الكلمة',
        'spelling' => 'الإملاء',
        'capitalization' => 'الأحرف الكبيرة',
        'punctuation' => 'علامات الترقيم',
        'missing_word' => 'كلمة ناقصة',
        'extra_word' => 'كلمة زائدة',
        'register' => 'مستوى اللغة (رسمي/غير رسمي)',
        'comprehension' => 'الفهم',
        'other' => 'أخرى',
    ];
}
