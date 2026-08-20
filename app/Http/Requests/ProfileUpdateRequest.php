<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Staff may only change their own NAME and PHONE NUMBER. Everything else on
     * the profile — email address, department, preferred language — is an admin
     * decision, so those keys are not accepted at all for a staff account
     * rather than merely being disabled in the form. A disabled input is a
     * suggestion; anyone can post the field anyway, so the restriction has to
     * live here.
     *
     * Role is not accepted for ANYONE, including admins: this form belongs to
     * the account holder, and a self-service role change is a privilege
     * escalation. Roles are changed from User Management.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];

        if ($this->user()->isAdmin()) {
            $rules['email'] = [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ];
            $rules['department'] = ['nullable', 'string', 'max:255'];
            $rules['preferred_language'] = ['nullable', 'string', 'max:40'];
        }

        return $rules;
    }
}
