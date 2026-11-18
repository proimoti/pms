<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Общото за командите, които създават фирми и потребители. */
trait InteractsSafely
{
    /** Пита скрито за парола два пъти. Връща null при грешка (вече показана). */
    protected function askNewPassword(): ?string
    {
        $password = (string) $this->secret('Парола (поне 12 знака, не се вижда)');
        $confirmation = (string) $this->secret('Повторете паролата');

        $failed = $this->failsValidation(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::defaults(), 'confirmed']],
        );

        return $failed ? null : $password;
    }

    /** Празен отговор става null. */
    protected function askOptional(string $question): ?string
    {
        $answer = trim((string) $this->ask($question));

        return $answer === '' ? null : $answer;
    }

    /** Показва грешките (без въведените стойности) и връща true, ако има такива. */
    protected function failsValidation(array $data, array $rules, array $messages = []): bool
    {
        $validator = Validator::make($data, $rules, $messages);

        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return $validator->fails();
    }
}
