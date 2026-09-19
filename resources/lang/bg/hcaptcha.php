<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Проверката на captcha не успя. Опитайте отново.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Моля, завършете captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Услугата captcha е недостъпна. Опитайте отново след момент.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha е с изтекло време. Моля, го завършете отново.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha не беше завършена. Моля, опитайте отново.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Проверка, моля изчакайте…',

];
