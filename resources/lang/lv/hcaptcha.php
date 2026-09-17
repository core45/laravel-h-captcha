<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha pārbaude neizdevās. Lūdzu, mēģiniet vēlreiz.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Lūdzu, pabeigiet captchu.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Captcha pakalpojums nav pieejams. Lūdzu, mēģiniet vēlreiz pēc brīža.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha beigu termiņš ir beidzies. Lūdzu, pabeigiet to vēlreiz.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

];
