<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Trim and lower-case the address before validating (2026-10-26), as Add
     * User and Edit User already do: the `lowercase` rule REFUSES "Admin@remedi.com"
     * rather than folding it, so an admin's own profile was the one form where
     * a capital letter was an error. A non-string is left for 'string' to refuse.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

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
            // Editable by EVERY role, unlike `email` below which only an admin
            // may change. It is a personal inbox, not an identity: it is where
            // a password reset code is sent, so an account that cannot change
            // it after switching providers would discover that at the exact
            // moment it needed the address to work.
            // REQUIRED since 2026-10-23, at the user's request (it was optional):
            // every account then has somewhere to receive its own reset code.
            'personal_email' => ['bail', 'required', 'string', 'email', 'max:255'],
        ];

        if ($this->user()->isAdmin()) {
            // 'bail': without it every rule runs even after 'string' fails, and
            // `lowercase` calls mb_strtolower() on whatever arrived -- an array
            // (email[]=x) was an uncaught TypeError, a 500.
            $rules['email'] = [
                'bail', 'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ];
            $rules['department'] = ['nullable', 'string', 'max:255'];
            $rules['preferred_language'] = ['nullable', 'string', 'max:40'];
        }

        return $rules;
    }
}
