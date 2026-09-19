<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'A captcha-ellenőrzés meghiúsult. Kérjük, próbálja újra.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Kérjük, töltse ki a captchát.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'A captcha-szolgáltatás nem elérhető. Kérjük, próbálja újra később.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'A captcha lejárt. Kérjük, töltse ki újra.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'A captcha nem fejeződött be. Kérjük, próbálja újra.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Ellenőrzés folyamatban, kérjük, várjon…',

];
