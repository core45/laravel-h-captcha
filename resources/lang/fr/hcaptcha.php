<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'La vérification du captcha a échoué. Veuillez réessayer.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Veuillez compléter le captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Le service captcha n\'est pas disponible. Veuillez réessayer dans un moment.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Le captcha a expiré. Veuillez le compléter à nouveau.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
