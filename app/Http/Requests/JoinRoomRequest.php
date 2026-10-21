<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinRoomRequest extends FormRequest
{
    /**
     * Trim before validating: a love is what the guest meant, not their spaces.
     */
    protected function prepareForValidation(): void
    {
        $loves = $this->input('loves');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'loves' => is_array($loves) ? array_map(fn ($love) => is_string($love) ? trim($love) : $love, $loves) : $loves,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'loves' => ['required', 'array', 'size:3'],
            'loves.*' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'loves.*.required' => 'Three things, please. Anything counts.',
            'loves.*.max' => 'Keep it under 80 characters.',
        ];
    }
}
