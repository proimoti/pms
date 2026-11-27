<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Забравена парола (PROJECT.md, т. 2 „Вход“). */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_is_shown_in_bulgarian(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Забравена парола');
    }

    public function test_reset_link_is_sent_to_an_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_mail_is_in_bulgarian(): void
    {
        Notification::fake();
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'парола');
        });
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $knownStatus = session('status');

        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);
        $unknownStatus = session('status');

        $known->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertNotEmpty($knownStatus);
        $this->assertSame($knownStatus, $unknownStatus);
    }

    public function test_password_is_reset_with_a_valid_link(): void
    {
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'нова-измислена-парола',
            'password_confirmation' => 'нова-измислена-парола',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('нова-измислена-парола', $user->fresh()->password));
    }

    public function test_password_shorter_than_12_characters_is_rejected(): void
    {
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)
        $token = Password::broker()->createToken($user);
        $oldHash = $user->password;

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => '12345678901', // 11 знака
            'password_confirmation' => '12345678901',
        ])->assertSessionHasErrors('password');

        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_password_of_exactly_12_characters_is_accepted(): void
    {
        $user = User::factory()->tenant()->create();
        $this->onAddressOf($user->company); // адресът на тестовете е на фирмата (27.11.2026)
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => '123456789012', // 12 знака
            'password_confirmation' => '123456789012',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('123456789012', $user->fresh()->password));
    }
}
