<?php

namespace Maya\Profile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Maya\Profile\Enums\Locale;

final class UpdateLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('jwt_user') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(Locale::values())],
        ];
    }

    public function locale(): Locale
    {
        return Locale::from($this->validated('locale'));
    }
}
