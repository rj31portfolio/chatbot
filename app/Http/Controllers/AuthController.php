<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function form(string $mode = 'login')
    {
        return view('auth.form', compact('mode'));
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt(array_merge($data, ['status' => 'active']), $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return redirect()->intended($r->user()->is_super_admin ? '/admin' : '/dashboard');
    }

    public function register(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'terms' => 'accepted']);
        $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
        Auth::login($user);
        $r->session()->regenerate();

        return redirect()->route('business.create');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('status', 'If that account exists, a password reset link has been sent.');
    }

    public function resetForm(Request $r, string $token)
    {
        return view('auth.form', ['mode' => 'reset', 'token' => $token, 'email' => $r->query('email')]);
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $p) {
            $u->forceFill(['password' => $p, 'remember_token' => Str::random(60)])->save();
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('status', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
