<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\BannerConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
            'layout' => ['required', Rule::in([
                BannerConfig::LAYOUT_MODAL,
                BannerConfig::LAYOUT_BAR_TOP,
                BannerConfig::LAYOUT_BAR_BOTTOM,
                BannerConfig::LAYOUT_CORNER,
            ])],
            'colors' => ['required', 'array'],
            'colors.background' => $hex,
            'colors.text' => $hex,
            'colors.primary' => $hex,
            'colors.primaryText' => $hex,
            'colors.secondary' => $hex,
            'colors.secondaryText' => $hex,
            'copy' => ['required', 'array'],
            'copy.title' => ['required', 'string', 'max:120'],
            'copy.body' => ['required', 'string', 'max:600'],
            'copy.acceptAllLabel' => ['required', 'string', 'max:60'],
            'copy.rejectAllLabel' => ['required', 'string', 'max:60'],
            'copy.customiseLabel' => ['required', 'string', 'max:60'],
            'copy.savePreferencesLabel' => ['required', 'string', 'max:60'],
            'copy.categoryDescriptions' => ['required', 'array'],
            'copy.categoryDescriptions.necessary' => ['required', 'string', 'max:300'],
            'copy.categoryDescriptions.preferences' => ['required', 'string', 'max:300'],
            'copy.categoryDescriptions.statistics' => ['required', 'string', 'max:300'],
            'copy.categoryDescriptions.marketing' => ['required', 'string', 'max:300'],
        ];
    }
}
