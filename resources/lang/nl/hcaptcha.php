<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha-verificatie mislukt. Probeer het opnieuw.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Vul alstublieft de captcha in.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'De captcha-service is niet beschikbaar. Probeer het alstublieft later opnieuw.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'De captcha is verlopen. Vul het alstublieft opnieuw in.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
