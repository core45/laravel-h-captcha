<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Provjera captche nije uspjela. Molimo pokušajte ponovno.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Molimo popunite captchu.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Usluga captche nije dostupna. Molimo pokušajte ponovno za trenutak.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha je istekla. Molimo popunite je ponovno.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
