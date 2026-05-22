<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BannerConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBannerDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $gcm = ['required', Rule::in(['granted', 'denied'])];
        $localeCode = ['regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'];

        return [
            'layout' => ['required', Rule::in([
                BannerConfig::LAYOUT_MODAL,
                BannerConfig::LAYOUT_BAR_TOP,
                BannerConfig::LAYOUT_BAR_BOTTOM,
                BannerConfig::LAYOUT_CORNER,
            ])],

            'locale_default' => ['required', 'string', ...$localeCode],
            'locales_enabled' => ['required', 'array', 'min:1'],
            'locales_enabled.*' => ['required', 'string', ...$localeCode],

            'colors' => ['required', 'array'],
            'colors.background' => $hex,
            'colors.text' => $hex,
            'colors.primary' => $hex,
            'colors.primaryText' => $hex,
            'colors.secondary' => $hex,
            'colors.secondaryText' => $hex,

            // Copy is keyed by locale: { en: {...}, fr: {...} }
            'copy' => ['required', 'array', 'min:1'],
            'copy.*' => ['required', 'array'],
            'copy.*.title' => ['required', 'string', 'max:120'],
            'copy.*.body' => ['required', 'string', 'max:600'],
            'copy.*.acceptAllLabel' => ['required', 'string', 'max:60'],
            'copy.*.rejectAllLabel' => ['required', 'string', 'max:60'],
            'copy.*.customiseLabel' => ['required', 'string', 'max:60'],
            'copy.*.savePreferencesLabel' => ['required', 'string', 'max:60'],
            'copy.*.categoryDescriptions' => ['required', 'array'],
            'copy.*.categoryDescriptions.necessary' => ['required', 'string', 'max:300'],
            'copy.*.categoryDescriptions.preferences' => ['required', 'string', 'max:300'],
            'copy.*.categoryDescriptions.statistics' => ['required', 'string', 'max:300'],
            'copy.*.categoryDescriptions.marketing' => ['required', 'string', 'max:300'],

            'gcm_defaults' => ['required', 'array'],
            'gcm_defaults.ad_storage' => $gcm,
            'gcm_defaults.analytics_storage' => $gcm,
            'gcm_defaults.ad_user_data' => $gcm,
            'gcm_defaults.ad_personalization' => $gcm,
            'gcm_defaults.functionality_storage' => $gcm,
            'gcm_defaults.personalization_storage' => $gcm,
            'gcm_defaults.security_storage' => $gcm,

            'geo_rules' => ['required', 'array'],
            'geo_rules.mode' => ['required', Rule::in(['always', 'eu_uk_only', 'whitelist', 'blacklist'])],
            'geo_rules.countries' => ['sometimes', 'array'],
            'geo_rules.countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],

            'behaviour' => ['required', 'array'],
            'behaviour.rePromptAfterDays' => ['required', 'integer', 'min:1', 'max:1825'],
            'behaviour.honourGpc' => ['required', 'boolean'],
            'behaviour.autoDenyOnDismiss' => ['required', 'boolean'],
            'behaviour.crossSubdomainConsent' => ['required', 'boolean'],
        ];
    }

    public function validationData(): array
    {
        $payload = parent::validationData();

        // locales_enabled is a list — Laravel's validator accepts the keys in
        // copy as the locales, but we also want to assert locale_default is in
        // locales_enabled. Done in withValidator() instead of rules() because
        // it's a cross-field check.

        return $payload;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $localeDefault = $this->input('locale_default');
            $localesEnabled = $this->input('locales_enabled', []);
            $copyLocales = array_keys($this->input('copy', []));

            if ($localeDefault && is_array($localesEnabled)
                && ! in_array($localeDefault, $localesEnabled, true)) {
                $validator->errors()->add(
                    'locale_default',
                    'The default locale must be listed in locales_enabled.',
                );
            }

            if (is_array($localesEnabled)) {
                foreach ($localesEnabled as $locale) {
                    if (! in_array($locale, $copyLocales, true)) {
                        $validator->errors()->add(
                            sprintf('copy.%s', $locale),
                            sprintf('Copy for locale "%s" is required.', $locale),
                        );
                    }
                }
            }
        });
    }
}
