<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'La verificación del captcha falló. Por favor, inténtelo de nuevo.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Por favor, completa el captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'El servicio de captcha no está disponible. Por favor, inténtelo de nuevo en un momento.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'El captcha expiró. Por favor, complétalo de nuevo.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
