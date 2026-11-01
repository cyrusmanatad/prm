<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\PartnershipInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        // Registration from a partner invitation is locked to the invited recipient.
        $recipient = $this->route('portal') === 'partner' ? PartnershipInvitation::recipient($this) : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class, ...($recipient ? [Rule::in([$recipient])] : [])],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return ['email.in' => 'Use the email address this invitation was sent to.'];
    }
}
