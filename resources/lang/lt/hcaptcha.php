<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha patikrinimas nepavyko. Prašome bandyti dar kartą.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Prašome užpildyti captchą.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Captcha paslauga nėra prieinama. Prašome bandyti vėliau.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha galiojimas baigėsi. Prašome ją užpildyti dar kartą.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
