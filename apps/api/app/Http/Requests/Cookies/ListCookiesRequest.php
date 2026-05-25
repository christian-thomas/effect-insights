<?php

declare(strict_types=1);

namespace App\Http\Requests\Cookies;

use App\Enums\CookieCategory;
use App\Models\Cookie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation + filter composition for the canonical cookie catalogue
 * listing. Filters are deliberately permissive — operators searching
 * for "_ga" should not get a 422 because the string is too short.
 */
class ListCookiesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', Rule::enum(CookieCategory::class)],
        ];
    }

    /**
     * @param  Builder<Cookie>  $query
     * @return Builder<Cookie>
     */
    public function applyFilters(Builder $query): Builder
    {
        $search = $this->string('search')->trim()->toString();
        $category = $this->string('category')->trim()->toString();

        return $query
            ->orderBy('vendor')
            ->orderBy('name_pattern')
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $builder) use ($search): void {
                    $like = sprintf('%%%s%%', $search);
                    $builder->where('name_pattern', 'like', $like)
                        ->orWhere('vendor', 'like', $like);
                }),
            )
            ->when(
                $category !== '',
                fn (Builder $query): Builder => $query->where('category', $category),
            );
    }
}
