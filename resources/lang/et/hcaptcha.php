<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha kinnitamine ebaõnnestus. Palun proovige uuesti.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Palun täitke captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Captcha teenus on saadamatu. Palun proovige hetke pärast uuesti.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha aegus. Palun täitke see uuesti.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
