<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha-Verifikation fehlgeschlagen. Bitte versuchen Sie es erneut.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Bitte füllen Sie das Captcha aus.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Der Captcha-Dienst ist nicht verfügbar. Bitte versuchen Sie es in einem Moment erneut.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Das Captcha ist abgelaufen. Bitte füllen Sie es erneut aus.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Das Captcha wurde nicht abgeschlossen. Bitte versuchen Sie es erneut.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Überprüfung läuft, bitte warten…',

];
