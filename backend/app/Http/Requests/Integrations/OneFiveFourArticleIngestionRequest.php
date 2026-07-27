<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OneFiveFourArticleIngestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:5000'],
            'body_html' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(['draft', 'published', 'scheduled', 'archived'])],
            'author' => ['required', 'array'],
            'author.name' => ['required', 'string', 'max:255'],
            'author.email' => ['nullable', 'email', 'max:255'],
            'author.slug' => ['nullable', 'string', 'max:255'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'array'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.slug' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:255'],
            'featured_image_url' => ['nullable', 'url', 'max:2048'],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.meta_description' => ['nullable', 'string'],
            'seo.description' => ['nullable', 'string'],
            'seo.canonical_url' => ['nullable', 'url', 'max:2048'],
            'seo.breadcrumbs' => ['nullable', 'array'],
            'seo.schema_json' => ['nullable', 'array'],
            'og' => ['nullable', 'array'],
            'og.title' => ['nullable', 'string', 'max:255'],
            'og.description' => ['nullable', 'string'],
            'og.image_url' => ['nullable', 'url', 'max:2048'],
            'source_id' => ['required', 'string', 'max:255'],
        ];
    }
}
