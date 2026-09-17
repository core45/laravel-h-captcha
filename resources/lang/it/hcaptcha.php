<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Verifica captcha non riuscita. Riprova.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Completa il captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Il servizio captcha non è disponibile. Riprova tra un momento.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Il captcha è scaduto. Completalo di nuovo.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
